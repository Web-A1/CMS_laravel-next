<?php

namespace App\Services\Support;

use App\Models\SupportMailboxState;
use App\Models\SupportMailReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class SupportIntake
{
    public function classify(SupportMailReceipt $receipt): void
    {
        if (! Schema::hasTable('support_media_reservations')) {
            return;
        }
        DB::transaction(function () use ($receipt): void {
            $receipt = SupportMailReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($receipt->media_deletion_id || $receipt->outcome !== 'pending' || $receipt->intake_status === 'eligible' || ! $receipt->original_key) {
                return;
            }
            $fingerprint = $receipt->fingerprint ?: hash('sha256', Storage::disk($receipt->disk)->get($receipt->original_key));
            $known = SupportMailReceipt::where('fingerprint', $fingerprint)->where('id', '!=', $receipt->id)->whereNotNull('support_message_id')->first();
            if ($known) {
                $receipt->update(['outcome' => 'duplicate', 'fingerprint' => $fingerprint, 'message_id' => $known->message_id, 'support_message_id' => $known->support_message_id, 'ticket_id' => $known->ticket_id, 'media_deletion_id' => $known->media_deletion_id]);

                return;
            }
            $state = SupportMailboxState::where('mailbox', $receipt->mailbox)->where('folder', $receipt->folder)->first();
            if ($state?->activated_at && (int) $receipt->uidvalidity === (int) $state->baseline_uidvalidity) {
                $eligible = (int) $receipt->uid > (int) $state->baseline_uid;
                $receipt->update(['intake_status' => $eligible ? 'eligible' : 'unclassified', 'outcome' => $eligible ? 'pending' : 'historical', 'reason' => $eligible ? null : 'BEFORE_ACTIVATION_UID', 'fingerprint' => $fingerprint]);

                return;
            }
            $start = $state?->activation_window_started_at;
            $finish = $state?->activation_window_finished_at;
            // INTERNALDATE has seconds precision. Its entire second must lie outside the snapshot interval.
            $received = $receipt->received_at?->copy()->startOfSecond();
            if (! $state?->activated_at || ! $start || ! $finish || $finish->lt($start) || ! $received) {
                $receipt->update(['outcome' => 'recovery_manual', 'reason' => ! $received ? 'RECEIVED_TIME_UNKNOWN' : 'ACTIVATION_WINDOW_UNKNOWN', 'fingerprint' => $fingerprint]);
            } elseif ($received->copy()->addSecond()->lte($start)) {
                $receipt->update(['outcome' => 'historical', 'reason' => 'BEFORE_ACTIVATION', 'fingerprint' => $fingerprint]);
            } elseif ($received->gt($finish)) {
                $receipt->update(['intake_status' => 'eligible', 'reason' => null, 'fingerprint' => $fingerprint]);
            } else {
                $receipt->update(['outcome' => 'recovery_manual', 'reason' => 'ACTIVATION_WINDOW_AMBIGUOUS', 'fingerprint' => $fingerprint]);
            }
        });
    }
}
