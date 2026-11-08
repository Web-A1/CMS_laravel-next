<?php

namespace App\Services\Support;

use App\Models\AdminSupportTicket;
use App\Models\SupportMailReceipt;
use App\Models\SupportMediaReservation;
use App\Models\SupportMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message;

final class SupportInbound
{
    public function belongsToWebTicket(string $rawHeaders): bool
    {
        return $this->ticketForHeaders($rawHeaders) !== null;
    }

    public function ticketForHeaders(string $rawHeaders): ?AdminSupportTicket
    {
        try {
            $header = Message::fromString($rawHeaders."\r\n", Config::make(['options' => ['rfc822' => false, 'debug' => false]]))->getHeader();
        } catch (\Throwable) {
            throw new \RuntimeException('HEADER_INVALID');
        }
        $sender = mb_strtolower(trim((string) ($header->get('from')->first()?->mail ?? '')));
        $type = mb_strtolower((string) $header->get('content_type'));
        $bounce = str_contains($type, 'delivery-status') || str_contains($type, 'multipart/report') || str_contains($sender, 'mailer-daemon');
        if ($bounce) {
            $reply = trim((string) $header->get('in_reply_to'), '<> ');

            return $reply === '' ? null : SupportMessage::where('message_id', $reply)
                ->whereIn('direction', ['outbound', 'notice'])
                ->whereHas('ticket', fn ($query) => $query->where('source', 'web'))
                ->first()?->ticket;
        }
        $tickets = $this->matchingTickets($header);

        return $tickets->count() === 1
            && $tickets->first()->source === 'web'
            && $tickets->first()->contact_email === $sender ? $tickets->first() : null;
    }

