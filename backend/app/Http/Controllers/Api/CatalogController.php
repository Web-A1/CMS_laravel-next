<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubjectResource;
use App\Models\Course;
use App\Models\Guide;
use App\Models\Lesson;
use App\Models\OrderItem;
use App\Models\SellableEntity;
use App\Models\Subject;
use App\Services\GrantPreviewService;
use App\Support\SellableTariff;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogController extends Controller
{
    public function sellables(GrantPreviewService $grantPreview): JsonResponse
    {
        $data = [];
        foreach (SellableEntity::query()->active()->orderBy('id')->get() as $sellable) {
            try {
                $offer = $this->offer($sellable, $grantPreview);
                if ($offer !== null) {
                    $data[] = $offer;
                }
            } catch (DomainException) {
                // Incomplete mappings or malformed terms cannot become public offers.
            }
        }

        return response()->json(['data' => $data]);
    }

    private function offer(SellableEntity $sellable, GrantPreviewService $grantPreview): ?array
    {
        if (! in_array($sellable->sellable_kind, OrderItem::SELLABLE_KINDS, true)
            || ! in_array($sellable->entity_type, OrderItem::SELLABLE_KINDS, true)
            || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,159}\z/', $sellable->sku)
            || $sellable->price_cents < 0 || $sellable->price_version < 1 || $sellable->currency !== 'RUB'
            || ! $this->safeTitle($sellable->title)) {
            return null;
        }

        $tariff = SellableTariff::fromMetadata($sellable->metadata);
        $atoms = [];
        foreach ($grantPreview->preview($sellable)['atoms'] as $atom) {
            $destination = $this->destination($atom['kind'], $atom['id']);
            if ($destination === null) {
                return null;
            }
            $atoms[] = ['kind' => $atom['kind'], 'id' => $atom['id'], ...$destination];
        }
        $entityId = $sellable->entity_id ?? $sellable->id;
        $destination = $sellable->entity_type === OrderItem::KIND_BOX
            ? ($sellable->entity_id === null ? $atoms[0] : null)
            : $this->destination($sellable->entity_type, $entityId);
        if ($destination === null || $entityId < 1) {
            return null;
        }

        return [
            'sku' => $sellable->sku, 'kind' => $sellable->sellable_kind,
            'entityType' => $sellable->entity_type, 'entityId' => (int) $entityId,
            'title' => $sellable->title, 'price' => (int) $sellable->price_cents,
            'currency' => $sellable->currency, 'priceVersion' => (int) $sellable->price_version,
            'publicPath' => '/offers/'.$sellable->sku, 'accessPath' => $destination['accessPath'],
            'tariff' => $tariff, 'atoms' => $atoms,
        ];
    }

    private function destination(string $kind, int $id): ?array
    {
        if ($kind === OrderItem::KIND_GUIDE) {
            $guide = Guide::query()->active()->find($id);

            return $guide && $this->safeTitle($guide->title) && $this->safeSlug($guide->slug)
                ? ['title' => $guide->title, 'accessPath' => '/lk/guides/'.$guide->slug] : null;
        }
        if ($kind === OrderItem::KIND_LESSON) {
            $lesson = Lesson::query()->active()->with('course.subject')->find($id);
            $course = $lesson?->course;

            return $lesson && $course?->is_active && $course->subject?->is_active
                && $this->safeTitle($lesson->title) && $this->safeSlug($lesson->slug)
                && $this->safeSlug($course->slug) && $this->safeSlug($course->subject->slug)
                ? ['title' => $lesson->title, 'accessPath' => '/lk/learning/'.$course->subject->slug.'/'.$course->slug.'/'.$lesson->slug] : null;
        }
        if (in_array($kind, [OrderItem::KIND_COURSE, OrderItem::KIND_INTENSIVE], true)) {
            $course = Course::query()->active()->with('subject')->find($id);

            return $course && $course->subject?->is_active && $this->safeTitle($course->title)
                && $this->safeSlug($course->slug) && $this->safeSlug($course->subject->slug)
                ? ['title' => $course->title, 'accessPath' => '/lk/learning/'.$course->subject->slug.'/'.$course->slug] : null;
        }

        return null;
    }

    private function safeSlug(string $value): bool
    {
        return preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,254}\z/', $value) === 1;
    }

    private function safeTitle(string $value): bool
    {
        return $value !== '' && trim($value) === $value && preg_match('/[\p{Cc}<>]/u', $value) === 0;
    }

    public function subjects(): AnonymousResourceCollection
    {
        $subjects = Subject::query()
            ->active()
            ->with([
                'courses' => fn ($query) => $query
                    ->active()
                    ->with([
                        'activeSellableEntity',
                        'lessons' => fn ($lessonQuery) => $lessonQuery
                            ->active()
                            ->orderBy('sort_order')
                            ->orderBy('title'),
                    ])
                    ->orderBy('sort_order')
                    ->orderBy('title'),
                'courses.subject',
            ])
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();

        return SubjectResource::collection($subjects);
    }
}
