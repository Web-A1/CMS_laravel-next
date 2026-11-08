<?php

namespace App\Services\ProtectedFiles;

use App\Exceptions\ProtectedFileSourceImportException;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialFile;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProtectedFileSourceIngestor
{
    private const PLAN_VERSION = 1;

    private const MAX_CONFIGURED_SOURCE_BYTES = 300 * 1024 * 1024;

    /**
     * @param  array{sha256?: string, size?: int, mime?: string}|null  $sourceExpectation
     * @return array{status: string, mode: string, change?: string, plan?: string, cleanup?: string}
     */
    public function execute(
        int $materialId,
        string $pdfPath,
        ?string $downloadFilename,
        bool $apply,
        ?string $confirmation,
        ?array $sourceExpectation = null,
    ): array {
        $configuration = $this->configuration();
        $verified = $this->verifiedSource($pdfPath, $downloadFilename, $configuration['max_source_bytes']);

        try {
            $this->assertSourceExpectation($verified, $sourceExpectation);

            $state = $this->materialState($materialId);
            $plan = $this->plan($materialId, $verified, $state['source'], $configuration, $sourceExpectation);
            $change = $state['source'] === null ? 'create' : 'replace';
            $noChange = $this->isNoChange($state['source'], $verified);

            if (! $apply) {
                return [
                    'status' => $noChange ? 'no_change' : 'ready',
                    'mode' => 'dry-run',
                    'change' => $noChange ? null : $change,
                    'plan' => $plan,
                ];
            }

            if ($confirmation === null || ! hash_equals($plan, $confirmation)) {
                throw new ProtectedFileSourceImportException(
                    ProtectedFileSourceImportException::PLAN_MISMATCH,
                );
            }

            if ($noChange) {
                return [
                    'status' => 'no_change',
                    'mode' => 'apply',
                ];
            }

            return $this->apply(
                $materialId,
                $verified,
                $state['source'],
                $configuration,
                $change,
            );
        } finally {
            fclose($verified['stream']);
        }
    }

    /**
     * @param  array{stream: resource, sha256: string, size: int, mime: string, filename: string}  $verified
     * @param  array{sha256?: string, size?: int, mime?: string}|null  $sourceExpectation
     */
    private function assertSourceExpectation(array $verified, ?array $sourceExpectation): void
    {
        if ($sourceExpectation === null) {
            return;
        }

        if (
            (array_key_exists('sha256', $sourceExpectation) && $sourceExpectation['sha256'] !== $verified['sha256'])
            || (array_key_exists('size', $sourceExpectation) && $sourceExpectation['size'] !== $verified['size'])
            || (array_key_exists('mime', $sourceExpectation) && $sourceExpectation['mime'] !== $verified['mime'])
        ) {
            throw new ProtectedFileSourceImportException(
                ProtectedFileSourceImportException::SOURCE_EXPECTATION_MISMATCH,
            );
        }
    }

    /**
     * @return array{disk: string, prefix: string, max_source_bytes: int}
     */
    private function configuration(): array
    {
        $disk = config('protected_files.source_disk');
        $prefix = config('protected_files.source_prefix');
        $maxSourceBytes = config('protected_files.max_source_ingest_bytes', config('protected_files.max_source_bytes'));
        $diskConfiguration = is_string($disk) ? config("filesystems.disks.{$disk}") : null;

        if (
            ! is_string($disk)
            || $disk === ''
            || ! is_array($diskConfiguration)
            || ! $this->isSafeDiskConfiguration($diskConfiguration)
            || ! is_string($prefix)
            || $prefix === ''
            || $prefix !== trim($prefix, '/')
            || strlen($prefix) > 900
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_\/-]*\z/', $prefix)
            || str_contains($prefix, '..')
            || str_contains($prefix, '//')
            || ! is_int($maxSourceBytes)
            || $maxSourceBytes < 1
            || $maxSourceBytes > self::MAX_CONFIGURED_SOURCE_BYTES
        ) {
            throw new ProtectedFileSourceImportException(
                ProtectedFileSourceImportException::INVALID_CONFIGURATION,
            );
        }

        return [
            'disk' => $disk,
            'prefix' => $prefix,
            'max_source_bytes' => $maxSourceBytes,
        ];
    }

    /**
     * @return array{stream: resource, sha256: string, size: int, mime: string, filename: string}
     */
    private function verifiedSource(string $pdfPath, ?string $downloadFilename, int $maxSourceBytes): array
    {
        if (str_contains($pdfPath, "\0")) {
            throw new ProtectedFileSourceImportException(
                ProtectedFileSourceImportException::UNSAFE_PATH,
            );
        }
        $canonicalPath = realpath($pdfPath);
        if (
            $pdfPath === ''
            || ! str_starts_with($pdfPath, DIRECTORY_SEPARATOR)
            || is_link($pdfPath)
            || $canonicalPath === false
            || $canonicalPath !== $pdfPath
            || ! is_file($canonicalPath)
            || $this->isInsideRepository($canonicalPath)
        ) {
            throw new ProtectedFileSourceImportException(
                ProtectedFileSourceImportException::UNSAFE_PATH,
            );
        }

        $permissions = fileperms($canonicalPath);
        if ($permissions === false || ($permissions & 0077) !== 0) {
            throw new ProtectedFileSourceImportException(
                ProtectedFileSourceImportException::UNSAFE_PERMISSIONS,
            );
        }

        $filename = $downloadFilename ?? basename($canonicalPath);
        $this->assertSafeFilename($filename);

        $source = @fopen($canonicalPath, 'rb');
        if ($source === false) {
            throw new ProtectedFileSourceImportException(
                ProtectedFileSourceImportException::UNSAFE_PATH,
            );
        }

        $temporary = tmpfile();
        if ($temporary === false) {
            fclose($source);
            throw new ProtectedFileSourceImportException(
                ProtectedFileSourceImportException::INTERNAL_ERROR,
            );
        }
        $temporaryMetadata = stream_get_meta_data($temporary);
        if (isset($temporaryMetadata['uri']) && is_string($temporaryMetadata['uri'])) {
            @chmod($temporaryMetadata['uri'], 0600);
            clearstatcache(true, $temporaryMetadata['uri']);
        }
        $temporaryPermissions = isset($temporaryMetadata['uri']) && is_string($temporaryMetadata['uri'])
            ? fileperms($temporaryMetadata['uri'])
            : false;
        if ($temporaryPermissions === false || ($temporaryPermissions & 0077) !== 0) {
            fclose($source);
            fclose($temporary);
            throw new ProtectedFileSourceImportException(
                ProtectedFileSourceImportException::INTERNAL_ERROR,
            );
        }

        try {
            $before = fstat($source);
            if (! is_array($before)) {
                throw new ProtectedFileSourceImportException(
                    ProtectedFileSourceImportException::SOURCE_UNSTABLE,
                );
            }
            if ((($before['mode'] ?? 0) & 0170000) !== 0100000) {
                throw new ProtectedFileSourceImportException(
                    ProtectedFileSourceImportException::UNSAFE_PATH,
                );
            }
            if ((int) ($before['nlink'] ?? 0) !== 1) {
                throw new ProtectedFileSourceImportException(
                    ProtectedFileSourceImportException::UNSAFE_PATH,
                );
            }
            if ((($before['mode'] ?? 0) & 0077) !== 0) {
                throw new ProtectedFileSourceImportException(
                    ProtectedFileSourceImportException::UNSAFE_PERMISSIONS,
                );
            }
            $expectedSize = (int) ($before['size'] ?? 0);
            if ($expectedSize < 1 || $expectedSize > $maxSourceBytes) {
                throw new ProtectedFileSourceImportException(
                    ProtectedFileSourceImportException::SOURCE_TOO_LARGE,
                );
            }

            $hash = hash_init('sha256');
            $size = 0;
            $header = '';

            while (! feof($source)) {
                $chunk = fread($source, 1024 * 1024);
                if ($chunk === false) {
                    throw new ProtectedFileSourceImportException(
                        ProtectedFileSourceImportException::SOURCE_UNSTABLE,
                    );
                }
                if ($chunk === '') {
                    continue;
                }

                if (strlen($header) < 5) {
                    $header .= substr($chunk, 0, 5 - strlen($header));
                }
                $size += strlen($chunk);
                if ($size > $maxSourceBytes) {
                    throw new ProtectedFileSourceImportException(
                        ProtectedFileSourceImportException::SOURCE_TOO_LARGE,
                    );
                }
                hash_update($hash, $chunk);
                $this->writeAll($temporary, $chunk);
            }

            $after = fstat($source);
            if (! is_array($after) || ! $this->sameFileStat($before, $after) || $size !== $expectedSize) {
                throw new ProtectedFileSourceImportException(
                    ProtectedFileSourceImportException::SOURCE_UNSTABLE,
                );
            }

            $metadata = stream_get_meta_data($temporary);
            $temporaryPath = $metadata['uri'] ?? null;
            $mime = is_string($temporaryPath)
                ? (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath)
                : false;
            if ($mime !== 'application/pdf' || $header !== '%PDF-') {
                throw new ProtectedFileSourceImportException(
                    ProtectedFileSourceImportException::INVALID_PDF,
                );
            }

            rewind($temporary);

            return [
                'stream' => $temporary,
                'sha256' => hash_final($hash),
                'size' => $size,
                'mime' => $mime,
                'filename' => $filename,
            ];
        } catch (Throwable $exception) {
            fclose($temporary);

            throw $exception;
        } finally {
            fclose($source);
        }
    }

    private function isInsideRepository(string $path): bool
    {
        $repositoryRoot = realpath(dirname(base_path()));
        if ($repositoryRoot === false) {
            throw new ProtectedFileSourceImportException(
                ProtectedFileSourceImportException::INVALID_CONFIGURATION,
            );
        }

        return $path === $repositoryRoot
            || str_starts_with($path, $repositoryRoot.DIRECTORY_SEPARATOR);
    }

    /** @param array<string, mixed> $configuration */
    private function isSafeDiskConfiguration(array $configuration): bool
    {
        $driver = $configuration['driver'] ?? null;
        if ($driver === 'local') {
            return $this->isPrivateLocalDiskConfiguration($configuration);
        }
        if ($driver !== 's3') {
            return false;
        }

        foreach (['key', 'secret', 'region', 'bucket', 'endpoint'] as $field) {
            if (! is_string($configuration[$field] ?? null) || trim($configuration[$field]) === '') {
                return false;
            }
        }
        $endpoint = parse_url($configuration['endpoint']);
        if (
            ! is_array($endpoint)
            || ($endpoint['scheme'] ?? null) !== 'https'
            || ! is_string($endpoint['host'] ?? null)
            || $endpoint['host'] === ''
            || isset($endpoint['user'])
            || isset($endpoint['pass'])
            || isset($endpoint['query'])
            || isset($endpoint['fragment'])
            || (isset($endpoint['path']) && $endpoint['path'] !== '' && $endpoint['path'] !== '/')
        ) {
            return false;
        }

        return ($configuration['visibility'] ?? null) === 'private'
            && ($configuration['throw'] ?? null) === true
            && ($configuration['report'] ?? null) === false
            && is_bool($configuration['use_path_style_endpoint'] ?? null)
            && (! isset($configuration['url']) || $configuration['url'] === null || $configuration['url'] === '')
            && (! isset($configuration['options']) || $configuration['options'] === []);
    }

    /** @param array<string, mixed> $configuration */
    private function isPrivateLocalDiskConfiguration(array $configuration): bool
    {
        $root = $configuration['root'] ?? null;
        if (
            ! is_string($root)
            || $root === ''
            || ! str_starts_with($root, DIRECTORY_SEPARATOR)
            || $this->hasDotPathSegment($root)
            || ($configuration['visibility'] ?? null) === 'public'
            || (is_string($configuration['url'] ?? null) && $configuration['url'] !== '')
        ) {
            return false;
        }

        $normalizedRoot = $this->normalizedFilesystemPath($root);
        $links = config('filesystems.links', []);
        $normalizedStorageRoot = $this->normalizedFilesystemPath(storage_path());
        if (
            ! str_starts_with($normalizedRoot, '/')
            || ! str_starts_with($normalizedRoot, $normalizedStorageRoot.'/')
            || ! is_array($links)
        ) {
            return false;
        }
        $publicRoots = [
            public_path(),
            storage_path('app/public'),
            ...array_values($links),
        ];

        foreach ($publicRoots as $publicRoot) {
            if (! is_string($publicRoot) || $publicRoot === '') {
                continue;
            }
            if (! str_starts_with($publicRoot, DIRECTORY_SEPARATOR)) {
                return false;
            }
            if ($this->hasDotPathSegment($publicRoot)) {
                return false;
            }
            $normalizedPublicRoot = $this->normalizedFilesystemPath($publicRoot);
            if (
                $normalizedRoot === $normalizedPublicRoot
                || str_starts_with($normalizedRoot, $normalizedPublicRoot.'/')
                || str_starts_with($normalizedPublicRoot, $normalizedRoot.'/')
            ) {
                return false;
            }
        }

        return true;
    }

    private function normalizedFilesystemPath(string $path): string
    {
        $candidate = rtrim(str_replace('\\', '/', $path), '/');
        $suffix = [];
        $canonicalPath = realpath($candidate);

        while ($canonicalPath === false) {
            $parent = dirname($candidate);
            if ($parent === $candidate) {
                return $candidate;
            }

            array_unshift($suffix, basename($candidate));
            $candidate = $parent;
            $canonicalPath = realpath($candidate);
        }

        $normalized = rtrim(str_replace('\\', '/', $canonicalPath), '/');

        return $suffix === [] ? $normalized : $normalized.'/'.implode('/', $suffix);
    }

    private function hasDotPathSegment(string $path): bool
    {
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    private function assertSafeFilename(string $filename): void
    {
        if (
            $filename === ''
            || strlen($filename) > 180
            || preg_match('//u', $filename) !== 1
            || preg_match('/[\p{C}\/\\\\]/u', $filename) === 1
            || $filename === '.'
            || $filename === '..'
            || preg_match('/\.pdf\z/i', $filename) !== 1
        ) {
            throw new ProtectedFileSourceImportException(
                ProtectedFileSourceImportException::INVALID_FILENAME,
            );
        }
    }

    /** @param resource $stream */
    private function writeAll(mixed $stream, string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $written = fwrite($stream, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new ProtectedFileSourceImportException(
                    ProtectedFileSourceImportException::SOURCE_UNSTABLE,
                );
            }
            $offset += $written;
        }
    }

    /** @param array<string|int, mixed> $before @param array<string|int, mixed> $after */
    private function sameFileStat(array $before, array $after): bool
    {
        foreach (['dev', 'ino', 'size', 'mtime'] as $field) {
            if (($before[$field] ?? null) !== ($after[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{source: array<string, int|string|null>|null}
     */
    private function materialState(int $materialId): array
    {
        $material = LessonMaterial::query()->find($materialId);
        if (! $material) {
            throw new ProtectedFileSourceImportException(
                ProtectedFileSourceImportException::MATERIAL_NOT_FOUND,
            );
        }
        if ($material->kind !== LessonMaterial::KIND_PDF) {
            throw new ProtectedFileSourceImportException(
                ProtectedFileSourceImportException::MATERIAL_NOT_PDF,
            );
        }

        $source = LessonMaterialFile::query()
            ->where('lesson_material_id', $materialId)
            ->first();

        return ['source' => $this->sourceSnapshot($source)];
    }

    /**
     * @param  array{stream: resource, sha256: string, size: int, mime: string, filename: string}  $verified
     * @param  array<string, int|string|null>|null  $source
     * @param  array{disk: string, prefix: string, max_source_bytes: int}  $configuration
     * @param  array{sha256?: string, size?: int, mime?: string}|null  $sourceExpectation
     */
    private function plan(
        int $materialId,
        array $verified,
        ?array $source,
        array $configuration,
        ?array $sourceExpectation,
    ): string {
        $payload = [
            'version' => self::PLAN_VERSION,
            'material_id' => $materialId,
            'source_sha256' => $verified['sha256'],
            'source_size_bytes' => $verified['size'],
            'source_mime_type' => $verified['mime'],
            'download_filename' => $verified['filename'],
            'current_source' => $source,
            'source_disk' => $configuration['disk'],
            'source_prefix' => $configuration['prefix'],
        ];
        if ($sourceExpectation !== null) {
            $payload['source_expectation'] = $sourceExpectation;
        }

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param  array<string, int|string|null>|null  $source
     * @param  array{stream: resource, sha256: string, size: int, mime: string, filename: string}  $verified
     */
    private function isNoChange(?array $source, array $verified): bool
    {
        return $source !== null
            && $source['source_sha256'] === $verified['sha256']
            && $source['source_size_bytes'] === $verified['size']
            && $source['source_mime_type'] === $verified['mime']
            && $source['download_filename'] === $verified['filename'];
    }

    /**
     * @param  array{stream: resource, sha256: string, size: int, mime: string, filename: string}  $verified
     * @param  array<string, int|string|null>|null  $expectedSource
     * @param  array{disk: string, prefix: string, max_source_bytes: int}  $configuration
     * @return array{status: string, mode: string, change: string, cleanup: string}
     */
    private function apply(
        int $materialId,
        array $verified,
        ?array $expectedSource,
        array $configuration,
        string $change,
    ): array {
        $key = sprintf(
            '%s/%d/%s.pdf',
            $configuration['prefix'],
            $materialId,
            Str::uuid(),
        );
        $disk = Storage::disk($configuration['disk']);

        try {
            rewind($verified['stream']);
            $written = $disk->put($key, $verified['stream'], [
                'visibility' => 'private',
                'ContentType' => 'application/pdf',
            ]);
        } catch (Throwable) {
            $this->failAfterWriteAttempt(
                $disk,
                $key,
                ProtectedFileSourceImportException::STORAGE_WRITE_FAILED,
            );
        }
        if (! $written) {
            $this->failAfterWriteAttempt(
                $disk,
                $key,
                ProtectedFileSourceImportException::STORAGE_WRITE_FAILED,
            );
        }

        if (! $this->readBackMatches($disk, $key, $verified)) {
            $this->failAfterWriteAttempt(
                $disk,
                $key,
                ProtectedFileSourceImportException::STORAGE_INTEGRITY_FAILED,
            );
        }

        try {
            DB::transaction(function () use ($materialId, $key, $verified, $expectedSource): void {
                $material = LessonMaterial::query()->lockForUpdate()->find($materialId);
                if (! $material || $material->kind !== LessonMaterial::KIND_PDF) {
                    throw new ProtectedFileSourceImportException(
                        ProtectedFileSourceImportException::REGISTRY_CONFLICT,
                    );
                }

                $source = LessonMaterialFile::query()
                    ->where('lesson_material_id', $materialId)
                    ->lockForUpdate()
                    ->first();
                if ($this->sourceSnapshot($source) !== $expectedSource) {
                    throw new ProtectedFileSourceImportException(
                        ProtectedFileSourceImportException::REGISTRY_CONFLICT,
                    );
                }

                $attributes = [
                    'source_object_key' => $key,
                    'source_sha256' => $verified['sha256'],
                    'source_size_bytes' => $verified['size'],
                    'source_mime_type' => $verified['mime'],
                    'download_filename' => $verified['filename'],
                ];

                if ($source) {
                    $source->forceFill($attributes)->save();

                    return;
                }

                LessonMaterialFile::query()->create([
                    'lesson_material_id' => $materialId,
                    ...$attributes,
                ]);
            });
        } catch (Throwable $exception) {
            $commitState = $this->commitState($materialId, $key, $verified);
            if ($commitState === 'ambiguous') {
                throw new ProtectedFileSourceImportException(
                    ProtectedFileSourceImportException::CLEANUP_DEFERRED,
                );
            }
            if ($commitState === 'not_committed') {
                $this->cleanupOrFail($disk, $key);

                if ($exception instanceof ProtectedFileSourceImportException) {
                    throw $exception;
                }

                throw new ProtectedFileSourceImportException(
                    ProtectedFileSourceImportException::REGISTRY_CONFLICT,
                );
            }
        }

        $cleanup = 'not_needed';
        $oldKey = $expectedSource['source_object_key'] ?? null;
        if (is_string($oldKey) && $oldKey !== $key) {
            try {
                $oldKeyIsReferenced = LessonMaterialFile::query()
                    ->where('source_object_key', $oldKey)
                    ->exists();
            } catch (Throwable) {
                $oldKeyIsReferenced = true;
            }
            if (
                ! $this->isManagedKey($oldKey, $configuration['prefix'], $materialId)
                || $oldKeyIsReferenced
            ) {
                $cleanup = 'deferred';
            } else {
                try {
                    $cleanup = $disk->delete($oldKey) ? 'done' : 'deferred';
                } catch (Throwable) {
                    $cleanup = 'deferred';
                }
            }
        }

        return [
            'status' => 'applied',
            'mode' => 'apply',
            'change' => $change,
            'cleanup' => $cleanup,
        ];
    }

    /**
     * @param  array{stream: resource, sha256: string, size: int, mime: string, filename: string}  $verified
     */
    private function readBackMatches(FilesystemAdapter $disk, string $key, array $verified): bool
    {
        try {
            $stream = $disk->readStream($key);
        } catch (Throwable) {
            return false;
        }
        if (! is_resource($stream)) {
            return false;
        }

        try {
            $hash = hash_init('sha256');
            $size = 0;
            $header = '';

            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    return false;
                }
                if ($chunk === '') {
                    continue;
                }
                if (strlen($header) < 5) {
                    $header .= substr($chunk, 0, 5 - strlen($header));
                }
                $size += strlen($chunk);
                if ($size > $verified['size']) {
                    return false;
                }
                hash_update($hash, $chunk);
            }

            return $size === $verified['size']
                && $header === '%PDF-'
                && hash_equals($verified['sha256'], hash_final($hash));
        } finally {
            fclose($stream);
        }
    }

    private function failAfterWriteAttempt(FilesystemAdapter $disk, string $key, string $errorCode): never
    {
        $this->cleanupOrFail($disk, $key);

        throw new ProtectedFileSourceImportException($errorCode);
    }

    private function cleanupOrFail(FilesystemAdapter $disk, string $key): void
    {
        try {
            if (! $disk->delete($key)) {
                throw new ProtectedFileSourceImportException(
                    ProtectedFileSourceImportException::CLEANUP_DEFERRED,
                );
            }
        } catch (ProtectedFileSourceImportException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ProtectedFileSourceImportException(
                ProtectedFileSourceImportException::CLEANUP_DEFERRED,
            );
        }
    }

    private function isManagedKey(string $key, string $prefix, int $materialId): bool
    {
        $expectedPrefix = "{$prefix}/{$materialId}/";
        if (! str_starts_with($key, $expectedPrefix)) {
            return false;
        }

        $filename = substr($key, strlen($expectedPrefix));

        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\.pdf\z/i', $filename) === 1;
    }

    /**
     * @param  array{stream: resource, sha256: string, size: int, mime: string, filename: string}  $verified
     * @return 'committed'|'not_committed'|'ambiguous'
     */
    private function commitState(int $materialId, string $key, array $verified): string
    {
        try {
            $source = LessonMaterialFile::query()
                ->where('lesson_material_id', $materialId)
                ->first();
        } catch (Throwable) {
            return 'ambiguous';
        }

        if (! $source || $source->source_object_key !== $key) {
            return 'not_committed';
        }

        return hash_equals((string) $source->source_sha256, $verified['sha256'])
            && (int) $source->source_size_bytes === $verified['size']
            && $source->source_mime_type === $verified['mime']
            && $source->download_filename === $verified['filename']
                ? 'committed'
                : 'ambiguous';
    }

    /** @return array<string, int|string|null>|null */
    private function sourceSnapshot(?LessonMaterialFile $source): ?array
    {
        if (! $source) {
            return null;
        }

        return [
            'id' => (int) $source->id,
            'source_object_key' => (string) $source->source_object_key,
            'source_sha256' => (string) $source->source_sha256,
            'source_size_bytes' => (int) $source->source_size_bytes,
            'source_mime_type' => (string) $source->source_mime_type,
            'download_filename' => (string) $source->download_filename,
            'updated_at' => $source->getRawOriginal('updated_at'),
        ];
    }
}
