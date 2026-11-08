<?php

namespace App\Services\Support;

use App\Models\AdminSupportTicket;
use App\Models\SupportAttachment;
use App\Models\SupportMailReceipt;
use App\Models\SupportMediaDeletion;
use App\Models\SupportMediaReservation;
use App\Models\SupportMessage;
use RuntimeException;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message;

final class SupportMediaInventory
{
    public function __construct(private readonly SupportMediaStorage $storage, private readonly SupportMedia $media) {}

    public function plan(SupportMediaDeletion $deletion): array
    {
        $snapshot = $deletion->snapshot;
        $ticket = AdminSupportTicket::findOrFail($deletion->ticket_id);
        $reservations = SupportMediaReservation::whereIn('id', $snapshot['reservations'])->get();
        $attachments = SupportAttachment::whereIn('id', $snapshot['attachments'])->get();
        $receipts = SupportMailReceipt::whereIn('id', $snapshot['receipts'])->get();
        $manifest = ['keys' => [], 'directories' => [], 'borrowed' => [], 'admitted_receipts' => []];
        $registered = [];
        foreach ($reservations as $reservation) {
            if ($reservation->deletion_id !== $deletion->id || $reservation->ticket_id !== $ticket->id || $reservation->protocol !== 1) {
                throw new RuntimeException('MEDIA_RESERVATION_OWNER_INVALID');
            }
            $this->storage->verifyIdentity($reservation->storage_identity);
            $manifest['directories'][] = $reservation->work_dir;
            foreach ($reservation->files as $file) {
                if ($file['state'] !== 'acknowledged') {
                    throw new RuntimeException('MEDIA_IO_UNCONFIRMED');
                }
                if ($file['kind'] === 'object') {
                    if (! str_starts_with($file['key'], 'v1/'.$reservation->id.'/')) {
                        throw new RuntimeException('MEDIA_RESERVATION_KEY_INVALID');
                    }
                    $this->addKey($manifest, $reservation->storage_identity, $file['key'], $snapshot);
                    $registered[$this->keyIdentity($reservation->storage_identity, $file['key'])] = true;
                }
                if (isset($file['borrowed']) && empty($file['borrowed_removed'])) {
                    $manifest['borrowed'][] = ['path' => $file['borrowed'], 'identity' => $file['borrowed_stat']];
                }
            }
        }
        $proven = (int) $ticket->media_protocol === 1
            && SupportMediaReservation::whereKey($ticket->birth_reservation_id)->where('ticket_id', $ticket->id)->where('protocol', 1)->whereNotNull('associated_at')->exists();
        foreach ($attachments as $attachment) {
            $identity = $this->storage->identity($attachment->disk);
            foreach (array_filter([$attachment->source_key, $attachment->preview_key, $attachment->thumbnail_key]) as $key) {
                $this->addKey($manifest, $identity, $key, $snapshot);
                $proven = $proven && isset($registered[$this->keyIdentity($identity, $key)]);
            }
            $proven = $proven && $attachment->media_reservation_id !== null;
        }
        foreach ($receipts as $receipt) {
            if ($receipt->original_key) {
                $identity = $this->storage->identity($receipt->disk);
                $this->addKey($manifest, $identity, $receipt->original_key, $snapshot);
                $proven = $proven && isset($registered[$this->keyIdentity($identity, $receipt->original_key)]);
            }
            $proven = $proven && ($receipt->media_reservation_id !== null || $receipt->original_key === null);
        }
        if (! $proven) {
            return $this->legacy($deletion, $manifest);
        }

        return ['coverage' => 'proven_v1', 'manifest' => $manifest, 'evidence' => ['version' => 1, 'birth_reservation' => $ticket->birth_reservation_id, 'reservations' => $reservations->modelKeys()]];
    }

    public function addKey(array &$manifest, array $identity, string $key, array $snapshot): void
    {
        foreach (SupportAttachment::whereNotIn('id', $snapshot['attachments'])->where(fn ($query) => $query->where('source_key', $key)->orWhere('preview_key', $key)->orWhere('thumbnail_key', $key))->get() as $other) {
            if ($this->keyIdentity($this->storage->identity($other->disk), $key) === $this->keyIdentity($identity, $key)) {
                throw new RuntimeException('MEDIA_SHARED_KEY');
            }
        }
        foreach (SupportMailReceipt::whereNotIn('id', [...$snapshot['receipts'], ...($manifest['admitted_receipts'] ?? [])])->where('original_key', $key)->get() as $other) {
            if ($this->keyIdentity($this->storage->identity($other->disk), $key) === $this->keyIdentity($identity, $key)) {
                throw new RuntimeException('MEDIA_SHARED_KEY');
            }
        }
        $id = $this->keyIdentity($identity, $key);
        $manifest['keys'][$id] = ['identity' => $identity, 'key' => $key];
    }

