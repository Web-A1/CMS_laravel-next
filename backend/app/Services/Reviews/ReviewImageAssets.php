<?php

namespace App\Services\Reviews;

use App\Jobs\ProcessReviewImage;
use App\Models\Review;
use App\Models\ReviewImageAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ReviewImageAssets
{
    public function __construct(private readonly ReviewImageProcessor $processor) {}

    public function stage(UploadedFile $file, string $kind, User $owner): ReviewImageAsset
    {
        $asset = $this->createAsset($file, $kind, $owner->id);
        try {
            ProcessReviewImage::dispatch($asset->id);
        } catch (Throwable $exception) {
            $asset->refresh()->update(['status' => 'failed', 'error' => 'Не удалось запустить обработку. Загрузите файл повторно.']);
            throw new RuntimeException('Не удалось запустить подготовку изображения. Повторите загрузку.', previous: $exception);
        }

        return $asset->refresh();
    }

    public function stageExisting(string $path, int $reviewId): ReviewImageAsset
    {
        // The migration owns processing and switching; keep its pending asset out of orphan cleanup.
        return $this->createAsset(new UploadedFile($path, basename($path), test: true), 'screenshot', null, $reviewId);
    }

    private function createAsset(UploadedFile $file, string $kind, ?int $ownerId, ?int $reviewId = null): ReviewImageAsset
    {
        if (! $file->isValid() || ! in_array($kind, ['avatar', 'screenshot'], true) || $file->getSize() < 1 || $file->getSize() > config('review-media.max_bytes')) {
            throw new InvalidArgumentException('Допустимы изображения размером до 25 МБ.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        [$width, $height] = $this->processor->inspect($file->getRealPath(), $mime);
        $id = (string) Str::uuid();
        $source = 'review-image-uploads/'.$id;
        if (! Storage::disk('local')->put($source, $file->get())) {
            throw new RuntimeException('Не удалось сохранить временный файл.');
        }
        try {
            $asset = ReviewImageAsset::create(['id' => $id, 'owner_id' => $ownerId, 'review_id' => $reviewId, 'kind' => $kind,
                'status' => 'pending', 'disk' => config('review-media.disk'),
                'primary_path' => ($kind === 'avatar' ? 'review-authors/' : 'review-screenshots/').$id.'.webp',
                'source_path' => $source, 'source_mime' => $mime, 'width' => $width, 'height' => $height]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($source);
            throw $exception;
        }

        return $asset;
    }

    public function process(string $id): void
    {
        Cache::lock('review-media:encoder', 100)->block(5, function () use ($id): void {
            $asset = DB::transaction(function () use ($id): ?ReviewImageAsset {
                $asset = ReviewImageAsset::lockForUpdate()->find($id);
                if ($asset && ! in_array($asset->status, ['ready', 'deleting'], true)) {
                    $asset->update(['status' => 'processing', 'error' => null]);
                }

                return $asset;
            });
            if (! $asset || $asset->status === 'deleting') {
                return;
            }
            if ($asset->status === 'ready') {
                return;
            }
            $directory = Storage::disk('local')->path('review-image-work/'.$asset->id);
            try {
                File::deleteDirectory($directory);
                File::makeDirectory($directory, 0700, true);
                if (! $asset->variants) {
                    $variants = $this->processor->variants(Storage::disk('local')->path($asset->source_path), $asset->source_mime, $asset->kind, $directory);
                    $largest = max(array_column($variants, 'width'));
                    $manifest = [];
                    foreach ($variants as $variant) {
                        $path = $variant['format'] === 'webp' && $variant['width'] === $largest
                            ? $asset->primary_path : pathinfo($asset->primary_path, PATHINFO_DIRNAME).'/'.$asset->id.'/'.$variant['width'].'.'.$variant['format'];
                        if (! Storage::disk($asset->disk)->put($path, file_get_contents($variant['file']), [
                            'visibility' => 'public', 'ContentType' => 'image/'.$variant['format'],
                            'CacheControl' => 'public, max-age=31536000, immutable',
                        ])) {
                            throw new RuntimeException('Не удалось сохранить готовое изображение.');
                        }
                        $manifest[] = array_diff_key($variant, ['file' => true]) + ['path' => $path, 'sha256' => hash_file('sha256', $variant['file'])];
                    }
                    $primary = collect($manifest)->firstWhere('path', $asset->primary_path);
                    $asset->update(['variants' => $manifest, 'width' => $primary['width'], 'height' => $primary['height']]);
                }
                foreach ($asset->variants as $variant) {
                    if (! Storage::disk($asset->disk)->exists($variant['path'])) {
                        throw new RuntimeException('Набор изображений сохранён не полностью.');
                    }
                }
                if ($asset->source_path && ! Storage::disk('local')->delete($asset->source_path)) {
                    throw new RuntimeException('Не удалось удалить временный оригинал.');
                }
                $asset->update(['status' => 'ready', 'source_path' => null, 'error' => null]);
            } catch (Throwable $exception) {
                $asset->update(['status' => 'failed', 'error' => 'Не удалось подготовить изображение. Удалите файл из формы и загрузите его снова.']);
                throw $exception;
            } finally {
                File::deleteDirectory($directory);
            }
        });
    }

    public function attach(User $actor, Review $review, array $paths): void
    {
        $assets = ReviewImageAsset::whereIn('primary_path', $paths)->orderBy('id')->lockForUpdate()->get();
        foreach ($assets as $asset) {
            if ($asset->status !== 'ready' || ($asset->review_id !== null && (int) $asset->review_id !== $review->id)
                || ($asset->review_id === null && (int) $asset->owner_id !== $actor->id)) {
                throw ValidationException::withMessages(['media' => 'Изображение ещё не готово или принадлежит другому отзыву.']);
            }
            $asset->update(['review_id' => $review->id]);
        }
    }

    public function cleanup(): int
    {
        return app(ReviewImageRemoval::class)->cleanup();
    }
}
