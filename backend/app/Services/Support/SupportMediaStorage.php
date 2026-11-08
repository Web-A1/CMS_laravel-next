<?php

namespace App\Services\Support;

use Aws\Exception\AwsException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class SupportMediaStorage
{
    public const MAX_BYTES = 25 * 1024 * 1024;

    public function identity(string $disk): array
    {
        $filesystem = Storage::disk($disk);
        if ($filesystem instanceof AwsS3V3Adapter) {
            $config = config('filesystems.disks.'.$disk);
            $endpoint = parse_url((string) $filesystem->getClient()->getEndpoint());

            return ['disk' => $disk, 'driver' => 's3', 'endpoint' => ($endpoint['scheme'] ?? 'https').'://'.($endpoint['host'] ?? '').(isset($endpoint['port']) ? ':'.$endpoint['port'] : '').($endpoint['path'] ?? ''), 'bucket' => $config['bucket'], 'root' => trim($config['root'] ?? '', '/')];
        }
        $root = realpath($filesystem->path(''));
        if (! $root || ! is_dir($root)) {
            throw new RuntimeException('MEDIA_ROOT_UNAVAILABLE');
        }

        return ['disk' => $disk, 'driver' => 'local', 'host' => gethostname(), 'root' => $root];
    }

    public function verifyIdentity(array $identity): string
    {
        foreach (array_unique([config('support.disk'), ...config('support.media_disks', [])]) as $disk) {
            try {
                if ($this->sameIdentity($identity, $this->identity($disk))) {
                    return $disk;
                }
            } catch (\Throwable) {
                // A declared historical domain may currently be unavailable.
            }
        }
        throw new RuntimeException('MEDIA_STORAGE_IDENTITY_CHANGED');
    }

    public function sameIdentity(array $first, array $second): bool
    {
        unset($first['disk'], $second['disk']);
        ksort($first);
        ksort($second);

        return $first === $second;
    }

    public function put(array $identity, string $key, string $path, string $reservation, string $slot): array
    {
        $disk = $this->verifyIdentity($identity);
        if (! preg_match('/\Av1\/[a-f0-9-]{36}\/[a-zA-Z0-9._\/-]+\z/D', $key)) {
            throw new RuntimeException('MEDIA_WRITE_NAMESPACE_INVALID');
        }
        $this->key($key);
        $bytes = filesize($path);
        if ($bytes === false || $bytes < 1 || $bytes > self::MAX_BYTES) {
            throw new RuntimeException('MEDIA_OBJECT_SIZE_INVALID');
        }
        $hash = hash_file('sha256', $path);
        $stream = fopen($path, 'rb');
        if (! $stream) {
            throw new RuntimeException('MEDIA_SOURCE_UNAVAILABLE');
        }
        try {
            if ($identity['driver'] === 's3') {
                Storage::disk($disk)->getClient()->putObject([
                    'Bucket' => $identity['bucket'], 'Key' => $this->physicalKey($identity, $key),
                    'Body' => $stream, 'ContentLength' => $bytes, '@retries' => 0,
                    '@http' => ['connect_timeout' => 5, 'timeout' => 60],
                    'Metadata' => ['support-reservation' => $reservation, 'support-slot' => $slot, 'sha256' => $hash],
                ]);
            } else {
                $this->localPath($identity, $key);
                if (! Storage::disk($disk)->put($key, $stream)) {
                    throw new RuntimeException('MEDIA_WRITE_FAILED');
                }
            }
        } finally {
            fclose($stream);
        }

        return ['bytes' => $bytes, 'sha256' => $hash];
    }

    public function read(array $identity, string $key, ?string $version = null): string
    {
        $disk = $this->verifyIdentity($identity);
        $this->key($key);
        if ($identity['driver'] === 'local') {
            $path = $this->localPath($identity, $key);
            if (! is_file($path) || filesize($path) > self::MAX_BYTES) {
                throw new RuntimeException('MEDIA_READ_UNAVAILABLE');
            }

            return file_get_contents($path);
        }
        $result = Storage::disk($disk)->getClient()->getObject([
            'Bucket' => $identity['bucket'], 'Key' => $this->physicalKey($identity, $key),
            '@retries' => 0, '@http' => ['connect_timeout' => 5, 'timeout' => 20],
            ...($version !== null ? ['VersionId' => $version] : []),
        ]);
        $body = $result['Body'];
        if (($result['ContentLength'] ?? self::MAX_BYTES + 1) > self::MAX_BYTES) {
            $body->close();
            throw new RuntimeException('MEDIA_OBJECT_SIZE_INVALID');
        }
        try {
            $bytes = $body->read(self::MAX_BYTES + 1);
            if (strlen($bytes) > self::MAX_BYTES || ! $body->eof()) {
                throw new RuntimeException('MEDIA_OBJECT_SIZE_INVALID');
            }

            return $bytes;
        } finally {
            $body->close();
        }
    }

    /** Complete, bounded inventory. A failed page never becomes an empty inventory. */
    public function inventory(array $identity, string $prefix): array
    {
        $disk = $this->verifyIdentity($identity);
        $this->key(rtrim($prefix, '/'));
        if ($identity['driver'] === 'local') {
            $start = $this->localPath($identity, rtrim($prefix, '/'));
            $entries = [];
            if (file_exists($start)) {
                $iterator = is_dir($start)
                    ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($start, \FilesystemIterator::SKIP_DOTS))
                    : [new \SplFileInfo($start)];
                foreach ($iterator as $file) {
                    if ($file->isDir() && ! $file->isLink()) {
                        continue;
                    }
                    if (count($entries) >= 100000 || $file->isLink() || ! $file->isFile()) {
                        throw new RuntimeException('MEDIA_LOCAL_INVENTORY_UNSAFE');
                    }
                    $key = substr($file->getPathname(), strlen($identity['root']) + 1);
                    $this->localPath($identity, $key);
                    $entries[] = ['kind' => 'object', 'key' => $key, 'bytes' => $file->getSize()];
                }
            }

            return ['entries' => $entries, 'pages' => [['kind' => 'local', 'prefix' => $prefix, 'count' => count($entries)]], 'complete' => true];
        }
        $client = Storage::disk($disk)->getClient();
        $physicalPrefix = $this->physicalKey($identity, $prefix);
        $entries = [];
        $pages = [];
        $deadline = microtime(true) + 80;
        foreach (['objects', 'versions', 'uploads'] as $kind) {
            $cursor = [];
            $seen = [];
            for ($page = 0; ; $page++) {
                if ($page >= 128 || microtime(true) > $deadline) {
                    throw new RuntimeException('MEDIA_INVENTORY_LIMIT');
                }
                $arguments = ['Bucket' => $identity['bucket'], 'Prefix' => $physicalPrefix, '@retries' => 0, '@http' => ['connect_timeout' => 5, 'timeout' => 15], ...$cursor];
                $result = match ($kind) {
                    'objects' => $client->listObjectsV2([...$arguments, 'MaxKeys' => 1000]),
                    'versions' => $client->listObjectVersions([...$arguments, 'MaxKeys' => 1000]),
                    'uploads' => $client->listMultipartUploads([...$arguments, 'MaxUploads' => 1000]),
                };
                if (! is_bool($result['IsTruncated'])) {
                    throw new RuntimeException('MEDIA_INVENTORY_RESPONSE_INVALID');
                }
                $batches = match ($kind) {
                    'objects' => ['Contents' => 'object'],
                    'versions' => ['Versions' => 'version', 'DeleteMarkers' => 'marker'],
                    'uploads' => ['Uploads' => 'upload'],
                };
                $count = 0;
                foreach ($batches as $field => $entryKind) {
                    foreach ($result[$field] ?? [] as $entry) {
                        if (! isset($entry['Key']) || ! str_starts_with($entry['Key'], $physicalPrefix)) {
                            throw new RuntimeException('MEDIA_INVENTORY_RESPONSE_INVALID');
                        }
                        $key = substr($entry['Key'], $identity['root'] === '' ? 0 : strlen($identity['root']) + 1);
                        $this->key($key);
                        $item = ['kind' => $entryKind, 'key' => $key];
                        if (in_array($entryKind, ['version', 'marker'], true)) {
                            if (! is_string($entry['VersionId'] ?? null) || $entry['VersionId'] === '') {
                                throw new RuntimeException('MEDIA_VERSION_ID_INVALID');
                            }
                            $item['version'] = $entry['VersionId'];
                        }
                        if ($entryKind === 'upload') {
                            if (! is_string($entry['UploadId'] ?? null) || $entry['UploadId'] === '') {
                                throw new RuntimeException('MEDIA_UPLOAD_ID_INVALID');
                            }
                            $item['upload'] = $entry['UploadId'];
                            $item['parts'] = $this->parts($identity, $key, $entry['UploadId']);
                        }
                        $entries[] = $item;
                        $count++;
                    }
                }
                $pages[] = ['kind' => $kind, 'cursor' => $cursor, 'count' => $count, 'truncated' => $result['IsTruncated']];
                if (! $result['IsTruncated']) {
                    break;
                }
                $cursor = match ($kind) {
                    'objects' => ['ContinuationToken' => $result['NextContinuationToken']],
                    'versions' => ['KeyMarker' => $result['NextKeyMarker'], 'VersionIdMarker' => $result['NextVersionIdMarker']],
                    'uploads' => ['KeyMarker' => $result['NextKeyMarker'], 'UploadIdMarker' => $result['NextUploadIdMarker']],
                };
                if (array_filter($cursor, fn ($value) => ! is_string($value) || $value === '') !== [] || in_array(json_encode($cursor), $seen, true)) {
                    throw new RuntimeException('MEDIA_INVENTORY_CURSOR_INVALID');
                }
                $seen[] = json_encode($cursor);
            }
        }

        return ['entries' => $entries, 'pages' => $pages, 'complete' => true];
    }

    public function deleteKey(array $identity, string $key): void
    {
        $disk = $this->verifyIdentity($identity);
        $this->key($key);
        if ($identity['driver'] === 'local') {
            $path = $this->localPath($identity, $key);
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException('MEDIA_DELETE_FAILED');
            }
            clearstatcache(true, $path);
            if (file_exists($path) || is_link($path)) {
                throw new RuntimeException('MEDIA_DELETE_UNCONFIRMED');
            }

            return;
        }
        $client = Storage::disk($disk)->getClient();
        $entries = array_values(array_filter($this->inventory($identity, $key)['entries'], fn ($entry) => $entry['key'] === $key));
        $hasVersions = false;
        foreach ($entries as $entry) {
            $arguments = ['Bucket' => $identity['bucket'], 'Key' => $this->physicalKey($identity, $key), '@retries' => 0, '@http' => ['connect_timeout' => 5, 'timeout' => 15]];
            if ($entry['kind'] === 'upload') {
                try {
                    $client->abortMultipartUpload([...$arguments, 'UploadId' => $entry['upload']]);
                } catch (AwsException $exception) {
                    if ($exception->getAwsErrorCode() !== 'NoSuchUpload') {
                        throw $exception;
                    }
                }
                if ($this->parts($identity, $key, $entry['upload']) !== []) {
                    throw new RuntimeException('MEDIA_MULTIPART_DELETE_UNCONFIRMED');
                }
            } elseif (in_array($entry['kind'], ['version', 'marker'], true)) {
                $hasVersions = true;
                $client->deleteObject([...$arguments, 'VersionId' => $entry['version']]);
            }
        }
        if (! $hasVersions && array_filter($entries, fn ($entry) => $entry['kind'] === 'object') !== []) {
            // An explicit null version avoids creating a fresh delete marker.
            $client->deleteObject(['Bucket' => $identity['bucket'], 'Key' => $this->physicalKey($identity, $key), 'VersionId' => 'null', '@retries' => 0, '@http' => ['connect_timeout' => 5, 'timeout' => 15]]);
        }
        if (array_filter($this->inventory($identity, $key)['entries'], fn ($entry) => $entry['key'] === $key) !== []) {
            throw new RuntimeException('MEDIA_DELETE_UNCONFIRMED');
        }
    }

    private function parts(array $identity, string $key, string $upload): array
    {
        $disk = $this->verifyIdentity($identity);
        $parts = [];
        $marker = 0;
        $deadline = microtime(true) + 80;
        for ($page = 0; $page < 128; $page++) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('MEDIA_PARTS_LIMIT');
            }
            try {
                $result = Storage::disk($disk)->getClient()->listParts([
                    'Bucket' => $identity['bucket'], 'Key' => $this->physicalKey($identity, $key),
                    'UploadId' => $upload, 'PartNumberMarker' => $marker, 'MaxParts' => 1000,
                    '@retries' => 0, '@http' => ['connect_timeout' => 5, 'timeout' => 15],
                ]);
            } catch (AwsException $exception) {
                if ($exception->getAwsErrorCode() === 'NoSuchUpload') {
                    return [];
                }
                throw $exception;
            }
            if (! is_bool($result['IsTruncated'])) {
                throw new RuntimeException('MEDIA_PARTS_RESPONSE_INVALID');
            }
            foreach ($result['Parts'] ?? [] as $part) {
                if (! isset($part['PartNumber'], $part['Size']) || $part['PartNumber'] <= $marker) {
                    throw new RuntimeException('MEDIA_PARTS_RESPONSE_INVALID');
                }
                $parts[] = ['number' => $part['PartNumber'], 'bytes' => $part['Size']];
            }
            if (! $result['IsTruncated']) {
                return $parts;
            }
            $next = $result['NextPartNumberMarker'];
            if (! is_int($next) || $next <= $marker) {
                throw new RuntimeException('MEDIA_PARTS_CURSOR_INVALID');
            }
            $marker = $next;
        }
        throw new RuntimeException('MEDIA_PARTS_LIMIT');
    }

    private function physicalKey(array $identity, string $key): string
    {
        return ($identity['root'] === '' ? '' : $identity['root'].'/').$key;
    }

    private function key(string $key): void
    {
        if ($key === '' || str_starts_with($key, '/') || str_contains($key, '//') || str_contains($key, '\\') || preg_match('/[\x00-\x1f]/', $key) || array_intersect(explode('/', $key), ['.', '..'])) {
            throw new RuntimeException('MEDIA_KEY_UNSAFE');
        }
    }

    private function localPath(array $identity, string $key): string
    {
        $this->key($key);
        $path = $identity['root'];
        foreach (explode('/', $key) as $segment) {
            $path .= '/'.$segment;
            if (is_link($path)) {
                throw new RuntimeException('MEDIA_SYMLINK_UNSAFE');
            }
        }

        return $path;
    }
}