    public function keyIdentity(array $identity, string $key): string
    {
        unset($identity['disk']);
        ksort($identity);

        return hash('sha256', json_encode([$identity, $key]));
    }

    private function legacy(SupportMediaDeletion $deletion, array $manifest): array
    {
        $environment = app(SupportLegacyEnvironmentEvidence::class)->evidenceFor($deletion);
        $evidence = [...($deletion->inventory_evidence ?? []), 'version' => 1, 'environment' => $environment, 'pages' => [], 'resources' => [], 'unresolved' => []];
        $deadline = microtime(true) + 80;
        foreach ($deletion->snapshot['attachments'] as $id) {
            $attachment = SupportAttachment::findOrFail($id);
            if (! $attachment->media_reservation_id) {
                foreach (['full.webp', 'thumb.webp'] as $name) {
                    $this->addKey($manifest, $this->storage->identity($attachment->disk), 'images/'.$id.'/'.$name, $deletion->snapshot);
                }
            }
        }
        $scanned = [];
        $workOwners = SupportMediaReservation::limit(10001)->pluck('id', 'work_dir');
        if ($workOwners->count() > 10000) {
            throw new RuntimeException('MEDIA_INVENTORY_LIMIT');
        }
        try {
            foreach ($environment['receipt']['domains'] as $domain) {
                $physical = $domain['identity'];
                $domainKey = $this->keyIdentity($physical, '');
                if (isset($scanned[$domainKey])) {
                    continue;
                }
                $scanned[$domainKey] = true;
                if ($domain['purpose'] === 'legacy_store') {
                    foreach (['sources/', 'voices/', 'images/', 'mail/'] as $prefix) {
                        $listing = $this->storage->inventory($physical, $prefix);
                        if (($listing['complete'] ?? false) !== true) {
                            throw new RuntimeException('MEDIA_INVENTORY_INCOMPLETE');
                        }
                        $evidence['pages'][] = ['domain' => $domain['id'], 'prefix' => $prefix, 'pages' => $listing['pages']];
                        foreach ($listing['entries'] as $entry) {
                            if (microtime(true) > $deadline) {
                                throw new RuntimeException('MEDIA_INVENTORY_LIMIT');
                            }
                            [$classification, $basis, $receiptId] = $this->classify($deletion, $physical, $entry);
                            $resource = ['domain' => $domain['id'], 'resource' => $entry, 'classification' => $classification, 'basis' => $basis];
                            $evidence['resources'][] = $resource;
                            if ($classification === 'unresolved') {
                                $evidence['unresolved'][] = $resource;
                                throw new RuntimeException('MEDIA_LEGACY_RESOURCE_UNRESOLVED');
                            }
                            if ($classification === 'target') {
                                if ($receiptId !== null && ! in_array($receiptId, $deletion->snapshot['receipts'], true)) {
                                    $manifest['admitted_receipts'][] = $receiptId;
                                }
                                $this->addKey($manifest, $physical, $entry['key'], $deletion->snapshot);
                            }
                        }
                    }
                } else {
                    $root = $physical['root'];
                    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                    $count = 0;
                    foreach ($iterator as $file) {
                        if (++$count > 100000 || microtime(true) > $deadline) {
                            throw new RuntimeException('MEDIA_INVENTORY_LIMIT');
                        }
                        if ($file->isDir() && ! $file->isLink()) {
                            continue;
                        }
                        $path = $file->getPathname();
                        $owner = null;
                        for ($parent = dirname($path), $depth = 0; str_starts_with($parent.'/', $root.'/') && $depth < 100; $parent = dirname($parent), $depth++) {
                            if ($workOwners->has($parent)) {
                                $owner = $workOwners[$parent];
                                break;
                            }
                        }
                        if ($file->isLink() || $owner === null) {
                            $evidence['unresolved'][] = ['domain' => $domain['id'], 'path' => $path, 'reason' => 'TEMP_OWNER_UNKNOWN'];
                            throw new RuntimeException('MEDIA_LEGACY_TEMP_UNRESOLVED');
                        }
                        $evidence['resources'][] = ['domain' => $domain['id'], 'path' => $path, 'classification' => in_array($owner, $deletion->snapshot['reservations'], true) ? 'target' : 'other', 'basis' => 'reservation_work_dir'];
                    }
                    $evidence['pages'][] = ['domain' => $domain['id'], 'root' => $root, 'count' => $count, 'complete' => true];
                }
            }
            $manifest['admitted_receipts'] = array_values(array_unique($manifest['admitted_receipts']));

            return ['coverage' => 'proven_inventory', 'manifest' => $manifest, 'evidence' => $evidence];
        } catch (\Throwable $error) {
            $evidence['complete'] = false;
            $deletion->update(['inventory_evidence' => $evidence]);
            throw $error;
        }
    }

