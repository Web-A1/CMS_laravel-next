<?php

namespace App\Http\Resources;

use App\Models\Review;
use App\Models\ReviewEvidence;
use App\Models\ReviewMedia;
use App\Models\ReviewSource;
use App\Services\Reviews\PublicReviewName;
use App\Services\Reviews\ReviewSourceLinks;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/** @mixin Review */
class ReviewResource extends JsonResource
{
    private const EVIDENCE_MEDIA_TYPES = ['screenshot', 'image'];

    public function toArray(Request $request): array
    {
        $course = $this->relationLoaded('course') ? $this->course : null;
        $directSubject = $this->relationLoaded('subject') ? $this->subject : null;
        $courseSubject = $this->course?->relationLoaded('subject') ? $this->course->subject : null;
        $subject = $directSubject ?? $courseSubject;

        return [
            'id' => $this->id,
            'displayName' => app(PublicReviewName::class)->resolve($this->resource),
            'authorPhotoUrl' => $this->resource->authorPhotoUrl(),
            ...($request->boolean('image_variants') ? ['authorPhotoVariants' => $this->is_anonymous || ! $this->author_photo_path ? []
                : ($this->resource->imageAsset($this->author_photo_path)?->publicVariants() ?? [])] : []),
            'text' => $this->text,
            'reviewWrittenAt' => $this->review_written_at?->toIso8601String(),
            'publishedAt' => $this->published_at?->toIso8601String(),
            'source' => $this->source,
            'importOrigin' => $this->source,
            'course' => $course ? new CourseResource($course) : null,
            'subject' => $subject ? [
                'id' => $subject->id,
                'slug' => $subject->slug,
                'title' => $subject->title,
            ] : null,
            'sourceLinks' => $this->buildSourceLinks(),
            'media' => $this->buildUnifiedMedia(),
        ];
    }

    /**
     * @return list<array{type: string, label: string, url: string}>
     */
    private function buildSourceLinks(): array
    {
        return app(ReviewSourceLinks::class)->links($this->resource);
    }

    /**
     * @return list<array{ id: string, type: string, url: string, alt: string|null, width: int|null, height: int|null, provenance: string|null, sourceType: string|null }>
     */
    private function buildUnifiedMedia(): array
    {
        $reviewMediaItems = $this->relationLoaded('media') ? $this->media : collect();
        $evidenceItems = ! $this->media_managed && $this->relationLoaded('evidence') ? $this->evidence : collect();

        $reviewMedia = $this->collectReviewMedia($reviewMediaItems);
        $evidenceMedia = $this->collectEvidenceMedia($evidenceItems);

        $combined = $reviewMedia->concat($evidenceMedia)
            ->filter(fn (array $item): bool => $item['url'] !== null)
            ->sortBy([fn (array $item): int => (int) $item['sort_order'], fn (array $item): string => (string) $item['url']])
            ->values();

        $result = [];
        $seen = [];

        foreach ($combined as $item) {
            $key = $item['type'].'|'.(string) $item['url'];
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = Arr::except($item, ['sort_order']);
        }

        return $result;
    }

    /** @param iterable<int, ReviewMedia> $items */
    private function collectReviewMedia(iterable $items): Collection
    {
        $result = collect();
        $sortOrder = 0;
        $skipTextScreenshots = ! $this->media_managed && ($this->hasPublicExternalReviewSource() || $this->hasPublicExternalScreenshotEvidence());

        foreach ($items as $media) {
            if ($skipTextScreenshots && (string) $media->type === 'legacy_screenshot') {
                continue;
            }

            $url = (string) $media->url;
            if ($url === '') {
                continue;
            }

            $asset = str_starts_with($url, '/storage/') ? $this->resource->imageAsset(substr($url, 9)) : null;
            $result->push([
                'id' => 'review_media:'.(int) $media->id,
                'type' => (string) $media->type,
                'url' => $asset?->url() ?? $url,
                ...(request()->boolean('image_variants') ? ['variants' => $asset?->publicVariants() ?? []] : []),
                'alt' => $media->alt,
                'width' => is_numeric($media->width) ? (int) $media->width : null,
                'height' => is_numeric($media->height) ? (int) $media->height : null,
                'provenance' => null,
                'sourceType' => null,
                'sort_order' => is_numeric($media->sort_order) ? (int) $media->sort_order : $sortOrder++,
            ]);
        }

        return $result;
    }

