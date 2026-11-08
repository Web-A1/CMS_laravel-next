<?php

namespace App\Services\Support;

use App\Jobs\DeliverSupportMessage;
use App\Models\AdminOperationEvent;
use App\Models\AdminSupportTicket;
use App\Models\SupportMailReceipt;
use App\Models\SupportMessage;
use App\Models\User;
use App\Support\ImpersonationContext;
use App\Support\Rbac;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class SupportOperations
{
    public const STATUSES = ['new' => 'Новое', 'in_progress' => 'В работе', 'waiting' => 'Ждём ответа', 'closed' => 'Закрыто'];

    public function authorize(User $actor, bool $write = false): User
    {
        $actor = $actor->fresh();
        abort_unless($actor && ! $actor->blocksAuthenticationForMigration() && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, $write ? 'support.write' : 'support.read'), 403);
        abort_if($write && ImpersonationContext::isActive(request()), 403);

        return $actor;
    }

    public function submit(string $email, string $body, string $key, array $files = [], ?UploadedFile $voice = null): array
    {
        $media = app(SupportMedia::class);
        $media->outsideTransaction();
        $email = mb_strtolower(trim($email));
        $voiceService = app(SupportVoice::class);
        $audio = $voice ? $voiceService->inspect($voice) : null;
        $fingerprint = [$email, $body, array_map(fn ($file) => [$file->getClientOriginalName(), $file->isValid() ? hash_file('sha256', $file->getRealPath()) : 'invalid'], $files)];
        if ($audio) {
            $fingerprint[] = [$audio['hash'], $audio['mime']];
        }
        $hash = hash('sha256', json_encode($fingerprint));
        $existing = AdminSupportTicket::where('submission_key', $key)->first();
        if ($existing) {
            abort_unless(hash_equals($existing->submission_hash, $hash), 409, 'Этот ключ уже использован для другого обращения.');

            return $existing->submission_result;
        }
        try {
            $reservation = $media->reserve('web:'.$key, $hash, 'web');
        } catch (HttpExceptionInterface $error) {
            throw $error;
        } catch (\RuntimeException) {
            abort(503, 'Не удалось подготовить вложения. Повторите отправку позже.');
        }
        $voiceKey = null;
        try {
            try {
                $prepared = app(SupportImages::class)->prepare($reservation, $files);
                if ($voice) {
                    $stored = $media->result($reservation, 'voice-result');
                    if ($stored) {
                        $voiceKey = $stored['key'];
                        $media->borrow($reservation, 'retry-voice', $voice);
                    } else {
                        $path = $media->stage($reservation, 'voice-upload', $voice);
                        $saved = $reservation->fresh()->files['voice'] ?? null;
                        $voiceKey = $saved && $saved['state'] === 'acknowledged'
                            ? $saved['key'] : $media->write($reservation, 'voice', $path);
                        $media->result($reservation, 'voice-result', ['key' => $voiceKey]);
                    }
                }
            } finally {
                $media->cleanWork($reservation);
            }
        } catch (HttpExceptionInterface $error) {
            throw $error;
        } catch (\RuntimeException) {
            abort(503, 'Не удалось сохранить вложения. Повторите отправку позже.');
        }
        try {
            return DB::transaction(function () use ($email, $body, $key, $hash, $audio, $voiceKey, $prepared, $media, $reservation): array {
                $existing = AdminSupportTicket::where('submission_key', $key)->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->submission_hash, $hash), 409, 'Этот ключ уже использован для другого обращения.');

                    return $existing->submission_result;
                }
                $ticket = $this->createTicket($email, $body, 'web', ['submission_key' => $key, 'submission_hash' => $hash, 'media_protocol' => 1, 'birth_reservation_id' => $reservation->id]);
                if ($audio && trim($body) === '') {
                    $ticket->update(['subject' => 'Голосовое обращение']);
                }
                $message = $this->inbound($ticket, $body, $email, notifyFailures: true);
                if ($audio) {
                    $message->attachments()->create(['disk' => config('support.disk'), 'preview_key' => $voiceKey, 'mime' => $audio['mime'], 'bytes' => $audio['bytes'], 'status' => 'ready', 'media_reservation_id' => $reservation->id]);
                }
                app(SupportImages::class)->attach($message, $reservation, $prepared);
                $warnings = $prepared['warnings'];
                // Early web warnings are already shown by the form. Only late failures need mail.
                $message->update(['warnings' => $warnings]);
                $result = ['data' => ['number' => $ticket->public_number, 'warnings' => $warnings]];
                $ticket->update(['submission_result' => $result]);
                $media->associate($reservation, $ticket->id, $message->id);

                return $result;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            $existing = AdminSupportTicket::where('submission_key', $key)->first();
            if (! $existing) {
                throw $e;
            }
            abort_unless(hash_equals($existing->submission_hash, $hash), 409);

            return $existing->submission_result;
        }
    }

    public function createTicket(?string $email, string $body, string $source, array $extra = []): AdminSupportTicket
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $number = 'SUP-';
            for ($index = 0; $index < 7; $index++) {
                $number .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            try {
                // A savepoint keeps the outer submission transaction usable after a collision.
                return DB::transaction(fn () => AdminSupportTicket::create([
                    'contact_email' => $email,
                    'subject' => Str::limit(preg_replace('/\s+/u', ' ', $body), 175, ''),
                    'description' => $body, 'source' => $source, 'status' => 'new', 'priority' => 'normal',
                    'revision' => 1, 'last_message_at' => now(), ...$extra, 'public_number' => $number,
                ]));
            } catch (UniqueConstraintViolationException $exception) {
                $detail = $exception->errorInfo[2] ?? '';
                $numberCollision = $detail === 'UNIQUE constraint failed: admin_support_tickets.public_number'
                    || preg_match('/\A(?:ERROR:\s*)?duplicate key value violates unique constraint "admin_support_tickets_public_number_unique"(?:\r?\n|\z)/', $detail) === 1;
                if ($attempt === 4 || ! $numberCollision) {
                    throw $exception;
                }
            }
        }
    }

    public function inbound(AdminSupportTicket $ticket, string $body, string $sender, array $headers = [], ?string $messageId = null, bool $notifyFailures = true): SupportMessage
    {
        $ticket = AdminSupportTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
        $oldStatus = $ticket->status;
        $ticket->revision++;
        $ticket->last_message_at = now();
        if (in_array($ticket->status, ['waiting', 'closed'], true)) {
            $ticket->status = 'new';
        }
        $ticket->save();
        if ($oldStatus !== $ticket->status) {
            $this->audit(null, 'inbound_reopen', $ticket, 'inbound:'.Str::uuid(), ['from' => $oldStatus, 'to' => $ticket->status]);
        }

        return $ticket->messages()->create(['direction' => 'inbound', 'body' => $body, 'sender' => $sender, 'headers' => $headers, 'message_id' => $messageId, 'notify_failures' => $notifyFailures]);
    }

    public function change(User $actor, int $id, array $input, string $key): AdminSupportTicket
    {
        $data = validator($input, [
            'revision' => ['required', 'integer', 'min:1'], 'status' => ['sometimes', 'in:'.implode(',', array_keys(self::STATUSES))],
            'contact_email' => ['sometimes', 'email:rfc', 'max:254'], 'description' => ['prohibited'], 'subject' => ['prohibited'],
            'user_id' => ['prohibited'], 'assignee_id' => ['prohibited'], 'metadata' => ['prohibited'],
        ])->validate();

        if (($data['status'] ?? null) === 'closed') {
            return app(SupportMediaRemoval::class)->close($actor, $id, $data, $key);
        }

        return DB::transaction(function () use ($actor, $id, $data, $key): AdminSupportTicket {
            $actor = $this->authorize($actor, true);
            $this->key($key);
            $ticket = AdminSupportTicket::whereKey($id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$id, $data]));
            $event = AdminOperationEvent::where('idempotency_key', $key)->first();
            if ($event) {
                abort_unless($event->actor_id === $actor->id && ($event->payload['hash'] ?? '') === $hash, 409);

                return $ticket;
            }
            abort_unless($ticket->revision === (int) $data['revision'], 409, 'История обновилась. Обновите карточку и повторите действие.');
            $old = $ticket->status;
            unset($data['revision']);
            if (isset($data['contact_email'])) {
                $data['contact_email'] = mb_strtolower(trim($data['contact_email']));
            }
            $ticket->fill($data);
            if (! $ticket->isDirty()) {
                return $ticket;
            }
            $ticket->revision++;
            $ticket->save();
            $this->audit($actor, 'support_update', $ticket, $key, ['hash' => $hash, 'from' => $old, 'to' => $ticket->status]);

            return $ticket;
        }, 3);
    }

    public function parameters(User $actor, int $id, array $patch, int $revision, string $key): AdminSupportTicket
    {
        $this->authorize($actor, true);
        if (array_diff(array_keys($patch), ['status'])) {
            throw ValidationException::withMessages(['request' => 'Запрос содержит недопустимые поля.']);
        }
        $data = validator($patch, ['status' => ['required', 'in:new,in_progress,waiting']])->validate();

        return $this->change($actor, $id, $data + ['revision' => $revision], $key);
    }

    public function close(User $actor, int $id, int $revision, string $key): AdminSupportTicket
    {
        return $this->change($actor, $id, ['revision' => $revision, 'status' => 'closed'], $key);
    }

    public function reply(User $actor, int $id, string $body, int $revision, string $key): SupportMessage
    {
        validator(['body' => $body], ['body' => ['required', 'string', 'max:20000']])->validate();

        return DB::transaction(function () use ($actor, $id, $body, $revision, $key): SupportMessage {
            $actor = $this->authorize($actor, true);
            $this->key($key);
            $ticket = AdminSupportTicket::whereKey($id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', json_encode([$id, $body, $revision, $actor->id]));
            $existing = SupportMessage::where('logical_key', 'reply:'.$key)->first();
            if ($existing) {
                abort_unless($existing->payload_hash === $hash, 409);

                return $existing;
            }
            abort_unless($ticket->contact_email, 422, 'Сначала укажите контактный email.');
            abort_unless($ticket->revision === $revision, 409, 'История обновилась. Текст ответа сохранён; обновите карточку.');
            $ticket->revision++;
            $ticket->last_message_at = now();
            $ticket->save();
            $message = $this->outgoing($ticket, $body, 'outbound', 'reply:'.$key, ['author_id' => $actor->id, 'payload_hash' => $hash, 'ticket_revision' => $ticket->revision]);
            $this->audit($actor, 'support_reply', $ticket, $key, ['message_id' => $message->id]);

            return $message;
        }, 3);
    }

    public function outgoing(AdminSupportTicket $ticket, string $body, string $direction, string $key, array $extra = []): SupportMessage
    {
        $previous = $ticket->messages()->whereNotNull('message_id')->orderByDesc('id')->first();
        $message = $ticket->messages()->firstOrCreate(['logical_key' => $key], [
            'direction' => $direction, 'body' => $body, 'sender' => config('support.address'),
            'message_id' => Str::uuid().'@'.Str::after(config('support.address'), '@'),
            'headers' => ['in_reply_to' => $previous?->message_id, 'references' => $previous?->message_id],
            'delivery_status' => 'pending', 'next_attempt_at' => now(), 'notify_failures' => false, ...$extra,
        ]);
        DB::afterCommit(function () use ($message): void {
            try {
                DeliverSupportMessage::dispatch($message->id)->onQueue(config('support.queue'));
            } catch (\Throwable) {
                // Durable pending row is recovered by support:recover-work.
            }
        });

        return $message;
    }

    public function retry(User $actor, int $id, string $key): void
    {
        DB::transaction(function () use ($actor, $id, $key): void {
            $actor = $this->authorize($actor, true);
            $this->key($key);
            $message = SupportMessage::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($prior = AdminOperationEvent::where('idempotency_key', $key)->first()) {
                abort_unless($prior->operation === 'support_retry' && (int) $prior->actor_id === $actor->id && (int) ($prior->payload['message_id'] ?? 0) === $id, 409);

                return;
            }
            abort_unless(in_array($message->delivery_status, ['failed', 'delivery_unknown'], true), 409);
            $message->update(['delivery_status' => 'pending', 'next_attempt_at' => now(), 'error_code' => null, 'attempts' => 0]);
            $this->audit($actor, 'support_retry', $message->ticket, $key, ['message_id' => $id]);
            DB::afterCommit(function () use ($message): void {
                try {
                    DeliverSupportMessage::dispatch($message->id)->onQueue(config('support.queue'));
                } catch (\Throwable) {
                }
            });
        });
    }

    public function resolveReceipt(User $actor, int $id, string $decision, string $key): void
    {
        DB::transaction(function () use ($actor, $id, $decision, $key): void {
            $this->authorize($actor, true);
            $this->key($key);
            $prior = AdminOperationEvent::where('idempotency_key', $key)->first();
            if ($prior) {
                abort_unless($prior->resource === 'SupportMailReceipt' && (int) $prior->resource_id === $id && ($prior->payload['decision'] ?? '') === $decision && (int) $prior->actor_id === $actor->id, 409);

                return;
            }
            abort_unless(in_array($decision, ['accept', 'historical'], true), 422);
            $receipt = SupportMailReceipt::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($receipt->outcome === 'recovery_manual', 409);
            abort_unless($receipt->original_key, 422);
            $receipt->update(['outcome' => $decision === 'accept' ? 'pending' : 'historical', 'intake_status' => $decision === 'accept' ? 'eligible' : 'unclassified', 'reason' => 'OPERATOR_DECISION']);
            AdminOperationEvent::create(['actor_id' => $actor->id, 'operation' => 'support_mail_recovery', 'resource' => 'SupportMailReceipt', 'resource_id' => $id, 'idempotency_key' => $key, 'payload' => ['decision' => $decision]]);
            if ($decision === 'accept') {
                DB::afterCommit(fn () => app(SupportInbound::class)->accept($receipt));
            }
        });
    }

    public function audit(?User $actor, string $operation, AdminSupportTicket $ticket, string $key, array $payload): void
    {
        AdminOperationEvent::create(['actor_id' => $actor?->id, 'operation' => $operation, 'resource' => 'AdminSupportTicket', 'resource_id' => $ticket->id, 'idempotency_key' => $key, 'payload' => $payload]);
    }

    private function key(string $key): void
    {
        abort_unless(strlen(trim($key)) > 0 && strlen($key) <= 128, 422, 'Требуется Idempotency-Key.');
    }
}