    private function classify(SupportMediaDeletion $deletion, array $identity, array $entry): array
    {
        $key = $entry['key'];
        $snapshot = $deletion->snapshot;
        if (str_starts_with($key, 'mail/')) {
            $receipt = preg_match('/\Amail\/(\d+)\.eml\z/D', $key, $match) ? SupportMailReceipt::find($match[1]) : null;
            if (! $receipt) {
                return ['unresolved', 'MIME_RECEIPT_UNKNOWN', null];
            }
            $owner = $receipt->support_message_id;
            if (in_array($entry['kind'], ['marker', 'upload'], true)) {
                return $owner ? [in_array($owner, $snapshot['messages'], true) ? 'target' : 'other', 'receipt_message', $receipt->id] : ['unresolved', 'MIME_OWNER_UNKNOWN', null];
            }
            $raw = $this->storage->read($identity, $key, $entry['version'] ?? null);
            $fingerprint = hash('sha256', $raw);
            $known = SupportMailReceipt::where('fingerprint', $fingerprint)->whereNotNull('support_message_id')->get()->pluck('support_message_id')->unique();
            if ($known->count() === 1) {
                return [in_array($known->first(), $snapshot['messages'], true) ? 'target' : 'other', 'receipt_fingerprint', $receipt->id];
            }
            if ($known->count() > 1) {
                return ['unresolved', 'MIME_IDENTITY_COLLISION', null];
            }
            try {
                $mail = Message::fromString($raw, Config::make(['options' => ['rfc822' => false, 'debug' => false]]));
                $header = $mail->getHeader();
                $messageId = trim((string) $header->get('message_id'), '<> ');
                if ($messageId !== '' && SupportMailReceipt::where('message_id', $messageId)->whereNotNull('fingerprint')->where('fingerprint', '!=', $fingerprint)->exists()) {
                    return ['unresolved', 'MIME_IDENTITY_COLLISION', null];
                }
                $existing = $messageId === '' ? null : SupportMessage::where('message_id', $messageId)->first();
                if ($existing && $owner === $existing->id && $receipt->fingerprint === $fingerprint) {
                    return [in_array($existing->id, $snapshot['messages'], true) ? 'target' : 'other', 'accepted_message_identity', $receipt->id];
                }
                $references = array_filter(preg_split('/[\s<>]+/', (string) $header->get('in_reply_to').' '.(string) $header->get('references')));
                $threads = SupportMessage::whereIn('message_id', $references)->get()->pluck('ticket_id')->unique();
                if ($threads->count() === 1 && ($receipt->outcome !== 'pending') && $receipt->id <= $snapshot['receipt_ceiling']) {
                    $sender = mb_strtolower(trim((string) ($header->get('from')->first()?->mail ?? '')));
                    $historicalSenders = SupportMessage::where('ticket_id', $threads->first())->where('direction', 'inbound')->pluck('sender')->filter()->map(fn ($email) => mb_strtolower($email))->unique();
                    if ($historicalSenders->contains($sender) && $receipt->fingerprint === $fingerprint) {
                        return [$threads->first() === $deletion->ticket_id ? 'target' : 'other', 'retained_receipt_thread', $receipt->id];
                    }
                }
            } catch (\Throwable) {
                return ['unresolved', 'MIME_UNREADABLE', null];
            }

            return ['unresolved', 'MIME_OWNER_UNKNOWN', null];
        }
        $references = SupportAttachment::where(fn ($query) => $query->where('source_key', $key)->orWhere('preview_key', $key)->orWhere('thumbnail_key', $key))->get()
            ->filter(fn ($attachment) => $this->keyIdentity($this->storage->identity($attachment->disk), $key) === $this->keyIdentity($identity, $key));
        if ($references->isEmpty() && preg_match('/\Aimages\/(\d+)\/(?:full|thumb)\.webp\z/D', $key, $match)) {
            $attachment = SupportAttachment::find($match[1]);
            if ($attachment && $this->keyIdentity($this->storage->identity($attachment->disk), $key) === $this->keyIdentity($identity, $key)) {
                $references = collect([$attachment]);
            }
        }
        if ($references->isNotEmpty()) {
            $target = $references->contains(fn ($attachment) => in_array($attachment->id, $snapshot['attachments'], true));
            if ($target && $references->contains(fn ($attachment) => ! in_array($attachment->id, $snapshot['attachments'], true))) {
                return ['unresolved', 'SHARED_KEY', null];
            }

            return [$target ? 'target' : 'other', 'attachment_key', null];
        }

        return ['unresolved', 'RANDOM_KEY_OWNER_UNKNOWN', null];
    }
}
