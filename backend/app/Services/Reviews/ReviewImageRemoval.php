<?php

namespace App\Services\Reviews;

use App\Exceptions\AdminReviewException;
use App\Jobs\DeleteReviewImage;
use App\Models\Review;
use App\Models\ReviewEvidence;
use App\Models\ReviewImageAsset;
use App\Models\ReviewImageDeletion;
use App\Models\ReviewImageMigration;
use App\Models\ReviewMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ReviewImageRemoval
{
    public function removeDetachedOwnerAssets(array $userIds): void
    {
        foreach (ReviewImageAsset::whereIn('owner_id', $userIds)->whereNull('review_id')->orderBy('id')->lockForUpdate()->get() as $asset) {
            if ($asset->status === 'deleting') {
                continue;
            }
            if (! $this->used($asset->disk, $asset->primary_path)) {
                $this->scheduleAsset($asset, null);
            }
        }
    }

    public function removeReview(Review $review): void
    {
        $assets = $review->imageAssets()->orderBy('id')->lockForUpdate()->get();
        if ($assets->contains('status', 'processing')) {
            throw new AdminReviewException('ADMIN_REVIEW_STATE_CONFLICT', 'Изображение ещё обрабатывается. Дождитесь завершения и повторите удаление.');
        }
        $evidence = ReviewEvidence::where('review_id', $review->id)
            ->orWhereIn('review_source_id', $review->sources()->pluck('id'))->get();
        $urls = $review->media()->pluck('url')->all();
        if ($review->author_photo_path) {
            $urls[] = '/storage/'.$review->author_photo_path;
        }
        foreach ($evidence as $item) {
            if ($item->storage_url) {
                $urls[] = $item->storage_url;
            }
            if ($item->file_path) {
                $urls[] = '/storage/'.$item->file_path;
            }
            if (is_string($item->metadata['public_review_media_url'] ?? null)) {
                $urls[] = $item->metadata['public_review_media_url'];
            }
        }
        $review->media()->delete();
        ReviewEvidence::whereIn('id', $evidence->pluck('id'))->delete();
        $review->update(['author_photo_path' => null, 'media_managed' => true]);
        $this->schedule($review, $assets->all(), $urls, []);
        // Only files in review-owned application directories; shared import archives stay intact.
        foreach ($evidence->pluck('file_path')->filter()->unique() as $path) {
            if (! preg_match('~\A(?:reviews|review-evidence)/[a-zA-Z0-9_@./-]+\.(?:jpe?g|png|webp|avif|heic|heif|webarchive|html?|pdf)\z~i', $path)
                || array_intersect(['.', '..'], explode('/', $path)) !== []) {
                continue;
            }
            foreach (['local', 'public'] as $disk) {
                if ($disk === 'public' && $this->localFile('/storage/'.$path)) {
                    continue;
                }
                if (Storage::disk($disk)->exists($path) && ! $this->used($disk, $path)) {
                    $this->enqueue(['review_id' => $review->id, 'files' => [['disk' => $disk, 'path' => $path]], 'directories' => []]);
                }
            }
        }
    }

    public function schedule(Review $review, array $oldAssets, array $oldUrls, array $retainedPaths): void
    {
        // A shared URL can outlive its original owner's attachment. Its last user owns cleanup.
        $paths = [];
        foreach ($oldUrls as $url) {
            if (preg_match('~(?:^|/)((?:review-authors|review-screenshots)/[a-f0-9-]{36}\.webp)\z~', (string) parse_url($url, PHP_URL_PATH), $match)) {
                $paths[] = $match[1];
            }
        }
        $referenced = ReviewImageAsset::whereIn('primary_path', $paths)->get()->filter(fn ($asset) => in_array($asset->url(), $oldUrls, true) || in_array('/storage/'.$asset->primary_path, $oldUrls, true));
        $oldAssets = collect($oldAssets)->merge($referenced)->unique('id')->sortBy('id');
        $covered = [];
        foreach ($oldAssets as $old) {
            $asset = ReviewImageAsset::lockForUpdate()->find($old->id);
            if (! $asset || in_array($asset->primary_path, $retainedPaths, true)) {
                continue;
            }
            $covered[] = $asset->url();
            $covered[] = '/storage/'.$asset->primary_path;
            if ($asset->status === 'deleting') {
                continue;
            }
            if ($this->used($asset->disk, $asset->primary_path)) {
                continue;
            }
            $this->scheduleAsset($asset, $review->id);
        }
        foreach (array_unique($oldUrls) as $url) {
            if (in_array($url, $covered, true)) {
                continue;
            }
            $file = $this->localFile($url);
            if ($file && ! $this->used($file['disk'], $file['path'])) {
                $this->enqueue(['review_id' => $review->id, 'files' => [$file], 'directories' => []]);
            }
        }
    }

    private function scheduleAsset(ReviewImageAsset $asset, ?int $reviewId, bool $dispatch = true): void
    {
        $prefix = $asset->kind === 'avatar' ? 'review-authors' : 'review-screenshots';
        if (! Str::isUuid($asset->id) || $asset->primary_path !== $prefix.'/'.$asset->id.'.webp') {
            throw new RuntimeException('Недопустимый путь набора изображений.');
        }
        $directory = $prefix.'/'.$asset->id;
        $paths = [$asset->primary_path];
        foreach ($asset->variants ?? [] as $variant) {
            if ($variant['path'] !== $asset->primary_path && ! preg_match('~\A'.preg_quote($directory, '~').'/[0-9]+\.(?:avif|webp)\z~', $variant['path'])) {
                throw new RuntimeException('Недопустимый путь версии изображения.');
            }
            $paths[] = $variant['path'];
        }
        $files = array_map(fn ($path) => ['disk' => $asset->disk, 'path' => $path], array_values(array_unique($paths)));
        foreach (ReviewImageMigration::where('asset_id', $asset->id)->whereNull('pruned_at')->get() as $migration) {
            $file = $this->localFile($migration->source_url);
            if ($file && ! $this->used($file['disk'], $file['path'])) {
                $files[] = $file + ['sha256' => $migration->source_sha256];
            }
        }
        $directories = [['disk' => $asset->disk, 'path' => $directory], ['disk' => 'local', 'path' => 'review-image-work/'.$asset->id]];
        if ($asset->source_path) {
            if ($asset->source_path !== 'review-image-uploads/'.$asset->id) {
                throw new RuntimeException('Недопустимый путь временного изображения.');
            }
            $files[] = ['disk' => 'local', 'path' => $asset->source_path];
        }
        $asset->update(['status' => 'deleting', 'review_id' => null]);
        $this->enqueue(['review_id' => $reviewId, 'asset_id' => $asset->id, 'files' => $files, 'directories' => $directories], $dispatch);
    }

    private function enqueue(array $data, bool $dispatch = true): void
    {
        $deletion = ReviewImageDeletion::create($data);
        if (! $dispatch) {
            return;
        }
        DB::afterCommit(function () use ($deletion): void {
            try {
                DeleteReviewImage::dispatch($deletion->id);
            } catch (Throwable $exception) {
                // A committed save must not be reported as failed; the durable outbox retries.
                $deletion->update(['error' => 'Удаление изображений ожидает повторной попытки.']);
                report($exception);
            }
        });
    }

    public function run(int $id): bool
    {
        try {
            return DB::transaction(function () use ($id): bool {
                $entry = ReviewImageDeletion::find($id);
                if (! $entry) {
                    return false;
                }
                // Same review-before-asset lock order as saves.
                if ($entry->review_id) {
                    Review::whereKey($entry->review_id)->lockForUpdate()->first();
                }
                $entry = ReviewImageDeletion::lockForUpdate()->find($id);
                if (! $entry) {
                    return false;
                }
                $asset = $entry->asset_id ? ReviewImageAsset::lockForUpdate()->find($entry->asset_id) : null;
                if ($asset && ($asset->status !== 'deleting' || $asset->review_id !== null)) {
                    throw new RuntimeException('Набор изображений снова используется.');
                }
                foreach ($entry->files as $file) {
                    if ($this->used($file['disk'], $file['path'])) {
                        throw new RuntimeException('Изображение используется или является исходным материалом.');
                    }
                    if (isset($file['sha256']) && Storage::disk($file['disk'])->exists($file['path'])
                        && ! hash_equals($file['sha256'], hash('sha256', Storage::disk($file['disk'])->get($file['path'])))) {
                        throw new RuntimeException('Рабочий исходник изменился после переноса.');
                    }
                }
                foreach ($entry->directories ?? [] as $directory) {
                    if (! Storage::disk($directory['disk'])->deleteDirectory($directory['path'])) {
                        throw new RuntimeException('Хранилище не подтвердило удаление версий изображения.');
                    }
                }
                foreach ($entry->files as $file) {
                    if (! Storage::disk($file['disk'])->delete($file['path'])) {
                        throw new RuntimeException('Хранилище не подтвердило удаление изображения.');
                    }
                }
                $entry->delete();
                if ($asset) {
                    ReviewImageMigration::where('asset_id', $asset->id)->update(['retired_at' => now()]);
                }
                $asset?->delete();

                return true;
            });
        } catch (Throwable $exception) {
            ReviewImageDeletion::whereKey($id)->update(['error' => 'Не удалось удалить изображения. Удаление будет повторено.']);
            throw $exception;
        }
    }

    public function cleanup(): int
    {
        ReviewImageAsset::whereNull('review_id')->where('status', '!=', 'deleting')
            ->where('updated_at', '<', now()->subHours(config('review-media.temporary_hours')))
            ->pluck('id')->each(function (string $id): void {
                DB::transaction(function () use ($id): void {
                    $asset = ReviewImageAsset::lockForUpdate()->find($id);
                    if ($asset && $asset->review_id === null && $asset->status !== 'deleting'
                        && $asset->updated_at->isBefore(now()->subHours(config('review-media.temporary_hours')))
                        && ! $this->used($asset->disk, $asset->primary_path)) {
                        $this->scheduleAsset($asset, null, false);
                    }
                });
            });
        $count = 0;
        foreach (ReviewImageDeletion::pluck('id') as $id) {
            try {
                $count += (int) $this->run($id);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $count;
    }

    private function used(string $disk, string $path): bool
    {
        $urls = array_unique(['/storage/'.$path, Storage::disk($disk)->url($path), '/'.$path]);
        if (Review::where('author_photo_path', $path)->exists() || ReviewMedia::whereIn('url', $urls)->exists()) {
            return true;
        }

        // Private originals remain protected; public evidence is historical after a managed save.
        return ReviewEvidence::where(fn ($query) => $query->where('is_public', false)
            ->orWhereHas('review', fn ($reviews) => $reviews->where('media_managed', false)))
            ->where(fn ($query) => $query->where('file_path', $path)->orWhereIn('storage_url', $urls))->exists();
    }

    private function localFile(string $url): ?array
    {
        if (preg_match('~\A/images/reviews/[a-zA-Z0-9_@./-]+\.(?:jpe?g|png|webp|avif)\z~', $url)
            && array_intersect(['.', '..'], explode('/', $url)) === []) {
            return ['disk' => 'review-media-legacy', 'path' => ltrim($url, '/')];
        }
        $public = rtrim(Storage::disk('public')->url(''), '/').'/';
        $path = str_starts_with($url, '/storage/') ? substr($url, 9)
            : (str_starts_with($url, $public) ? substr($url, strlen($public)) : null);
        if ($path && preg_match('~\A(?:review-authors|review-screenshots|reviews)/[a-zA-Z0-9_@./-]+\.(?:jpe?g|png|webp|avif)\z~', $path)
            && array_intersect(['.', '..'], explode('/', $path)) === []) {
            return ['disk' => 'public', 'path' => $path];
        }

        return null;
    }
}
