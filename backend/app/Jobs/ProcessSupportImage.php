<?php

namespace App\Jobs;

use App\Models\AdminSupportTicket;
use App\Models\SupportAttachment;
use App\Services\Reviews\ReviewImageProcessor;
use App\Services\Support\SupportImages;
use App\Services\Support\SupportMedia;
use App\Services\Support\SupportMediaStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProcessSupportImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 150;

    public function __construct(public int $attachmentId) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(ReviewImageProcessor $processor, SupportImages $images): void
    {
        $media = app(SupportMedia::class);
        $media->outsideTransaction();
        $attachment = SupportAttachment::findOrFail($this->attachmentId);
        if ($attachment->media_deletion_id || $attachment->status !== 'pending') {
            return;
        }
        $ticketId = $attachment->message->ticket_id;
        $reservation = $media->reserve('process-image:'.$attachment->id, hash('sha256', json_encode([$attachment->source_key, $attachment->mime, $attachment->bytes])), 'image', $ticketId, $attachment->id);
        $storage = app(SupportMediaStorage::class);
        try {
            $prepared = $media->result($reservation, 'processed-result');
            if (! $prepared) {
                $variants = $media->work($reservation, function ($directory) use ($attachment, $processor, $storage) {
                    $attachment->refresh();
                    abort_if($attachment->media_deletion_id || $attachment->status !== 'pending', 409);
                    $source = $storage->read($storage->identity($attachment->disk), $attachment->source_key);
                    if (file_put_contents($directory.'/source', $source) !== strlen($source)) {
                        throw new \RuntimeException('MEDIA_SOURCE_COPY_FAILED');
                    }

                    return $processor->variants($directory.'/source', $attachment->mime, 'screenshot', $directory, temporaryDirectory: $directory);
                });
                $webp = array_values(array_filter($variants, fn ($variant) => $variant['format'] === 'webp'));
                $full = $webp[array_key_last($webp)];
                $thumb = $webp[0];
                foreach ($webp as $variant) {
                    if ($variant['width'] <= 320) {
                        $thumb = $variant;
                    }
                }
                $keys = [];
                foreach (['full' => $full, 'thumb' => $thumb] as $slot => $variant) {
                    $stored = $reservation->fresh()->files[$slot.'.webp'] ?? null;
                    $keys[$slot] = $stored && $stored['state'] === 'acknowledged'
                        ? $stored['key'] : $media->write($reservation, $slot.'.webp', $variant['file']);
                }
                $prepared = $media->result($reservation, 'processed-result', ['preview_key' => $keys['full'], 'thumbnail_key' => $keys['thumb'], 'width' => $full['width'], 'height' => $full['height']]);
            }
            $media->cleanWork($reservation);
            DB::transaction(function () use ($attachment, $ticketId, $reservation, $media, $prepared): void {
                AdminSupportTicket::whereKey($ticketId)->lockForUpdate()->firstOrFail();
                $attachment = SupportAttachment::whereKey($attachment->id)->lockForUpdate()->firstOrFail();
                if ($attachment->media_deletion_id || $attachment->status !== 'pending') {
                    return;
                }
                $attachment->update([...$prepared, 'status' => 'ready', 'error_code' => null]);
                // The immutable original key stays linked until closure.
                $media->associate($reservation, $ticketId, $attachment->message_id, $attachment->id);
            });
            if ($attachment->media_reservation_id && str_starts_with($attachment->source_key, 'v1/'.$attachment->media_reservation_id.'/')) {
                try {
                    $media->guard($attachment->media_reservation_id, function ($owner) use ($attachment, $storage): void {
                        if (SupportAttachment::where('source_key', $attachment->source_key)->count() !== 1) {
                            return;
                        }
                        $storage->deleteKey($owner->storage_identity, $attachment->source_key);
                        $files = $owner->files;
                        foreach ($files as &$file) {
                            if (($file['key'] ?? null) === $attachment->source_key) {
                                $file['removed'] = true;
                            }
                        }
                        unset($file);
                        $owner->update(['files' => $files]);
                    });
                } catch (\Throwable) {
                    // The original remains linked for closure and recovery.
                }
            }
            $images->notify($attachment->message);
        } finally {
            if (! $reservation->fresh()->deletion_id) {
                $media->cleanWork($reservation);
            }
        }
    }

    public function failed(?\Throwable $exception): void
    {
        if (! Schema::hasColumn('support_attachments', 'media_deletion_id')) {
            return;
        }
        $attachment = SupportAttachment::find($this->attachmentId);
        if ($attachment && ! $attachment->media_deletion_id && $attachment->status === 'pending') {
            $attachment->update(['status' => 'failed', 'error_code' => 'IMAGE_PROCESSING_FAILED']);
            app(SupportImages::class)->notify($attachment->message);
        }
    }
}
