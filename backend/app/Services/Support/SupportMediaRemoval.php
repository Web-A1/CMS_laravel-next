<?php

namespace App\Services\Support;

use App\Jobs\DeleteSupportMedia;
use App\Models\AdminOperationEvent;
use App\Models\AdminSupportTicket;
use App\Models\SupportAttachment;
use App\Models\SupportMailReceipt;
use App\Models\SupportMediaDeletion;
use App\Models\SupportMediaReservation;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class SupportMediaRemoval
{
    public function close(User $actor, int $id, array $data, string $key): AdminSupportTicket
    {
        abort_unless(Schema::hasTable('support_media_deletions'), 503, 'Очистка медиа ещё не настроена.');
        $operations = app(SupportOperations::class);
        $actor = $operations->authorize($actor, true);
        validator(['key' => $key], ['key' => ['required', 'string', 'min:8', 'max:128']])->validate();

        try {
            return DB::transaction(function () use ($actor, $id, $data, $key, $operations): AdminSupportTicket {
                $query = AdminSupportTicket::whereKey($id);
                $ticket = $query->lock(DB::getDriverName() === 'pgsql' ? 'for update nowait' : true)->firstOrFail();
                $hash = hash('sha256', json_encode([$id, $data]));
                $event = AdminOperationEvent::where('idempotency_key', $key)->first();
                if ($event) {
                    abort_unless($event->actor_id === $actor->id && ($event->payload['hash'] ?? '') === $hash && $event->operation === 'support_close', 409);

                    return $ticket;
                }
                abort_unless($ticket->revision === (int) $data['revision'], 409, 'История обновилась. Обновите карточку и повторите действие.');
                $reservations = SupportMediaReservation::where('ticket_id', $id)->whereNull('deletion_id')->lockForUpdate()->get();
                abort_if($reservations->contains(fn ($reservation) => ! $reservation->associated_at
                    && in_array($reservation->kind, ['mail', 'inbound'], true)
                    && SupportMailReceipt::whereKey($reservation->receipt_id)->where('outcome', 'pending')->exists()), 409, 'Входящее сообщение ещё обрабатывается. Повторите закрытие позже.');
                abort_if(SupportMailReceipt::where('ticket_id', $id)->where('outcome', 'pending')->whereNull('support_message_id')->exists(), 409, 'Входящее сообщение ещё обрабатывается.');
                $messages = $ticket->messages()->whereNull('media_deletion_id')->get();
                $attachments = SupportAttachment::whereIn('message_id', $messages->modelKeys())->get();
                $receipts = SupportMailReceipt::whereNull('media_deletion_id')->where(fn ($query) => $query->whereIn('support_message_id', $messages->modelKeys())->orWhere('ticket_id', $id))->get();
                $snapshot = [
                    'messages' => $messages->modelKeys(), 'attachments' => $attachments->modelKeys(),
                    'receipts' => $receipts->modelKeys(), 'reservations' => $reservations->modelKeys(),
                    'receipt_ceiling' => (int) SupportMailReceipt::max('id'),
                    'anchors' => $messages->map(fn ($message) => ['id' => $message->id, 'message_id' => $message->message_id, 'sender' => $message->sender])->all(),
                ];
                $deletion = SupportMediaDeletion::create([
                    'ticket_id' => $id, 'closing_revision' => $ticket->revision + 1, 'actor_id' => $actor->id,
                    'idempotency_key' => $key, 'snapshot' => $snapshot, 'manifest' => [],
                    'coverage' => 'legacy_unknown', 'status' => 'pending',
                ]);
                SupportMessage::whereIn('id', $snapshot['messages'])->update(['media_deletion_id' => $deletion->id, 'notify_failures' => false]);
                SupportAttachment::whereIn('id', $snapshot['attachments'])->update(['media_deletion_id' => $deletion->id, 'status' => 'deleting']);
                SupportMailReceipt::whereIn('id', $snapshot['receipts'])->update(['media_deletion_id' => $deletion->id]);
                SupportMediaReservation::whereIn('id', $snapshot['reservations'])->update(['deletion_id' => $deletion->id]);
                $old = $ticket->status;
                $ticket->status = 'closed';
                $ticket->revision++;
                if (isset($data['contact_email'])) {
                    $ticket->contact_email = mb_strtolower(trim($data['contact_email']));
                }
                $ticket->save();
                $operations->audit($actor, 'support_close', $ticket, $key, ['hash' => $hash, 'from' => $old, 'to' => 'closed', 'deletion_id' => $deletion->id]);
                DB::afterCommit(function () use ($deletion): void {
                    try {
                        DeleteSupportMedia::dispatch($deletion->id)->onQueue(config('support.queue'));
                    } catch (\Throwable) {
                        Log::warning('Support media cleanup dispatch failed', ['deletion_id' => $deletion->id]);
                    }
                });

                return $ticket;
            });
        } catch (QueryException $exception) {
            abort_if(($exception->errorInfo[0] ?? '') === '55P03', 409, 'Вложения ещё обрабатываются. Повторите закрытие позже.');
            throw $exception;
        }
    }

    public function run(int $id): void
    {
        if (! Schema::hasTable('support_media_deletions')) {
            return;
        }
        $deletion = SupportMediaDeletion::findOrFail($id);
        $locked = false;
        $lockSession = null;
        if (DB::getDriverName() === 'pgsql') {
            $locked = (bool) DB::selectOne('SELECT pg_try_advisory_lock(192763, 1) AS locked')->locked;
            if (! $locked) {
                return;
            }
            $lockSession = DB::selectOne('SELECT pg_backend_pid() AS id')->id;
        }
        $assertLock = static function () use ($lockSession): void {
            if ($lockSession !== null && DB::selectOne('SELECT pg_backend_pid() AS id')->id !== $lockSession) {
                throw new \RuntimeException('MEDIA_INVENTORY_LOCK_LOST');
            }
        };
        try {
            $deletion->refresh();
            if ($deletion->status === 'completed') {
                return;
            }
            if (! in_array($deletion->coverage, ['proven_v1', 'proven_inventory'], true)) {
                $plan = app(SupportMediaInventory::class)->plan($deletion);
                $assertLock();
                DB::transaction(function () use ($deletion, $plan): void {
                    AdminSupportTicket::whereKey($deletion->ticket_id)->lockForUpdate()->firstOrFail();
                    foreach ($plan['manifest']['admitted_receipts'] ?? [] as $id) {
                        $receipt = SupportMailReceipt::whereKey($id)->lockForUpdate()->firstOrFail();
                        if (($receipt->media_deletion_id && $receipt->media_deletion_id !== $deletion->id)
                            || ($receipt->support_message_id && ! in_array($receipt->support_message_id, $deletion->snapshot['messages'], true))) {
                            throw new \RuntimeException('MEDIA_LEGACY_RECEIPT_CHANGED');
                        }
                        $receipt->update(['media_deletion_id' => $deletion->id, 'ticket_id' => $deletion->ticket_id]);
                    }
                    $deletion->update(['coverage' => $plan['coverage'], 'manifest' => $plan['manifest'], 'inventory_evidence' => $plan['evidence'], 'error_code' => null]);
                });
            }
            $storage = app(SupportMediaStorage::class);
            $media = app(SupportMedia::class);
            foreach ($deletion->manifest['keys'] as $target) {
                // Check shared references again before each destructive call.
                $checked = ['keys' => [], 'admitted_receipts' => $deletion->manifest['admitted_receipts'] ?? []];
                app(SupportMediaInventory::class)->addKey($checked, $target['identity'], $target['key'], $deletion->snapshot);
                $assertLock();
                $storage->deleteKey($target['identity'], $target['key']);
            }
            foreach ($deletion->manifest['borrowed'] as $borrowed) {
                $assertLock();
                $media->removeBorrowed($borrowed);
            }
            foreach ($deletion->manifest['directories'] as $directory) {
                $assertLock();
                $media->removeWorkDirectory($directory);
            }
            foreach ($deletion->manifest['keys'] as $target) {
                if (array_filter($storage->inventory($target['identity'], $target['key'])['entries'], fn ($entry) => $entry['key'] === $target['key']) !== []) {
                    throw new \RuntimeException('MEDIA_DELETE_UNCONFIRMED');
                }
            }
            $assertLock();
            DB::transaction(function () use ($deletion): void {
                $row = SupportMediaDeletion::whereKey($deletion->id)->lockForUpdate()->firstOrFail();
                $row->update(['status' => 'completed', 'completed_at' => now(), 'error_code' => null]);
                SupportAttachment::where('media_deletion_id', $row->id)->update(['status' => 'deleted']);
                $ticket = AdminSupportTicket::findOrFail($row->ticket_id);
                app(SupportOperations::class)->audit(null, 'support_media_deleted', $ticket, 'media-deleted:'.$row->id, ['deletion_id' => $row->id]);
            });
        } catch (\Throwable $error) {
            $code = $error instanceof \RuntimeException && preg_match('/\AMEDIA_[A-Z_]+\z/D', $error->getMessage()) ? $error->getMessage() : 'MEDIA_STORAGE_FAILED';
            $blocked = str_starts_with($code, 'MEDIA_LEGACY_') || str_starts_with($code, 'MEDIA_ENVIRONMENT_')
                || in_array($code, ['MEDIA_IO_UNCONFIRMED', 'MEDIA_SHARED_KEY', 'MEDIA_STORAGE_IDENTITY_CHANGED', 'MEDIA_UPLOAD_CLEANUP_UNCONFIRMED'], true);
            $deletion->update(['status' => $blocked ? 'blocked' : 'failed', 'error_code' => $code]);
            Log::warning('Support media cleanup incomplete', ['deletion_id' => $id, 'code' => $code]);
        } finally {
            if ($locked) {
                DB::select('SELECT pg_advisory_unlock(192763, 1)');
            }
        }
    }
}
