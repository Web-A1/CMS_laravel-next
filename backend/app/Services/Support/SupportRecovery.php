<?php

namespace App\Services\Support;

use App\Jobs\DeleteSupportMedia;
use App\Jobs\DeliverSupportMessage;
use App\Jobs\ProcessSupportImage;
use App\Models\SupportAttachment;
use App\Models\SupportMailReceipt;
use App\Models\SupportMediaDeletion;
use App\Models\SupportMessage;
use Illuminate\Support\Facades\Schema;

final class SupportRecovery
{
    public function run(): void
    {
        SupportMessage::where('delivery_status', 'sending')->where('claimed_at', '<', now()->subSeconds(config('support.lease_seconds')))->update(['delivery_status' => 'delivery_unknown', 'error_code' => 'SEND_LEASE_EXPIRED', 'next_attempt_at' => null]);
        SupportMessage::where('delivery_status', 'pending')->where('next_attempt_at', '<=', now())->orderBy('id')->limit(100)->get()->each(fn ($message) => DeliverSupportMessage::dispatch($message->id)->onQueue(config('support.queue')));
        if (! Schema::hasTable('support_media_reservations')) {
            return;
        }
        SupportMediaDeletion::where('status', 'pending')->orderBy('id')->limit(10)->get()->each(fn ($deletion) => DeleteSupportMedia::dispatch($deletion->id)->onQueue(config('support.queue')));
        app(SupportMedia::class)->recoverOrphans();
        SupportAttachment::where('status', 'pending')->orderBy('id')->limit(25)->get()->each(fn ($attachment) => ProcessSupportImage::dispatch($attachment->id)->onQueue(config('support.queue')));
        SupportMailReceipt::where('outcome', 'pending')->where('intake_status', 'unclassified')->whereNotNull('original_key')->orderBy('id')->limit(25)->get()->each(fn ($receipt) => app(SupportIntake::class)->classify($receipt));
        SupportMailReceipt::where('outcome', 'pending')->where('intake_status', 'eligible')->whereNotNull('original_key')->orderBy('id')->limit(25)->get()->each(fn ($receipt) => app(SupportInbound::class)->accept($receipt));
        SupportMessage::where('direction', 'inbound')->where('notify_failures', true)->orderBy('id')->chunkById(100, function ($messages): void {
            foreach ($messages as $message) {
                app(SupportImages::class)->notify($message);
            }
        });
    }

    public function health(): array
    {
        $state = app(SupportMailbox::class)->state();

        return [
            'smtp' => config('mail.mailers.support.host') ? 'configured' : 'not_configured',
            'imap' => ! config('support.imap.host') || ! config('support.imap.username') || ! config('support.imap.password') ? 'not_configured' : (! config('support.imap.enabled') ? 'disabled' : $state->status),
            'last_success' => $state->last_success_at?->format('d.m.Y H:i:s'),
            'error_code' => $state->error_code,
            'backlog' => SupportMessage::whereIn('delivery_status', ['pending', 'sending'])->count(),
            'delivery_problems' => SupportMessage::whereIn('delivery_status', ['failed', 'delivery_unknown'])->count(),
            'quarantine' => SupportMailReceipt::whereIn('outcome', ['quarantine', 'recovery_manual'])->orWhere(fn ($query) => $query->where('outcome', 'pending')->where('intake_status', 'unclassified'))->count(),
        ];
    }
}
