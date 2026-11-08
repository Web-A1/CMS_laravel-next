<?php

namespace App\Services\Support;

use App\Models\AdminSupportTicket;
use App\Models\SupportAttachment;
use App\Models\SupportMailReceipt;
use App\Models\SupportMediaReservation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

final class SupportMedia
{
    public function __construct(private readonly SupportMediaStorage $storage) {}

    public function ready(): void
    {
        abort_unless(Schema::hasTable('support_media_reservations') && Schema::hasTable('support_media_deletions'), 503, 'Поддержка временно недоступна.');
    }

    public function outsideTransaction(): void
    {
        $this->ready();
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('MEDIA_REQUIRES_COMMITTED_RESERVATION');
        }
    }

    public function reserve(string $logicalKey, string $hash, string $kind, ?int $ticket = null, ?int $attachment = null, ?int $receipt = null): SupportMediaReservation
    {
        $this->outsideTransaction();
        $identity = $this->storage->identity(config('support.disk'));

        $reserve = function () use ($logicalKey, $hash, $kind, $ticket, $attachment, $receipt, $identity) {
            if ($ticket !== null) {
                AdminSupportTicket::whereKey($ticket)->lockForUpdate()->firstOrFail();
            }
            abort_if(($attachment && SupportAttachment::whereKey($attachment)->value('media_deletion_id'))
                || ($receipt && SupportMailReceipt::whereKey($receipt)->value('media_deletion_id')), 409, 'Медиа этого сообщения удаляются.');
            $existing = SupportMediaReservation::where('logical_key', $logicalKey)->lockForUpdate()->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'Ключ уже использован для других данных.');
                abort_if($existing->deletion_id || str_starts_with($existing->error_code ?? '', 'MEDIA_ORPHAN_'), 409, 'Эти вложения уже удаляются.');
                $this->storage->verifyIdentity($existing->storage_identity);

                return $existing;
            }
            $id = (string) Str::uuid();

            return SupportMediaReservation::create([
                'id' => $id, 'logical_key' => $logicalKey, 'payload_hash' => $hash, 'kind' => $kind,
                'ticket_id' => $ticket, 'attachment_id' => $attachment, 'receipt_id' => $receipt,
                'storage_identity' => $identity, 'files' => [], 'work_dir' => $this->workRoot().'/'.$id,
            ]);
        };
        try {
            return DB::transaction($reserve);
        } catch (UniqueConstraintViolationException $error) {
            // A simultaneous first request may have committed the same logical owner.
            if (! SupportMediaReservation::where('logical_key', $logicalKey)->exists()) {
                throw $error;
            }

            return DB::transaction($reserve);
        }
    }

    public function guard(string $id, \Closure $operation): mixed
    {
        $this->outsideTransaction();
        $ticket = SupportMediaReservation::findOrFail($id)->ticket_id;

        return DB::transaction(function () use ($id, $ticket, $operation) {
            if ($ticket !== null) {
                AdminSupportTicket::whereKey($ticket)->lockForUpdate()->firstOrFail();
            }
            $reservation = SupportMediaReservation::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($reservation->deletion_id || str_starts_with($reservation->error_code ?? '', 'MEDIA_ORPHAN_'), 409, 'Вложения этого сообщения удаляются.');
            abort_if(($reservation->attachment_id && SupportAttachment::whereKey($reservation->attachment_id)->value('media_deletion_id'))
                || ($reservation->receipt_id && SupportMailReceipt::whereKey($reservation->receipt_id)->value('media_deletion_id')), 409, 'Медиа этого сообщения удаляются.');

            return $operation($reservation);
        });
    }

    public function stage(SupportMediaReservation $reservation, string $slot, UploadedFile $upload): string
    {
        $this->outsideTransaction();
        if (! $upload->isValid() || ! $upload->getRealPath()) {
            throw new RuntimeException('MEDIA_UPLOAD_INVALID');
        }
        $path = $reservation->work_dir.'/'.$slot;
        $borrowed = $upload->getRealPath();
        $this->start($reservation->id, $slot, ['kind' => 'local', 'path' => $path, 'borrowed' => $borrowed, 'borrowed_stat' => $this->fileIdentity($borrowed)]);
        try {
            return $this->guard($reservation->id, function ($owner) use ($slot, $path, $borrowed): string {
                $this->makeWorkDirectory($owner);
                $source = fopen($borrowed, 'rb');
                $destination = fopen($path, 'xb');
                if (! $source || ! $destination) {
                    throw new RuntimeException('MEDIA_STAGING_FAILED');
                }
                try {
                    $bytes = stream_copy_to_stream($source, $destination, SupportMediaStorage::MAX_BYTES + 1);
                } finally {
                    fclose($source);
                    fclose($destination);
                }
                if ($bytes < 1 || $bytes > SupportMediaStorage::MAX_BYTES) {
                    throw new RuntimeException('MEDIA_OBJECT_SIZE_INVALID');
                }
                $this->acknowledge($owner, $slot, ['bytes' => $bytes, 'sha256' => hash_file('sha256', $path)]);

                return $path;
            });
        } catch (\Throwable $error) {
            $this->uncertain($reservation->id, $slot);
            throw $error;
        }
    }

    public function borrow(SupportMediaReservation $reservation, string $slot, UploadedFile $upload): void
    {
        if (! $upload->isValid() || ! $upload->getRealPath()) {
            return;
        }
        $path = $upload->getRealPath();
        $this->guard($reservation->id, function ($owner) use ($slot, $path): void {
            $files = $owner->files;
            if (isset($files[$slot]) && empty($files[$slot]['borrowed_removed']) && $files[$slot]['borrowed'] !== $path) {
                $slot .= '-'.substr(hash('sha256', $path), 0, 12);
            }
            if (! isset($files[$slot]) || ! empty($files[$slot]['borrowed_removed'])) {
                $files[$slot] = ['kind' => 'local', 'state' => 'acknowledged', 'borrowed' => $path, 'borrowed_stat' => $this->fileIdentity($path)];
            }
            $owner->update(['files' => $files]);
        });
    }

    public function write(SupportMediaReservation $reservation, string $slot, string $path): string
    {
        $key = 'v1/'.$reservation->id.'/'.$slot;
        $this->start($reservation->id, $slot, ['kind' => 'object', 'key' => $key]);
        try {
            $this->guard($reservation->id, function ($owner) use ($slot, $key, $path): void {
                $metadata = $this->storage->put($owner->storage_identity, $key, $path, $owner->id, $slot);
                $this->acknowledge($owner, $slot, $metadata);
            });
        } catch (\Throwable $error) {
            $this->uncertain($reservation->id, $slot);
            throw $error;
        }

        return $key;
    }

    public function work(SupportMediaReservation $reservation, \Closure $operation): mixed
    {
        $slot = 'work-'.Str::random(12);
        $this->start($reservation->id, $slot, ['kind' => 'directory', 'path' => $reservation->work_dir]);
        try {
            return $this->guard($reservation->id, function ($owner) use ($operation, $slot) {
                $this->makeWorkDirectory($owner);
                $result = $operation($owner->work_dir);
                $this->acknowledge($owner, $slot, []);

                return $result;
            });
        } catch (\Throwable $error) {
            $this->uncertain($reservation->id, $slot);
            throw $error;
        }
    }

    public function cleanWork(SupportMediaReservation $reservation): void
    {
        $this->guard($reservation->id, function ($owner): void {
            $files = $owner->files;
            foreach ($files as &$file) {
                if (isset($file['borrowed']) && empty($file['borrowed_removed']) && is_file($file['borrowed'])) {
                    if ($this->fileIdentity($file['borrowed']) !== $file['borrowed_stat']) {
                        throw new RuntimeException('MEDIA_UPLOAD_IDENTITY_CHANGED');
                    }
                    if (! unlink($file['borrowed'])) {
                        throw new RuntimeException('MEDIA_UPLOAD_CLEANUP_FAILED');
                    }
                }
                if (isset($file['borrowed'])) {
                    $file['borrowed_removed'] = true;
                }
            }
            unset($file);
            $this->removeWorkDirectory($owner->work_dir);
            foreach ($files as &$file) {
                if (in_array($file['kind'], ['local', 'directory'], true)) {
                    $file['state'] = 'acknowledged';
                    $file['removed'] = true;
                }
            }
            unset($file);
            $owner->update(['files' => $files]);
        });
    }

    public function result(SupportMediaReservation $reservation, string $name, ?array $value = null): ?array
    {
        if ($value === null) {
            return $reservation->fresh()->files[$name]['value'] ?? null;
        }
        $this->guard($reservation->id, function ($owner) use ($name, $value): void {
            $files = $owner->files;
            $files[$name] = ['kind' => 'result', 'state' => 'acknowledged', 'value' => $value];
            $owner->update(['files' => $files]);
        });

        return $value;
    }

    public function associate(SupportMediaReservation $reservation, int $ticket, ?int $message = null, ?int $attachment = null): void
    {
        $owner = SupportMediaReservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
        abort_if($owner->deletion_id || str_starts_with($owner->error_code ?? '', 'MEDIA_ORPHAN_'), 409);
        foreach ($owner->files as $file) {
            if ($file['state'] !== 'acknowledged') {
                throw new RuntimeException('MEDIA_IO_UNCONFIRMED');
            }
        }
        if (file_exists($owner->work_dir)) {
            throw new RuntimeException('MEDIA_WORK_CLEANUP_UNCONFIRMED');
        }
        $owner->update(['ticket_id' => $ticket, 'message_id' => $message, 'attachment_id' => $attachment ?? $owner->attachment_id, 'associated_at' => now(), 'error_code' => null]);
    }

    public function removeWorkDirectory(string $directory): void
    {
        if (! preg_match('/\A'.preg_quote($this->workRoot(), '/').'\/[a-f0-9-]{36}\z/D', $directory)) {
            throw new RuntimeException('MEDIA_WORK_ROOT_UNSAFE');
        }
        $this->assertNoSymlinks($directory);
        if (! file_exists($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            if ($file->isLink() || ($file->isDir() ? ! rmdir($file->getPathname()) : ! unlink($file->getPathname()))) {
                throw new RuntimeException('MEDIA_WORK_CLEANUP_FAILED');
            }
        }
        if (! rmdir($directory)) {
            throw new RuntimeException('MEDIA_WORK_CLEANUP_FAILED');
        }
        clearstatcache(true, $directory);
    }

    public function workRoot(): string
    {
        return rtrim(config('support.work_root', storage_path('app/support-media-work')), '/');
    }

    public function removeBorrowed(array $target): void
    {
        if (! file_exists($target['path']) && ! is_link($target['path'])) {
            return;
        }
        if ($this->fileIdentity($target['path']) !== $target['identity'] || ! unlink($target['path'])) {
            throw new RuntimeException('MEDIA_UPLOAD_CLEANUP_UNCONFIRMED');
        }
    }

    public function recoverOrphans(): void
    {
        $this->outsideTransaction();
        $owners = SupportMediaReservation::whereNull('ticket_id')->whereNull('associated_at')
            ->where('created_at', '<', now()->subMinutes(30))
            ->where(fn ($query) => $query->whereNull('error_code')->orWhere('error_code', 'MEDIA_ORPHAN_REMOVING'))
            ->orderBy('created_at')->limit(10)->get();
        foreach ($owners as $reservation) {
            try {
                $sealed = DB::transaction(function () use ($reservation) {
                    $owner = SupportMediaReservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
                    if ($owner->ticket_id || $owner->associated_at) {
                        return null;
                    }
                    foreach ($owner->files as $file) {
                        if ($file['state'] !== 'acknowledged') {
                            throw new RuntimeException('MEDIA_IO_UNCONFIRMED');
                        }
                    }
                    $owner->update(['error_code' => 'MEDIA_ORPHAN_REMOVING']);

                    return $owner;
                });
                if (! $sealed) {
                    continue;
                }
                foreach ($sealed->files as $file) {
                    if ($file['kind'] === 'object') {
                        $this->storage->deleteKey($sealed->storage_identity, $file['key']);
                    }
                    if (isset($file['borrowed']) && empty($file['borrowed_removed'])) {
                        $this->removeBorrowed(['path' => $file['borrowed'], 'identity' => $file['borrowed_stat']]);
                    }
                }
                $this->removeWorkDirectory($sealed->work_dir);
                $sealed->update(['error_code' => 'MEDIA_ORPHAN_REMOVED']);
            } catch (\Throwable $error) {
                Log::warning('Support orphan cleanup incomplete', ['reservation_id' => $reservation->id]);
            }
        }
    }

    private function start(string $id, string $slot, array $resource): void
    {
        if (! preg_match('/\A[a-zA-Z0-9._-]+\z/D', $slot)) {
            throw new RuntimeException('MEDIA_SLOT_INVALID');
        }
        $this->guard($id, function ($owner) use ($slot, $resource): void {
            $files = $owner->files;
            if (isset($files[$slot]) && ! ($files[$slot]['kind'] === 'local' && $files[$slot]['state'] === 'acknowledged' && ! file_exists($files[$slot]['path'] ?? ''))) {
                throw new RuntimeException('MEDIA_SLOT_ALREADY_STARTED');
            }
            $files[$slot] = [...$resource, 'state' => 'started'];
            $owner->update(['files' => $files]);
        });
    }

    private function acknowledge(SupportMediaReservation $owner, string $slot, array $metadata): void
    {
        $files = $owner->files;
        $files[$slot] = [...$files[$slot], ...$metadata, 'state' => 'acknowledged'];
        $owner->update(['files' => $files]);
    }

    private function uncertain(string $id, string $slot): void
    {
        DB::transaction(function () use ($id, $slot): void {
            $owner = SupportMediaReservation::whereKey($id)->lockForUpdate()->firstOrFail();
            $files = $owner->files;
            if (isset($files[$slot]) && $files[$slot]['state'] !== 'acknowledged') {
                $files[$slot]['state'] = 'uncertain';
                $owner->update(['files' => $files, 'error_code' => 'MEDIA_IO_UNCONFIRMED']);
            }
        });
    }

    private function makeWorkDirectory(SupportMediaReservation $owner): void
    {
        $this->assertNoSymlinks($owner->work_dir);
        if (! is_dir($owner->work_dir) && ! mkdir($owner->work_dir, 0700, true) && ! is_dir($owner->work_dir)) {
            throw new RuntimeException('MEDIA_WORK_ROOT_UNAVAILABLE');
        }
    }

    private function assertNoSymlinks(string $path): void
    {
        for ($part = $path; $part !== '/' && $part !== '.'; $part = dirname($part)) {
            if (is_link($part)) {
                throw new RuntimeException('MEDIA_SYMLINK_UNSAFE');
            }
        }
    }

    private function fileIdentity(string $path): array
    {
        clearstatcache(true, $path);
        $stat = lstat($path);
        if (! $stat || is_link($path)) {
            throw new RuntimeException('MEDIA_UPLOAD_UNSAFE');
        }

        return ['device' => $stat['dev'], 'inode' => $stat['ino'], 'size' => $stat['size'], 'changed' => $stat['ctime'], 'hash' => hash_file('sha256', $path)];
    }
}