    /** @param iterable<int, ReviewEvidence> $items */
    private function collectEvidenceMedia(iterable $items): Collection
    {
        $result = collect();
        $sortOrder = 0;

        foreach ($items as $evidence) {
            if (! $evidence->is_public) {
                continue;
            }

            if ($this->isServiceScreenshot($evidence)) {
                continue;
            }

            if (! $this->isPublicEvidenceType((string) $evidence->type)) {
                continue;
            }

            $url = $this->resolveEvidenceUrl($evidence);
            if ($url === null) {
                continue;
            }

            $metadata = is_array($evidence->metadata) ? $evidence->metadata : [];
            $asset = str_starts_with($url, '/storage/') ? $this->resource->imageAsset(substr($url, 9)) : null;
            $sortIndex = data_get($metadata, 'sort_order', $sortOrder++);
            $sourceType = $evidence->relationLoaded('source') ? $evidence->source?->source_type : null;

            $result->push([
                'id' => 'review_evidence:'.(int) $evidence->id,
                'type' => (string) $evidence->type,
                'url' => $asset?->url() ?? $url,
                ...(request()->boolean('image_variants') ? ['variants' => $asset?->publicVariants() ?? []] : []),
                'alt' => is_string($evidence->caption) ? $evidence->caption : null,
                'width' => is_numeric(data_get($metadata, 'width')) ? (int) data_get($metadata, 'width') : null,
                'height' => is_numeric(data_get($metadata, 'height')) ? (int) data_get($metadata, 'height') : null,
                'provenance' => filled($evidence->provenance) ? (string) $evidence->provenance : null,
                'sourceType' => $sourceType ? (string) $sourceType : null,
                'sort_order' => (int) $sortIndex,
            ]);
        }

        return $result;
    }

    private function resolveEvidenceUrl(ReviewEvidence $evidence): ?string
    {
        if (filled($evidence->storage_url)) {
            return (string) $evidence->storage_url;
        }

        if (! filled($evidence->file_path)) {
            return null;
        }

        return Storage::disk('public')->url($evidence->file_path);
    }

    private function isPublicEvidenceType(string $type): bool
    {
        return in_array($type, self::EVIDENCE_MEDIA_TYPES, true);
    }

    private function isServiceScreenshot(ReviewEvidence $evidence): bool
    {
        if ((string) $evidence->type !== 'screenshot') {
            return false;
        }

        $sourceType = $evidence->relationLoaded('source') ? $evidence->source?->source_type : null;

        return in_array($sourceType, [ReviewSource::TYPE_VK_WEBARCHIVE, ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE], true)
            || in_array((string) $evidence->provenance, [ReviewSource::TYPE_VK_WEBARCHIVE, ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE], true);
    }

    private function hasPublicExternalReviewSource(): bool
    {
        if (! $this->relationLoaded('sources')) {
            return false;
        }

        foreach ($this->sources as $source) {
            if (! $source->is_public) {
                continue;
            }

            if (in_array((string) $source->source_type, [
                ReviewSource::TYPE_LEGACY_SITE,
                ReviewSource::TYPE_VK_WEBARCHIVE,
                ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE,
            ], true)) {
                return true;
            }
        }

        return false;
    }

    private function hasPublicExternalScreenshotEvidence(): bool
    {
        if (! $this->relationLoaded('evidence')) {
            return false;
        }

        foreach ($this->evidence as $evidence) {
            if (! $evidence->is_public || (string) $evidence->type !== 'screenshot') {
                continue;
            }

            $sourceType = $evidence->relationLoaded('source') ? $evidence->source?->source_type : null;

            if (in_array((string) $sourceType, [
                ReviewSource::TYPE_LEGACY_SITE,
                ReviewSource::TYPE_VK_WEBARCHIVE,
                ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE,
            ], true)) {
                return true;
            }

            if (in_array((string) $evidence->provenance, [
                ReviewSource::TYPE_LEGACY_SITE,
                ReviewSource::TYPE_VK_WEBARCHIVE,
                ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE,
            ], true)) {
                return true;
            }
        }

        return false;
    }
}
