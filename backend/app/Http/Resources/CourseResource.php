<?php

namespace App\Http\Resources;

use App\Models\Course;
use App\Models\SellableEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Course */
class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'itemId' => $this->itemId(),
            'slug' => $this->slug,
            'title' => $this->title,
            'shortTitle' => $this->short_title,
            'price' => $this->price_cents,
            'sellable' => $this->whenLoaded(
                'activeSellableEntity',
                fn () => $this->sellablePayload($this->activeSellableEntity)
            ),
            'subjectSlug' => $this->whenLoaded('subject', fn () => $this->subject?->slug),
            'subjectTitle' => $this->whenLoaded('subject', fn () => $this->subject?->title),
            'lessons' => LessonResource::collection($this->whenLoaded('lessons')),
        ];
    }

    /**
     * @return array{sku: string, kind: string, price: int, currency: string, priceVersion: int}|null
     */
    private function sellablePayload(?SellableEntity $sellable): ?array
    {
        if (! $sellable) {
            return null;
        }

        return [
            'sku' => $sellable->sku,
            'kind' => $sellable->sellable_kind,
            'price' => $sellable->price_cents,
            'currency' => $sellable->currency,
            'priceVersion' => $sellable->price_version,
        ];
    }
}
