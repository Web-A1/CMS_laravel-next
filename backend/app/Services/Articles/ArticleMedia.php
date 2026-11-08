<?php

namespace App\Services\Articles;

use App\Jobs\ProcessArticleImage;
use App\Models\ArticleMediaAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;

final class ArticleMedia
{
    public function prime(iterable $entries): void
    {
        $paths = [];
        foreach ($entries as $entry) {
            $paths[] = $entry->cover_path;
            $paths[] = $entry->relationLoaded('author') ? $entry->author?->photo_path : null;
            $paths[] = $entry->relationLoaded('author') ? $entry->author?->avatar_path : null;
            preg_match_all('~/storage/(article-media/[a-f0-9]{64}\.(?:jpg|jpeg|png|gif|webp|avif|pdf))\b~', $entry->body ?? '', $matches);
            array_push($paths, ...$matches[1]);
            foreach ($entry->payload['attachments'] ?? [] as $attachment) {
                $paths[] = preg_replace('~^/storage/~', '', $attachment['url']);
            }
        }
        $this->publicUrls($paths);
    }

    public function publicUrls(array $paths): array
    {
        $paths = array_values(array_unique(array_filter($paths)));
        $cache = request()->attributes->get('articles.mediaUrls', []);
        $missing = array_values(array_filter($paths, fn ($path) => ! array_key_exists($path, $cache)));
        if ($missing) {
            $assets = ArticleMediaAsset::query()->whereIn('filename', array_map('basename', $missing))->where('status', 'active')->get()->keyBy('filename');
            $assetCache = request()->attributes->get('articles.mediaAssets', []);
            foreach ($missing as $path) {
                $asset = $assets[basename($path)] ?? null;
                $assetCache[$path] = $asset;
                $cache[$path] = $asset && config('filesystems.disks.'.$asset->disk.'.driver') === 's3'
                    ? Storage::disk($asset->disk)->url($asset->key) : '/storage/'.$path;
            }
            request()->attributes->set('articles.mediaUrls', $cache);
            request()->attributes->set('articles.mediaAssets', $assetCache);
        }

        return array_intersect_key($cache, array_flip($paths));
    }

    public function publicUrl(?string $path): ?string
    {
        return $path ? $this->publicUrls([$path])[$path] : null;
    }

    public function publicHtml(string $html): string
    {
        $html = app(ArticleHtml::class)->sanitize($html);
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="article-picture-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $paths = [];
        $normalizer = app(ArticleMediaLifecycle::class);
        foreach (['img' => 'src', 'a' => 'href'] as $tag => $attribute) {
            foreach ($dom->getElementsByTagName($tag) as $node) {
                $path = $normalizer->ownedPath($node->getAttribute($attribute));
                if ($path && preg_match('~^article-media/[a-f0-9]{64}\.(?:jpg|jpeg|png|gif|webp|avif|pdf)$~D', $path)) {
                    $paths[] = $path;
                }
            }
        }
        $resolved = $this->publicUrls($paths);
        foreach ($dom->getElementsByTagName('a') as $link) {
            $path = $normalizer->ownedPath($link->getAttribute('href'));
            if ($path && isset($resolved[$path])) {
                $link->setAttribute('href', $resolved[$path]);
            }
        }
        foreach (iterator_to_array($dom->getElementsByTagName('img')) as $image) {
            $path = $normalizer->ownedPath($image->getAttribute('src'));
            if (! $path || ! isset($resolved[$path])) {
                continue;
            }
            $original = $resolved[$path];
            $image->setAttribute('src', $original);
            $image->setAttribute('data-full-src', $original);
            $variants = $this->variants($path);
            if (! $variants) {
                continue;
            }
            $picture = $dom->createElement('picture');
            $image->parentNode->insertBefore($picture, $image);
            foreach (['avif', 'webp'] as $format) {
                $set = array_values(array_filter($variants, fn ($variant) => $variant['format'] === $format));
                if (! $set) {
                    continue;
                }
                $source = $dom->createElement('source');
                $source->setAttribute('type', 'image/'.$format);
                $source->setAttribute('srcset', implode(', ', array_map(fn ($variant) => $variant['url'].' '.$variant['width'].'w', $set)));
                $source->setAttribute('sizes', '(max-width: 768px) 100vw, 960px');
                $picture->appendChild($source);
            }
            $picture->appendChild($image);
        }
        $root = $dom->getElementById('article-picture-root');
        return $root ? implode('', array_map(fn ($child) => $dom->saveHTML($child), iterator_to_array($root->childNodes))) : $html;
    }

    public function variants(?string $path): array
    {
        if (! $path) {
            return [];
        }
        $this->publicUrls([$path]);
        $asset = request()->attributes->get('articles.mediaAssets', [])[$path] ?? null;
        if (! $asset || $asset->processing_status !== 'ready') {
            return [];
        }

        return array_map(fn ($variant) => ['url' => config('filesystems.disks.'.$asset->disk.'.driver') === 's3' ? Storage::disk($asset->disk)->url($variant['key']) : '/storage/'.$variant['key'], 'format' => $variant['format'], 'width' => $variant['width'], 'height' => $variant['height'], 'bytes' => $variant['bytes']], $asset->variants ?? []);
    }

