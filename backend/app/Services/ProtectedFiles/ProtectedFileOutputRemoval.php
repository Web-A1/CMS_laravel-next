<?php

namespace App\Services\ProtectedFiles;

use App\Jobs\DeleteProtectedFileOutput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ProtectedFileOutputRemoval
{
    public function schedule(?string $key): void
    {
        if (! $key) {
            return;
        }
        $disk = (string) config('protected_files.output_disk', 'local');
        $this->assertSafe($key);
        $id = DB::table('protected_file_output_deletions')->insertGetId([
            'disk' => $disk, 'object_key' => $key, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::afterCommit(function () use ($id): void {
            try {
                DeleteProtectedFileOutput::dispatch($id);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function run(int $id): bool
    {
        try {
            return DB::transaction(function () use ($id): bool {
                $entry = DB::table('protected_file_output_deletions')->lockForUpdate()->find($id);
                if (! $entry || $entry->completed_at) {
                    return true;
                }
                $this->assertSafe($entry->object_key);
                // Another delivery may still use the same output. Sources are never candidates.
                if (DB::table('protected_file_deliveries')->where('output_object_key', $entry->object_key)->exists()
                    || $entry->disk === config('protected_files.source_disk', 'local')
                        && DB::table('lesson_material_files')->where('source_object_key', $entry->object_key)->exists()) {
                    throw new \RuntimeException('Персональный PDF ещё используется.');
                }
                $filesystem = Storage::disk($entry->disk);
                if ($filesystem->exists($entry->object_key) && ! $filesystem->delete($entry->object_key)) {
                    throw new \RuntimeException('Не удалось удалить персональный PDF.');
                }
                DB::table('protected_file_output_deletions')->where('id', $id)->update([
                    'completed_at' => now(), 'error' => null, 'updated_at' => now(),
                ]);

                return true;
            });
        } catch (Throwable $exception) {
            DB::table('protected_file_output_deletions')->where('id', $id)->update([
                'error' => 'Удаление персонального PDF ожидает повторной попытки.', 'updated_at' => now(),
            ]);
            report($exception);

            return false;
        }
    }

    public function retry(): int
    {
        $count = 0;
        foreach (DB::table('protected_file_output_deletions')->whereNull('completed_at')->orderBy('id')->pluck('id') as $id) {
            $count += (int) $this->run($id);
        }

        return $count;
    }

    private function assertSafe(string $key): void
    {
        $prefix = trim((string) config('protected_files.output_prefix', 'protected-copies'), '/');
        abort_unless($prefix !== '' && preg_match('~\A[a-zA-Z0-9_/-]+\z~', $prefix)
            && preg_match('~\A'.preg_quote($prefix, '~').'/[a-f0-9-]{36}/[a-f0-9-]{36}\.pdf\z~', $key)
            && ! in_array('..', explode('/', $key), true), 409, 'Недопустимый путь персонального PDF.');
    }
}
