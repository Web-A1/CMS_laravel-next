<?php

namespace App\Services\Articles;

use App\Models\ArticleMediaAsset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ArticleMediaVariants
{
    public function cleanupWorkspaces(): int
    {
        $count = 0;
        foreach (array_slice(glob(storage_path('app/private/article-image-work/*'), GLOB_ONLYDIR) ?: [], 0, 100) as $directory) {
            if (is_link($directory) || ! Str::isUuid(basename($directory)) || filemtime($directory) > time() - 3600) {
                continue;
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                if (is_file($file) && ! is_link($file)) {
                    unlink($file);
                }
            }
            if (count(glob($directory.'/*') ?: []) === 0) {
                rmdir($directory);
                $count++;
            }
        }

        return $count;
    }

    public function process(int $id, ?int $generation = null): bool
    {
        $directory = storage_path('app/private/article-image-work/'.Str::uuid());
        $expectedGeneration = $generation;
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Cannot prepare article image workspace.');
        }
        try {
            $snapshot = app(ArticleMediaGuard::class)->run(function () use ($id, $generation, $directory) {
                $asset = ArticleMediaAsset::query()->lockForUpdate()->findOrFail($id);
                if ($asset->status !== 'active' || ($generation !== null && $asset->generation !== $generation)) {
                    return null;
                }
                if ($asset->processing_status === 'ready') {
                    $this->verify($asset);

                    return null;
                }
                $source = Storage::disk($asset->disk)->get($asset->key);
                if (strlen($source) !== $asset->bytes || hash('sha256', $source) !== $asset->sha256) {
                    throw new RuntimeException('Article original checksum mismatch.');
                }
                file_put_contents($directory.'/source', $source);

                return $asset;
            });
            if (! $snapshot) {
                return false;
            }
            $expectedGeneration = $snapshot->generation;
            $output = app(ArticleImageProcessor::class)->variants($directory.'/source', $snapshot->mime, $directory);

            return app(ArticleMediaGuard::class)->run(function () use ($snapshot, $output): bool {
                $asset = ArticleMediaAsset::query()->lockForUpdate()->findOrFail($snapshot->id);
                if ($asset->status !== 'active' || $asset->generation !== $snapshot->generation) {
                    return false;
                }
                if ($asset->processing_status === 'ready') {
                    $this->verify($asset);

                    return false;
                }
                $storage = Storage::disk($asset->disk);
                $manifest = [];
                foreach ($output['variants'] as $variant) {
                    $key = 'article-media/variants/'.$asset->sha256.'/v1/'.$variant['width'].'.'.$variant['format'];
                    if (! $storage->exists($key)) {
                        $stream = fopen($variant['file'], 'rb');
                        try {
                            if (! $storage->put($key, $stream, ['visibility' => 'public', 'ContentType' => $variant['mime'], 'CacheControl' => 'public, max-age=31536000, immutable'])) {
                                throw new RuntimeException('Cannot write article variant.');
                            }
                        } finally {
                            fclose($stream);
                        }
                    }
                    $remote = $storage->get($key);
                    if (strlen($remote) !== $variant['bytes'] || hash('sha256', $remote) !== $variant['sha256']) {
                        throw new RuntimeException('Remote article variant checksum mismatch.');
                    }
                    unset($variant['file']);
                    $manifest[] = $variant + ['key' => $key];
                }
                $asset->update(['variants' => $manifest, 'width' => $output['width'], 'height' => $output['height'], 'processing_status' => 'ready', 'processing_error' => null]);
                app(ArticleMediaLifecycle::class)->event($asset, $output['passthrough'] ? 'passthrough' : 'variants_ready', null, ['variants' => count($manifest)]);

                return true;
            });
        } catch (Throwable $e) {
            app(ArticleMediaGuard::class)->run(function () use ($id, $expectedGeneration, $e): void {
                $asset = ArticleMediaAsset::query()->lockForUpdate()->find($id);
                if ($asset && $asset->status === 'active' && ($expectedGeneration === null || $asset->generation === $expectedGeneration)) {
                    $asset->update(['processing_status' => 'failed', 'processing_error' => mb_substr($e->getMessage(), 0, 2000)]);
                    app(ArticleMediaLifecycle::class)->event($asset, 'processing_failed', null, ['error' => mb_substr($e->getMessage(), 0, 2000)]);
                }
            });
            throw $e;
        } finally {
            foreach (glob($directory.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function verify(ArticleMediaAsset $asset): void
    {
        if ($asset->processing_status !== 'ready') {
            throw new RuntimeException('Article variants are not ready.');
        }
        $storage = Storage::disk($asset->disk);
        foreach (array_merge([['key' => $asset->key, 'sha256' => $asset->sha256, 'bytes' => $asset->bytes]], $asset->variants ?? []) as $file) {
            if (! $storage->exists($file['key'])) {
                throw new RuntimeException('Missing article media output '.$file['key']);
            }
            $bytes = $storage->get($file['key']);
            if (strlen($bytes) !== $file['bytes'] || hash('sha256', $bytes) !== $file['sha256']) {
                throw new RuntimeException('Article media verification failed '.$file['key']);
            }
        }
    }
}
