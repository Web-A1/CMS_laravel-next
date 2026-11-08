<?php

namespace App\Services\Reviews;

use App\Models\Review;
use App\Models\ReviewEvidence;
use App\Models\ReviewImageAsset;
use App\Models\ReviewImageMigration;
use App\Models\ReviewMedia;
use App\Models\ReviewSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class ExistingReviewImages
{
    public function __construct(private readonly ReviewImageAssets $assets) {}

    public function inventory(): array
    {
        $items = [];
        foreach (ReviewMedia::orderBy('id')->get() as $media) {
            if ($this->isLegacyUrl($media->url)) {
                $this->add($items, $media->review_id, $media->url, ['table' => 'review_media', 'id' => $media->id, 'before' => ['url' => $media->url]]);
            }
        }
        foreach (ReviewEvidence::where('is_public', true)->whereHas('review', fn ($query) => $query->where('media_managed', false))
            ->whereIn('type', ['screenshot', 'image'])->orderBy('id')->get() as $evidence) {
            $url = $evidence->storage_url ?: ($evidence->file_path ? '/storage/'.$evidence->file_path : '');
            if ($this->isLegacyUrl($url)) {
                $this->add($items, $evidence->review_id, $url, ['table' => 'review_evidence', 'id' => $evidence->id,
                    'before' => ['storage_url' => $evidence->storage_url, 'file_path' => $evidence->file_path, 'is_public' => true, 'type' => $evidence->type]]);
            }
        }

        return array_values($items);
    }

    private function add(array &$items, int $reviewId, string $url, array $reference): void
    {
        $key = hash('sha256', $reviewId.':'.$url);
        $items[$key] ??= ['source_key' => $key, 'review_id' => $reviewId, 'source_url' => $url, 'references' => []];
        $items[$key]['references'][] = $reference;
    }

    public function isLegacyUrl(string $url): bool
    {
        return (bool) preg_match('~^/(?:images/reviews|storage/reviews)/(?:[a-zA-Z0-9_@.-]+/)*[a-zA-Z0-9_@.-]+\.(?:jpe?g|png|webp|avif)$~D', $url)
            && ! in_array('..', explode('/', $url), true) && ! in_array('.', explode('/', $url), true);
    }

    public function sourcePath(string $url, bool $mustExist = true): string
    {
        if (! $this->isLegacyUrl($url)) {
            throw new RuntimeException('Недопустимый исходный путь.');
        }
        $frontend = str_starts_with($url, '/images/');
        $root = $frontend ? config('review-media.frontend_public').'/images/reviews' : Storage::disk('public')->path('reviews');
        $relative = substr($url, strlen($frontend ? '/images/reviews/' : '/storage/reviews/'));
        $path = $root.'/'.$relative;
        $realRoot = realpath($root);
        $real = realpath($path);
        if (! $mustExist && $realRoot && ! file_exists($path) && ! is_link($path)) {
            // Validate existing parent directories too: a missing leaf must not hide a symlink escape.
            $parent = dirname($path);
            while (! file_exists($parent)) {
                $parent = dirname($parent);
            }
            if (realpath($parent) === $realRoot || str_starts_with((string) realpath($parent), $realRoot.DIRECTORY_SEPARATOR)) {
                return $path;
            }
        }
        if (! $realRoot || ! $real || ! is_file($real) || ! str_starts_with($real, $realRoot.DIRECTORY_SEPARATOR) || is_link($path)) {
            throw new RuntimeException('Исходник отсутствует или выходит за разрешённый каталог: '.$url);
        }

        return $real;
    }

    public function migrate(array $item): ReviewImageMigration
    {
        $path = $this->sourcePath($item['source_url']);
        $sha = hash_file('sha256', $path);
        $entry = ReviewImageMigration::firstOrCreate(['source_key' => $item['source_key']], [
            'review_id' => $item['review_id'], 'source_url' => $item['source_url'], 'source_sha256' => $sha, 'references' => $item['references'],
        ]);
        if ($entry->source_sha256 !== $sha || $entry->references !== $item['references']) {
            throw new RuntimeException('Исходник или его привязки изменились после начала переноса.');
        }
        if ($entry->switched_at) {
            throw new RuntimeException('Перенесённая старая ссылка появилась снова. Проверьте привязки.');
        }
        if (! $entry->asset_id) {
            DB::transaction(function () use ($entry, $path): void {
                $same = ReviewImageMigration::where('review_id', $entry->review_id)->where('source_sha256', $entry->source_sha256)
                    ->whereNotNull('asset_id')->first();
                $asset = $same?->asset ?? $this->assets->stageExisting($path, $entry->review_id);
                $entry->update(['asset_id' => $asset->id]);
            });
        }
        $this->assets->process($entry->asset_id);
        $entry->refresh()->load('asset');
        $this->verifyAsset($entry->asset);
        if (hash_file('sha256', $this->sourcePath($entry->source_url)) !== $entry->source_sha256) {
            throw new RuntimeException('Исходник изменился во время обработки.');
        }
        DB::transaction(function () use ($entry): void {
            Review::whereKey($entry->review_id)->lockForUpdate()->firstOrFail();
            foreach ($entry->references as $ref) {
                $model = $ref['table'] === 'review_media' ? ReviewMedia::class : ReviewEvidence::class;
                $row = $model::whereKey($ref['id'])->lockForUpdate()->firstOrFail();
                foreach ($ref['before'] as $field => $value) {
                    if ($row->getAttribute($field) !== $value || (int) $row->review_id !== (int) $entry->review_id) {
                        throw new RuntimeException('Привязка изменилась во время обработки.');
                    }
                }
                $url = '/storage/'.$entry->asset->primary_path;
                $row->update($ref['table'] === 'review_media' ? ['url' => $url]
                    : ['storage_url' => $url, 'file_path' => null, 'mime_type' => 'image/webp']);
            }
            $entry->update(['switched_at' => now()]);
        });
        $this->syncMetadata($entry);

        return $entry;
    }

    public function syncMetadata(ReviewImageMigration $entry): void
    {
        DB::transaction(function () use ($entry): void {
            $entry->refresh()->load('asset');
            $this->verifyEntry($entry, verifyObjects: false);
            $rows = ReviewEvidence::where('review_id', $entry->review_id)
                ->where('metadata->public_review_media_url', $entry->source_url)->lockForUpdate()->get();
            $references = $entry->references;
            foreach ($rows as $row) {
                // Only the public derivative pointer moves. Original evidence paths/hashes/privacy stay intact.
                $references[] = ['table' => 'review_evidence', 'id' => $row->id, 'before' => [
                    'metadata.public_review_media_url' => $entry->source_url, 'is_public' => $row->is_public, 'type' => $row->type,
                ]];
                $metadata = $row->metadata;
                $metadata['public_review_media_url'] = $entry->asset->url();
                $row->update(['metadata' => $metadata]);
            }
            if ($rows->isNotEmpty()) {
                $entry->update(['references' => $references]);
            }
        });
    }

    public function verifyAsset(?ReviewImageAsset $asset): void
    {
        if (! $asset || $asset->status !== 'ready' || ! $asset->variants || $asset->disk === 'public') {
            throw new RuntimeException('Набор S3 ещё не готов.');
        }
        foreach ($asset->variants as $variant) {
            $bytes = Storage::disk($asset->disk)->get($variant['path']);
            if (! is_string($bytes) || strlen($bytes) !== $variant['bytes'] || ! isset($variant['sha256']) || hash('sha256', $bytes) !== $variant['sha256']) {
                throw new RuntimeException('Проверка готового объекта не пройдена: '.$variant['path']);
            }
        }
    }

    public function verifyEntry(ReviewImageMigration $entry, bool $verifyObjects = true): void
    {
        if (! $entry->switched_at || $entry->asset?->status !== 'ready' || (int) $entry->asset?->review_id !== (int) $entry->review_id) {
            throw new RuntimeException('Перенос не завершён или набор отсоединён: '.$entry->source_url);
        }
        if ($verifyObjects) {
            $this->verifyAsset($entry->asset);
        }
        foreach ($entry->references as $ref) {
            $model = $ref['table'] === 'review_media' ? ReviewMedia::class : ReviewEvidence::class;
            $row = $model::findOrFail($ref['id']);
            $expected = $ref['before'];
            if (array_key_exists('metadata.public_review_media_url', $expected)) {
                $expected['metadata.public_review_media_url'] = $entry->asset->url();
            } elseif ($ref['table'] === 'review_media') {
                $expected['url'] = '/storage/'.$entry->asset->primary_path;
            } else {
                $expected['storage_url'] = '/storage/'.$entry->asset->primary_path;
                $expected['file_path'] = null;
            }
            foreach ($expected as $field => $value) {
                if (data_get($row, $field) !== $value || (int) $row->review_id !== (int) $entry->review_id) {
                    throw new RuntimeException('Изменена перенесённая привязка: '.$entry->source_url);
                }
            }
        }
    }

    public function validatePrune(ReviewImageMigration $entry, bool $verifyObjects = true): void
    {
        $entry->refresh()->load('asset');
        $this->verifyEntry($entry, $verifyObjects);
        if ($entry->pruned_at) {
            return;
        }
        foreach (ReviewImageMigration::with('asset')->whereNull('retired_at')->where('source_url', $entry->source_url)->whereKeyNot($entry->id)->get() as $shared) {
            $this->verifyEntry($shared, $verifyObjects);
        }
        $path = $this->sourcePath($entry->source_url, false);
        if ((is_file($path) && hash_file('sha256', $path) !== $entry->source_sha256) || $this->hasSourceReferences($entry->source_url)) {
            throw new RuntimeException('Старый файл изменён или ещё используется: '.$entry->source_url);
        }
    }

    public function prune(ReviewImageMigration $entry, bool $verifyObjects = true): bool
    {
        $this->validatePrune($entry, $verifyObjects);
        if ($entry->pruned_at) {
            return false;
        }
        $path = $this->sourcePath($entry->source_url, false);
        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException('Не удалось удалить старый файл.');
        }
        ReviewImageMigration::where('source_url', $entry->source_url)->update(['pruned_at' => now()]);

        return true;
    }

    private function hasSourceReferences(string $url): bool
    {
        $path = str_starts_with($url, '/storage/') ? substr($url, 9) : ltrim($url, '/');
        if (ReviewMedia::where('url', $url)->exists()
            || ReviewEvidence::where('file_path', $path)->orWhere('storage_url', $url)->exists()
            || Review::where('author_photo_path', $path)->exists()
            || ReviewSource::where('source_url', $url)->exists()) {
            return true;
        }
        foreach ([ReviewMedia::pluck('url'), ReviewEvidence::whereNotNull('storage_url')->pluck('storage_url'), ReviewSource::whereNotNull('source_url')->pluck('source_url')] as $urls) {
            foreach ($urls as $reference) {
                if (parse_url($reference, PHP_URL_PATH) === $url) {
                    return true;
                }
            }
        }
        // Import metadata may retain a live file pointer even when the explicit columns do not.
        foreach (ReviewEvidence::whereNotNull('metadata')->pluck('metadata') as $metadata) {
            $json = json_encode($metadata, JSON_UNESCAPED_SLASHES);
            if (str_contains($json, $url) || str_contains($json, $path)) {
                return true;
            }
        }

        return false;
    }
}
