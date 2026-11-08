<?php

namespace App\Http\Resources;

use App\Models\Review;
use App\Models\ReviewModerationEvent;
use App\Services\Reviews\ReviewMediaManager;
use App\Services\Reviews\ReviewSourceLinks;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Review */
final class AdminReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $published = $this->publication_status === Review::PUBLICATION_PUBLISHED;
        $publishable = in_array($this->publication_status, [Review::PUBLICATION_DRAFT, Review::PUBLICATION_HIDDEN], true);
        $subject = $this->subject ?? $this->course?->subject;

        return [
            'id' => $this->id,
            'displayName' => (string) $this->display_name,
            'authorPhotoPath' => $this->author_photo_path,
            'showOnHome' => (bool) $this->show_on_home,
            'screenshotPaths' => $this->relationLoaded('media') ? array_keys(app(ReviewMediaManager::class)->current($this->resource)) : [],
            'sourceUrl' => $this->whenLoaded('sources', fn () => app(ReviewSourceLinks::class)->url($this->resource)),
            'isAnonymous' => (bool) $this->is_anonymous,
            'text' => (string) $this->text,
            'reviewWrittenAt' => $this->review_written_at?->toDateString(),
            'publishedAt' => $this->published_at?->toIso8601String(),
            'publicationStatus' => (string) $this->publication_status,
            'moderationStatus' => (string) $this->moderation_status,
            'dedupeStatus' => (string) $this->dedupe_status,
            'originType' => (string) $this->origin_type,
            'revision' => (int) ($this->moderation_events_max_id ?? 0),
            'subject' => $subject ? ['id' => $subject->id, 'title' => $subject->title] : null,
            'course' => $this->course ? ['id' => $this->course->id, 'title' => $this->course->title, 'subjectId' => $this->course->subject_id] : null,
            'hasPhoto' => ((int) ($this->media_count ?? 0) + ($this->media_managed ? 0 : (int) ($this->photo_evidence_count ?? 0))) > 0,
            'canEdit' => true,
            'canPublish' => $publishable && ! in_array($this->dedupe_status, [Review::DEDUPE_CANDIDATE, Review::DEDUPE_DUPLICATE], true),
            'canHide' => $published,
            'events' => $this->whenLoaded('moderationEvents', fn () => $this->moderationEvents->map(static fn (ReviewModerationEvent $event): array => [
                'id' => $event->id,
                'type' => $event->event_type,
                'actorName' => $event->actor?->name,
                'createdAt' => $event->created_at->toIso8601String(),
                'fromPublicationStatus' => $event->from_publication_status,
                'toPublicationStatus' => $event->to_publication_status,
                'changes' => $event->metadata['changes'] ?? [],
            ])->all()),
        ];
    }
}
