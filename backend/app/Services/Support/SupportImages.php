<?php

namespace App\Services\Support;

use App\Jobs\ProcessSupportImage;
use App\Models\AdminSupportTicket;
use App\Models\SupportMediaReservation;
use App\Models\SupportMessage;
use App\Services\Reviews\ReviewImageProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SupportImages
{
    public function accept(SupportMessage $message, array $files): array
    {
        $media = app(SupportMedia::class);
        $reservation = $media->reserve('images:'.Str::uuid(), hash('sha256', (string) $message->id), 'images', $message->ticket_id);
        $prepared = $this->prepare($reservation, $files);
        $media->cleanWork($reservation);

        return DB::transaction(function () use ($message, $reservation, $prepared, $media): array {
            $ticket = $message->ticket()->lockForUpdate()->firstOrFail();
            $message->refresh();
            abort_if($message->media_deletion_id, 409);
            $this->attach($message, $reservation, $prepared);
            $media->associate($reservation, $ticket->id, $message->id);

            return $prepared['warnings'];
        });
    }

    public function prepare(SupportMediaReservation $reservation, array $files): array
    {
        $media = app(SupportMedia::class);
        foreach ($files as $index => $file) {
            $media->borrow($reservation, 'input-'.$index, $file);
        }
        if ($stored = $media->result($reservation, 'images-result')) {
            return $stored;
        }
        $warnings = [];
        $attachments = [];
        foreach ($files as $index => $file) {
            try {
                if ($index >= config('support.max_files')) {
                    throw new \InvalidArgumentException('Допустимо до пяти изображений.');
                }
                if (! $file->isValid() || $file->getSize() > config('support.max_file_bytes')) {
                    throw new \InvalidArgumentException('Размер изображения не должен превышать 10 МиБ.');
                }
                $local = $reservation->fresh()->files['upload-'.$index] ?? null;
                $path = $local && $local['state'] === 'acknowledged' && is_file($local['path'])
                    ? $local['path'] : $media->stage($reservation, 'upload-'.$index, $file);
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
                $media->work($reservation, fn ($directory) => app(ReviewImageProcessor::class)->inspect($path, $mime, rejectAnimation: true, temporaryDirectory: $directory));
                $stored = $reservation->fresh()->files['image-'.$index] ?? null;
                $key = $stored && $stored['state'] === 'acknowledged'
                    ? $stored['key'] : $media->write($reservation, 'image-'.$index, $path);
                $attachments[] = ['disk' => config('support.disk'), 'source_key' => $key, 'mime' => $mime, 'bytes' => filesize($path), 'status' => 'pending'];
            } catch (\Throwable $e) {
                if ($e instanceof \RuntimeException && str_starts_with($e->getMessage(), 'MEDIA_')) {
                    throw $e;
                }
                $warnings[] = ['index' => $index, 'code' => 'IMAGE_REJECTED', 'message' => $e instanceof \InvalidArgumentException ? $e->getMessage() : 'Не удалось сохранить изображение. Текст обращения принят.'];
            }
        }

        return $media->result($reservation, 'images-result', ['attachments' => $attachments, 'warnings' => $warnings]);
    }

    public function attach(SupportMessage $message, SupportMediaReservation $reservation, array $prepared): void
    {
        foreach ($prepared['attachments'] as $data) {
            $attachment = $message->attachments()->create([...$data, 'media_reservation_id' => $reservation->id]);
            DB::afterCommit(function () use ($attachment): void {
                try {
                    ProcessSupportImage::dispatch($attachment->id)->onQueue(config('support.queue'));
                } catch (\Throwable) {
                    // Recovered from durable pending attachments.
                }
            });
        }
    }

    public function notify(SupportMessage $source): void
    {
        DB::transaction(function () use ($source): void {
            AdminSupportTicket::whereKey($source->ticket_id)->lockForUpdate()->firstOrFail();
            $source = SupportMessage::whereKey($source->id)->lockForUpdate()->firstOrFail();
            if ($source->media_deletion_id || ! $source->notify_failures || $source->attachments()->where('status', 'pending')->exists()) {
                return;
            }
            $late = $source->attachments()->where('status', 'failed')->exists();
            $earlyInbound = ($source->headers['channel'] ?? '') === 'email' && count($source->warnings ?? []) > 0;
            if ((! $late && ! $earlyInbound) || ! $source->ticket->contact_email) {
                $source->update(['notify_failures' => false]);

                return;
            }
            app(SupportOperations::class)->outgoing($source->ticket, 'Текст вашего сообщения сохранён. Некоторые изображения не удалось принять или обработать. Отправьте их повторно в JPEG, PNG, WebP, AVIF или HEIC, не более 10 МиБ каждое.', 'notice', 'image-failure:'.$source->id);
            $source->update(['notify_failures' => false]);
        });
    }
}
