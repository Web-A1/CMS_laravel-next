<?php

namespace App\Services\Reviews;

use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Models\ReviewImageAsset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class ReviewMediaManager
{
    /** The same effective list as the public site; historical evidence is not added twice. */
    public function current(Review $review): array
    {
        $review->loadMissing(['media', 'evidence.source', 'sources', 'imageAssets']);
        $items = (new ReviewResource($review))->resolve()['media'];
        $result = [];
        foreach ($items as $item) {
            // Private/non-image attachments are not editable uploads.
            if (! str_starts_with($item['url'], '/storage/') && ! str_starts_with($item['url'], '/images/reviews/')
                && ! preg_match('~\Ahttps?://~', $item['url'])) {
                continue;
            }
            $asset = $review->imageAssets->first(fn (ReviewImageAsset $asset) => $asset->kind === 'screenshot'
                && in_array($item['url'], [$asset->url(), '/storage/'.$asset->primary_path], true));
            $key = $asset?->primary_path ?? 'existing:'.$item['id'];
            $result[$key] = $item + ['path' => $asset?->primary_path];
        }

        return $result;
    }

    public function snapshot(Review $review): array
    {
        return ['displayName' => $review->display_name, 'isAnonymous' => (bool) $review->is_anonymous,
            'sourceUrl' => app(ReviewSourceLinks::class)->url($review),
            'text' => $review->text, 'reviewWrittenAt' => $review->review_written_at?->toDateString(),
            'authorPhotoPath' => $review->author_photo_path, 'showOnHome' => (bool) $review->show_on_home,
            'subjectId' => $review->subject_id, 'courseId' => $review->course_id,
            'screenshots' => array_values($this->current($review))];
    }

    public function sync(Review $review, array $paths): void
    {
        $current = $this->current($review);
        $keep = [];
        $seen = [];
        foreach ($paths as $index => $path) {
            $existing = $current[$path] ?? null;
            if (str_starts_with($path, 'existing:') && $existing === null) {
                throw ValidationException::withMessages(['screenshotPaths' => 'Изображение не относится к текущему отзыву. Обновите форму.']);
            }
            $asset = ReviewImageAsset::where('primary_path', $path)->first();
            $url = $existing ? ($asset ? '/storage/'.$path : $existing['url']) : '/storage/'.$path;
            if (in_array($url, $seen, true)) {
                throw ValidationException::withMessages(['screenshotPaths' => 'Одно изображение добавлено дважды.']);
            }
            $seen[] = $url;
            $media = $existing && str_starts_with($existing['id'], 'review_media:')
                ? $review->media()->findOrFail(substr($existing['id'], strlen('review_media:')))
                : $review->media()->where('url', $url)->first();
            $image = $asset ? [$asset->width, $asset->height] : ($existing ? [$existing['width'], $existing['height']]
                : getimagesizefromstring(Storage::disk('public')->get($path)));
            $values = ['url' => $url, 'alt' => $existing['alt'] ?? 'Скриншот отзыва',
                'width' => $image[0], 'height' => $image[1], 'sort_order' => $index];
            if ($media) {
                $media->update($values);
            } else {
                $media = $review->media()->create($values + ['type' => 'screenshot']);
            }
            $keep[] = $media->id;
        }
        // Keep historical private attachments; all current public images follow the saved form.
        $review->media()->whereNotIn('id', $keep)->where(function ($query): void {
            $query->where('url', 'like', '/storage/%')->orWhere('url', 'like', '/images/reviews/%')
                ->orWhere('url', 'like', 'https://%')->orWhere('url', 'like', 'http://%');
        })->delete();
        $review->media_managed = true;
        $review->save();
        $review->unsetRelation('media');
    }

    public function assetPaths(Review $review): array
    {
        return array_values(array_filter([$review->author_photo_path, ...$review->media()->pluck('url')
            ->filter(fn ($url) => str_starts_with($url, '/storage/'))->map(fn ($url) => substr($url, 9))->all()]));
    }
}
