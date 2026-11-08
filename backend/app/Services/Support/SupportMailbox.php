<?php

namespace App\Services\Support;

use App\Models\AdminSupportTicket;
use App\Models\SupportMailboxState;
use App\Models\SupportMailReceipt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SupportMailbox
{
    public function state(): SupportMailboxState
    {
        return SupportMailboxState::firstOrCreate(['mailbox' => config('support.address'), 'folder' => config('support.imap.folder')]);
    }

    public function activation(bool $confirm = false, ?\Closure $showPreview = null): array
    {
        $identity = ['mailbox' => config('support.address'), 'folder' => config('support.imap.folder')];
        $lock = Cache::lock('support:mailbox', 300);
        if (! $lock->get()) {
            return [...$identity, 'status' => 'busy'];
        }
        $imap = app(SupportImap::class);
        try {
            $state = SupportMailboxState::where($identity)->first();
            if ($state?->activated_at) {
                return [...$identity, 'status' => 'already_active', 'uidvalidity' => $state->baseline_uidvalidity, 'baseline_uid' => $state->baseline_uid];
            }
            if (! config('support.imap.enabled')) {
                return [...$identity, 'status' => 'disabled'];
            }
            if (! config('support.imap.host') || ! config('support.imap.username') || ! config('support.imap.password')) {
                return [...$identity, 'status' => 'not_configured'];
            }
            $windowStart = now();
            $metadata = $imap->connect();
            $windowFinish = now();
            if (! isset($metadata['uidvalidity'], $metadata['uidnext'], $metadata['exists'])
                || (int) $metadata['uidvalidity'] < 1 || (int) $metadata['uidnext'] < 1 || (int) $metadata['exists'] < 0) {
                return [...$identity, 'status' => 'invalid_metadata'];
            }
            $preview = [...$identity, 'status' => 'preview', 'uidvalidity' => (int) $metadata['uidvalidity'], 'baseline_uid' => (int) $metadata['uidnext'] - 1, 'existing_messages' => (int) $metadata['exists']];
            $showPreview?->__invoke($preview);
            if (! $confirm) {
                return $preview;
            }

            return DB::transaction(function () use ($identity, $preview, $windowStart, $windowFinish): array {
                $state = SupportMailboxState::where($identity)->lockForUpdate()->first();
                if ($state?->activated_at) {
                    return [...$identity, 'status' => 'already_active', 'uidvalidity' => $state->baseline_uidvalidity, 'baseline_uid' => $state->baseline_uid];
                }
                $state ??= new SupportMailboxState($identity);
                $state->fill(['activated_at' => now(), 'activation_window_started_at' => $windowStart, 'activation_window_finished_at' => $windowFinish, 'baseline_uid' => $preview['baseline_uid'], 'baseline_uidvalidity' => $preview['uidvalidity'], 'uidvalidity' => $preview['uidvalidity'], 'cursor' => $preview['baseline_uid'], 'status' => 'active', 'error_code' => null, 'last_attempt_at' => now(), 'last_success_at' => now()])->save();

                return [...$preview, 'status' => 'active'];
            });
        } catch (\Throwable) {
            return [...$identity, 'status' => 'connection_failed'];
        } finally {
            try {
                $imap->disconnect();
            } catch (\Throwable) {
            }
            $lock->release();
        }
    }

    public function run(bool $activate = false, bool $recover = false): string
    {
        if (! Schema::hasTable('support_media_reservations')) {
            return 'media_unavailable';
        }
        $media = app(SupportMedia::class);
        $media->outsideTransaction();
        $lock = Cache::lock('support:mailbox', 300);
        if (! $lock->get()) {
            return 'busy';
        }
        $imap = app(SupportImap::class);
        $state = $this->state();
        $deadline = microtime(true) + 90;
        try {
            if (! config('support.imap.enabled')) {
                return 'disabled';
            }
            if (! $activate && ! $state->activated_at) {
                return 'inactive';
            }
            if ($activate && $state->activated_at) {
                return 'already_active';
            }
            $state->update(['last_attempt_at' => now()]);
            $windowStart = now();
            $status = $imap->connect();
            $windowFinish = now();
            $epoch = (int) $status['uidvalidity'];
            $end = max(0, (int) $status['uidnext'] - 1);
            if ($activate) {
                abort_if($state->activated_at !== null, 409, 'Ящик уже активирован. Используйте recovery.');
                $state->update(['activated_at' => now(), 'activation_window_started_at' => $windowStart, 'activation_window_finished_at' => $windowFinish, 'baseline_uid' => $end, 'baseline_uidvalidity' => $epoch, 'uidvalidity' => $epoch, 'cursor' => $end, 'status' => 'active', 'error_code' => null, 'last_success_at' => now()]);

                return 'active';
            }
            $reset = $epoch !== (int) $state->uidvalidity;
            if ($reset && ! $recover) {
                $state->update(['status' => 'recovery_required', 'error_code' => 'UIDVALIDITY_CHANGED']);

                return 'recovery_required';
            }
            if ($reset && (int) $state->recovery_epoch !== $epoch) {
                $state->update(['recovery_epoch' => $epoch, 'recovery_cursor' => 0]);
            }
            $cursor = $reset ? (int) $state->recovery_cursor : (int) $state->cursor;
            $through = min($end, $cursor + config('support.imap.batch'));
            if ($through > $cursor) {
                $metadata = $imap->metadata($cursor + 1, $through);
                ksort($metadata, SORT_NUMERIC);
                foreach ($metadata as $uid => $meta) {
                    if (microtime(true) >= $deadline) {
                        $through = $uid - 1;
                        break;
                    }
                    $receipt = SupportMailReceipt::firstOrCreate(['mailbox' => $state->mailbox, 'folder' => $state->folder, 'uidvalidity' => $epoch, 'uid' => $uid], ['intake_status' => $reset ? 'unclassified' : 'eligible']);
                    $receipt->refresh();
                    if ($receipt->outcome !== 'pending') {
                        continue;
                    }
                    if (! $receipt->original_key) {
                        try {
                            $headers = $imap->headers($uid);
                            $owner = app(SupportInbound::class)->ticketForHeaders($headers);
                        } catch (\RuntimeException $error) {
                            if (! in_array($error->getMessage(), ['HEADER_TOO_LARGE', 'HEADER_INVALID'], true)) {
                                throw $error;
                            }
                            $receipt->update(['outcome' => 'quarantine', 'reason' => $error->getMessage()]);

                            continue;
                        }
                        if (! $owner) {
                            $receipt->update(['outcome' => 'ignored', 'reason' => 'NOT_WEB_TICKET']);

                            continue;
                        }
                        $receipt->update(['ticket_id' => $owner->id]);
                    }
                    if ($meta['bytes'] <= 0 || $meta['bytes'] > min(config('support.max_mail_bytes'), SupportMediaStorage::MAX_BYTES)) {
                        $receipt->update(['outcome' => 'quarantine', 'reason' => $meta['bytes'] <= 0 ? 'MAIL_SIZE_UNKNOWN' : 'MAIL_TOO_LARGE']);

                        continue;
                    }
                    if (! $receipt->original_key) {
                        try {
                            $raw = $imap->raw($uid);
                            $fingerprint = hash('sha256', $raw);
                            $known = SupportMailReceipt::where('fingerprint', $fingerprint)->whereNotNull('media_deletion_id')->first();
                            if ($known) {
                                $receipt->update(['outcome' => 'duplicate', 'fingerprint' => $fingerprint, 'message_id' => $known->message_id, 'support_message_id' => $known->support_message_id, 'ticket_id' => $known->ticket_id, 'media_deletion_id' => $known->media_deletion_id]);

                                continue;
                            }
                            $reservation = $media->reserve('receipt:'.$receipt->id, $fingerprint, 'mail', $receipt->ticket_id, receipt: $receipt->id);
                            $path = $media->work($reservation, function ($directory) use ($raw): string {
                                $path = $directory.'/original.eml';
                                if (strlen($raw) > SupportMediaStorage::MAX_BYTES || file_put_contents($path, $raw) !== strlen($raw)) {
                                    throw new \RuntimeException('MEDIA_MAIL_STAGING_FAILED');
                                }

                                return $path;
                            });
                            try {
                                $stored = $reservation->fresh()->files['original.eml'] ?? null;
                                $key = $stored && $stored['state'] === 'acknowledged'
                                    ? $stored['key'] : $media->write($reservation, 'original.eml', $path);
                            } finally {
                                $media->cleanWork($reservation);
                            }
                            try {
                                $received = isset($meta['received_at']) ? Carbon::parse($meta['received_at']) : null;
                            } catch (\Throwable) {
                                $received = null;
                            }
                            DB::transaction(function () use ($receipt, $key, $received, $fingerprint, $media, $reservation): void {
                                AdminSupportTicket::whereKey($receipt->ticket_id)->lockForUpdate()->firstOrFail();
                                $receipt = SupportMailReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
                                $receipt->update(['disk' => config('support.disk'), 'original_key' => $key, 'received_at' => $received, 'fingerprint' => $fingerprint, 'media_reservation_id' => $reservation->id]);
                                $media->associate($reservation, $receipt->ticket_id);
                            });
                        } catch (\Throwable) {
                            $receipt->update(['outcome' => 'quarantine', 'reason' => 'ORIGINAL_UNAVAILABLE']);

                            continue;
                        }
                    }
                    app(SupportIntake::class)->classify($receipt);
                    app(SupportInbound::class)->accept($receipt);
                }
            }
            if ($reset && $through < $end) {
                $state->update(['status' => 'recovery_required', 'error_code' => 'RECOVERY_IN_PROGRESS', 'recovery_cursor' => $through]);
            } else {
                $state->update(['uidvalidity' => $epoch, 'cursor' => $through, 'status' => 'active', 'error_code' => null, 'last_success_at' => now()]);
            }

            return $state->status;
        } catch (\Throwable $e) {
            $state->update(['status' => $e->getMessage() === 'NOT_CONFIGURED' ? 'not_configured' : 'connection_failed', 'error_code' => $e->getMessage() === 'NOT_CONFIGURED' ? 'NOT_CONFIGURED' : 'IMAP_CONNECTION_FAILED']);

            return $state->status;
        } finally {
            try {
                $imap->disconnect();
            } catch (\Throwable) {
            }
            $lock->release();
        }
    }
}