    public function ingest(string $source, array $origin): ArticleMediaAsset
    {
        return app(ArticleMediaGuard::class)->run(fn () => $this->ingestGuarded($source, $origin));
    }

    private function ingestGuarded(string $source, array $origin): ArticleMediaAsset
    {
        $sha = hash_file('sha256', $source);
        $bytes = filesize($source);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/gif' => 'gif', 'application/pdf' => 'pdf'][$mime] ?? throw new RuntimeException('Unsupported article media MIME '.$mime);
        $filename = $sha.'.'.$extension;
        $existing = ArticleMediaAsset::query()->where('filename', $filename)->lockForUpdate()->first();
        if ($existing) {
            if ($existing->status === 'deleting') {
                throw ValidationException::withMessages(['cover_path' => 'Файл удаляется. Дождитесь завершения и загрузите его заново.']);
            }
            if ($existing->status === 'deleted') {
                if (($origin['kind'] ?? null) !== 'editor') {
                    throw new RuntimeException('Deleted article media cannot be revived by import/backfill.');
                }
                app(ArticleEditor::class)->actor();
                $existing->update(['status' => 'active', 'generation' => $existing->generation + 1, 'processing_status' => 'pending', 'variants' => [], 'deleted_at' => null, 'lease_at' => now(), 'processing_error' => null]);
            } else {
                if ($existing->sha256 !== $sha || $existing->bytes !== $bytes || $existing->mime !== $mime || ! Storage::disk($existing->disk)->exists($existing->key) || hash('sha256', Storage::disk($existing->disk)->get($existing->key)) !== $sha) {
                    throw new RuntimeException('Article media registry conflict '.$filename);
                }
                if (($origin['kind'] ?? null) === 'editor') {
                    $existing->update(['lease_at' => now()]);
                }

                return $existing;
            }
        }
        $disk = $existing?->disk ?? config('articles.media_disk');
        if (! in_array($disk, ['public', 'article-media-s3'], true)) {
            throw new RuntimeException('Unsupported article media disk');
        }
        $key = 'article-media/'.$filename;
        $storage = Storage::disk($disk);
        if ($storage->exists($key)) {
            if (hash('sha256', $storage->get($key)) !== $sha) {
                throw new RuntimeException('Article media object conflict '.$key);
            }
        } else {
            $stream = fopen($source, 'rb');
            try {
                if (! $storage->put($key, $stream, ['visibility' => 'public', 'ContentType' => $mime, 'CacheControl' => 'public, max-age=31536000, immutable'])) {
                    throw new RuntimeException('Cannot write article media '.$key);
                }
            } finally {
                fclose($stream);
            }
        }

        $asset = $existing ?? ArticleMediaAsset::query()->create(['filename' => $filename, 'disk' => $disk, 'key' => $key, 'sha256' => $sha, 'bytes' => $bytes, 'mime' => $mime, 'origin' => $origin, 'created_at' => now(), 'lease_at' => ($origin['kind'] ?? null) === 'editor' ? now() : null]);
        $asset = $asset->fresh();
        app(ArticleMediaLifecycle::class)->event($asset, $existing ? 'revived' : 'ingested', $origin['actorId'] ?? null);

        return $asset;
    }

    public function upload(TemporaryUploadedFile $file): string
    {
        $actor = app(ArticleEditor::class)->actor();
        $asset = $this->ingest($file->getRealPath(), ['kind' => 'editor', 'actorId' => $actor->id, 'name' => $file->getClientOriginalName()]);
        DB::afterCommit(function () use ($file): void {
            // RichEditor does not run BaseFileUpload's post-callback cleanup.
            // Keep the source available until the registry transaction is durable.
            if ($file->exists() && ! $file->delete()) {
                throw new RuntimeException('Cannot remove committed article upload temporary file.');
            }
            $disk = Storage::disk(FileUploadConfiguration::disk());
            $metadata = FileUploadConfiguration::path($file->getFilename(), false).'.json';
            if ($disk->exists($metadata) && ! $disk->delete($metadata)) {
                throw new RuntimeException('Cannot remove article upload temporary metadata.');
            }
        });
        try {
            ProcessArticleImage::dispatch($asset->id, $asset->generation)->afterCommit();
        } catch (\Throwable $e) {
            report($e);
        }

        return 'article-media/'.$asset->filename;
    }

    public function assertRegistered(?string $path): void
    {
        if ($path && (! preg_match('~^article-media/[a-f0-9]{64}\.(jpg|png|gif|webp|avif|pdf)$~D', $path) || ! ArticleMediaAsset::query()->where('filename', basename($path))->where('status', 'active')->exists())) {
            throw ValidationException::withMessages(['cover_path' => 'Медиа не зарегистрировано. Загрузите файл через редактор.']);
        }
    }
}
