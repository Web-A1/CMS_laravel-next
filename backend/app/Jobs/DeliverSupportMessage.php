<?php

namespace App\Jobs;

use App\Models\AdminSupportTicket;
use App\Models\SupportMessage;
use App\Services\Support\SupportOperations;
use App\Services\Support\SupportSmtp;
use App\Services\Support\SupportSmtpFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class DeliverSupportMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $messageId) {}

    public function handle(SupportSmtp $smtp): void
    {
        $message = DB::transaction(function (): ?SupportMessage {
            $message = SupportMessage::whereKey($this->messageId)->lockForUpdate()->firstOrFail();
            if ($message->delivery_status !== 'pending' || $message->next_attempt_at?->isFuture()) {
                return null;
            }
            $message->update(['delivery_status' => 'sending', 'claimed_at' => now(), 'attempts' => $message->attempts + 1]);

            return $message;
        });
        if (! $message) {
            return;
        }
        try {
            $smtp->send($message);
        } catch (SupportSmtpFailure $e) {
            $retry = $e->retryable && $message->attempts < config('support.delivery_attempts');
            $message->update(['delivery_status' => $e->unknown ? 'delivery_unknown' : ($retry ? 'pending' : 'failed'), 'error_code' => $e->safeCode, 'next_attempt_at' => $retry ? now()->addSeconds(60 * (2 ** $message->attempts)) : null]);

            return;
        } catch (\Throwable) {
            $message->update(['delivery_status' => 'delivery_unknown', 'error_code' => 'SMTP_OUTCOME_UNKNOWN', 'next_attempt_at' => null]);

            return;
        }
        DB::transaction(function () use ($message): void {
            $ticket = AdminSupportTicket::whereKey($message->ticket_id)->lockForUpdate()->firstOrFail();
            $message->refresh()->update(['delivery_status' => 'sent', 'sent_at' => now(), 'next_attempt_at' => null, 'error_code' => null]);
            if ($message->direction === 'outbound' && $ticket->revision === $message->ticket_revision) {
                $old = $ticket->status;
                $ticket->update(['status' => 'waiting', 'revision' => $ticket->revision + 1]);
                app(SupportOperations::class)->audit(null, 'support_sent', $ticket, 'sent:'.$message->id, ['from' => $old, 'to' => 'waiting', 'message_id' => $message->id]);
            }
        });
    }
}