    public function accept(SupportMailReceipt $receipt): void
    {
        $media = app(SupportMedia::class);
        $media->outsideTransaction();
        $receipt->refresh();
        if ($receipt->media_deletion_id || $receipt->outcome !== 'pending' || $receipt->intake_status !== 'eligible' || ! $receipt->original_key) {
            return;
        }
        try {
            $storage = app(SupportMediaStorage::class);
            $raw = $storage->read($storage->identity($receipt->disk), $receipt->original_key);
            $mail = Message::fromString($raw, Config::make(['options' => ['rfc822' => false, 'debug' => false]]));
            $header = $mail->getHeader();
            $id = trim((string) $header->get('message_id'), '<> ');
            $fingerprint = hash('sha256', $raw);
            $identity = hash('sha256', $id.'|'.$fingerprint);
            $known = SupportMailReceipt::where('id', '!=', $receipt->id)
                ->where(fn ($query) => $query->where('identity_hash', $identity)->orWhere(fn ($query) => $query->where('fingerprint', $fingerprint)->whereNotNull('support_message_id')))->first();
            if ($known) {
                $receipt->update(['outcome' => 'duplicate', 'fingerprint' => $fingerprint, 'message_id' => $id ?: null, 'support_message_id' => $known->support_message_id, 'ticket_id' => $known->ticket_id, 'media_deletion_id' => $known->media_deletion_id]);

                return;
            }
            if ($id !== '' && SupportMailReceipt::where('message_id', $id)->where('fingerprint', '!=', $fingerprint)->exists()) {
                $receipt->update(['outcome' => 'quarantine', 'reason' => 'MESSAGE_ID_COLLISION', 'fingerprint' => $fingerprint, 'message_id' => $id]);

                return;
            }
            $sender = mb_strtolower(trim((string) ($header->get('from')->first()?->mail ?? '')));
            $auto = mb_strtolower((string) $header->get('auto_submitted'));
            $precedence = mb_strtolower((string) $header->get('precedence'));
            $type = mb_strtolower((string) $header->get('content_type'));
            $reply = trim((string) $header->get('in_reply_to'), '<> ');
            $references = (string) $header->get('references');
            $ownId = $id !== '' && SupportMessage::where('message_id', $id)->whereIn('direction', ['outbound', 'notice'])->exists();
            $bounce = str_contains($type, 'delivery-status') || str_contains($type, 'multipart/report') || str_contains($sender, 'mailer-daemon');
            if ($ownId || $sender === mb_strtolower(config('support.address')) || ($auto !== '' && $auto !== 'no') || in_array($precedence, ['bulk', 'list', 'junk'], true) || $bounce || (string) $header->get('list_id') !== '') {
                if ($bounce && $reply !== '') {
                    SupportMessage::where('message_id', $reply)->whereIn('direction', ['outbound', 'notice'])->update(['delivery_status' => 'failed', 'error_code' => 'BOUNCE_RECEIVED']);
                }
                $receipt->update(['outcome' => 'ignored', 'reason' => $bounce ? 'DELIVERY_STATUS' : 'LOOP_PREVENTED', 'identity_hash' => $identity, 'fingerprint' => $fingerprint, 'message_id' => $id ?: null]);

                return;
            }
            if ($header->get('from')->count() !== 1 || ! filter_var($sender, FILTER_VALIDATE_EMAIL)) {
                $receipt->update(['outcome' => 'quarantine', 'reason' => 'INVALID_SENDER']);

                return;
            }
            $body = trim($mail->getTextBody());
            if ($body === '') {
                $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $mail->getHTMLBody());
                $body = trim(html_entity_decode(strip_tags(preg_replace('#<(?:br\s*/?|/p|/div)>#i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            if ($body === '' || mb_strlen($body) > 20000) {
                $receipt->update(['outcome' => 'quarantine', 'reason' => $body === '' ? 'UNREADABLE_BODY' : 'BODY_TOO_LONG', 'fingerprint' => $fingerprint, 'message_id' => $id ?: null]);

                return;
            }
            $tickets = $this->matchingTickets($header);
            if ($tickets->count() > 1 || ($tickets->isNotEmpty() && $tickets->first()->contact_email !== $sender)) {
                $receipt->update(['outcome' => 'quarantine', 'reason' => 'AMBIGUOUS_THREAD', 'fingerprint' => $fingerprint, 'message_id' => $id ?: null]);

                return;
            }
            if ($tickets->isEmpty() || $tickets->first()->source !== 'web') {
                $receipt->update(['outcome' => 'ignored', 'reason' => 'NOT_WEB_TICKET', 'identity_hash' => $identity, 'fingerprint' => $fingerprint, 'message_id' => $id ?: null]);

                return;
            }
            $ticket = $tickets->first();
            $receipt->update(['ticket_id' => $ticket->id]);
            $reservation = $media->reserve('inbound:'.$receipt->id, $identity, 'inbound', $ticket->id, receipt: $receipt->id);
            try {
                $prepared = $media->result($reservation, 'images-result');
                if (! $prepared) {
                    $files = $media->work($reservation, function ($directory) use ($mail): array {
                        $files = [];
                        foreach ($mail->getAttachments() as $index => $attachment) {
                            $path = $directory.'/mail-upload-'.count($files);
                            $bytes = $attachment->getContent();
                            if (strlen($bytes) > SupportMediaStorage::MAX_BYTES || file_put_contents($path, $bytes) !== strlen($bytes)) {
                                throw new \RuntimeException('MEDIA_MAIL_STAGING_FAILED');
                            }
                            $files[] = new UploadedFile($path, 'image', null, null, true);
                        }

                        return $files;
                    });
                    $prepared = app(SupportImages::class)->prepare($reservation, $files);
                }
                $media->cleanWork($reservation);
                DB::transaction(function () use ($receipt, $body, $sender, $ticket, $prepared, $reservation, $media, $reply, $references, $id, $identity, $fingerprint): void {
                    $ticket = AdminSupportTicket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
                    $receipt = SupportMailReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
                    if ($receipt->media_deletion_id || $receipt->outcome !== 'pending' || $receipt->intake_status !== 'eligible') {
                        return;
                    }
                    $operations = app(SupportOperations::class);
                    $message = $operations->inbound($ticket, $body, $sender, ['channel' => 'email', 'in_reply_to' => $reply, 'references' => $references], $id ?: null);
                    app(SupportImages::class)->attach($message, $reservation, $prepared);
                    $message->update(['warnings' => $prepared['warnings']]);
                    $receipt->update(['outcome' => 'accepted', 'identity_hash' => $identity, 'fingerprint' => $fingerprint, 'message_id' => $id ?: null, 'support_message_id' => $message->id, 'ticket_id' => $ticket->id]);
                    $media->associate($reservation, $ticket->id, $message->id);
                    if ($receipt->media_reservation_id) {
                        $media->associate(SupportMediaReservation::findOrFail($receipt->media_reservation_id), $ticket->id, $message->id);
                    }
                    DB::afterCommit(fn () => app(SupportImages::class)->notify($message->fresh()));
                });
            } finally {
                if (! $reservation->fresh()->deletion_id) {
                    $media->cleanWork($reservation);
                }
            }
        } catch (\Throwable) {
            $receipt->refresh();
            if (! $receipt->media_deletion_id && $receipt->outcome === 'pending') {
                $receipt->update(['outcome' => 'quarantine', 'reason' => 'MIME_PARSE_FAILED']);
            }
        }
    }

    private function matchingTickets($header): Collection
    {
        $reply = trim((string) $header->get('in_reply_to'), '<> ');
        $references = (string) $header->get('references');
        $ids = array_filter(array_unique([$reply, ...preg_split('/[\s<>]+/', $references)]));
        $tickets = AdminSupportTicket::whereHas('messages', fn ($q) => $q->whereIn('message_id', $ids))->get();
        if ($tickets->isEmpty() && preg_match('/\bSUP-(?:[A-Z0-9]{20}|[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{7,8})\b/', (string) $header->get('subject'), $match)) {
            $tickets = AdminSupportTicket::where('public_number', $match[0])->get();
        }

        return $tickets;
    }
}
