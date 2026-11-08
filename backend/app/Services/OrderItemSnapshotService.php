<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellableEntity;
use App\Models\UserEntitlementGrant;
use App\Support\SellableTariff;
use DomainException;
use JsonException;

class OrderItemSnapshotService
{
    public function __construct(private readonly GrantPreviewService $grantPreviewService) {}

    public function createCourseItem(Order $order, Course $course): OrderItem
    {
        $course->loadMissing([
            'subject',
            'lessons' => fn ($query) => $query->active()->orderBy('sort_order')->orderBy('id'),
        ]);

        $atoms = $course->lessons
            ->map(fn ($lesson): array => [
                'kind' => UserEntitlementGrant::ATOM_LESSON,
                'id' => (int) $lesson->id,
            ])
            ->values()
            ->all();

        if ($atoms === []) {
            throw new DomainException('COURSE_HAS_NO_GRANTABLE_TOPICS');
        }

        $catalogSnapshot = [
            'version' => 1,
            'course' => [
                'id' => (int) $course->id,
                'slug' => (string) $course->slug,
                'title' => (string) $course->title,
                'subject_id' => (int) $course->subject_id,
                'subject_slug' => $course->subject?->slug,
            ],
            'lessons' => $course->lessons
                ->map(fn ($lesson): array => [
                    'id' => (int) $lesson->id,
                    'slug' => (string) $lesson->slug,
                    'title' => (string) $lesson->title,
                ])
                ->values()
                ->all(),
        ];
        $priceSnapshot = [
            'version' => 1,
            'currency' => (string) $order->currency,
            'unit_amount' => (int) $order->amount_total,
            'discount_amount' => (int) $order->amount_discount,
            'final_amount' => (int) $order->amount_final,
        ];
        $benefitSnapshot = [
            'version' => 1,
            'discount_amount' => (int) $order->amount_discount,
            'rules' => [],
            'purchase_bonus_policy_version' => config('bonuses.checkout_enabled', false) === true ? 1 : null,
        ];
        $grantSnapshot = [
            'version' => 1,
            'entity_type' => OrderItem::KIND_COURSE,
            'entity_id' => (int) $course->id,
            'atoms' => $atoms,
            'resolved_from' => 'lessons',
            'resolved_at' => now()->toISOString(),
        ];

        $attributes = [
            'order_id' => $order->id,
            'sellable_kind' => OrderItem::KIND_COURSE,
            'sellable_id' => $course->id,
            'sellable_sku' => $course->itemId(),
            'purchased_entity_type' => OrderItem::KIND_COURSE,
            'purchased_entity_id' => $course->id,
            'purchased_entity_slug' => $course->slug,
            'display_title' => $course->title,
            'quantity' => 1,
            'currency' => (string) $order->currency,
            'unit_amount' => (int) $order->amount_total,
            'subtotal_amount' => (int) $order->amount_total,
            'discount_amount' => (int) $order->amount_discount,
            'final_amount' => (int) $order->amount_final,
            'catalog_snapshot' => $catalogSnapshot,
            'price_snapshot' => $priceSnapshot,
            'benefit_snapshot' => $benefitSnapshot,
            'grant_snapshot' => $grantSnapshot,
            'access_status' => OrderItem::ACCESS_NOT_GRANTED,
        ];

        $attributes['snapshot_hash'] = self::snapshotHash($attributes);

        return OrderItem::query()->create($attributes);
    }

    public function createSellableItem(Order $order, SellableEntity $sellableEntity, ?int $grantVersion = null): OrderItem
    {
        if (! in_array((string) $sellableEntity->sellable_kind, OrderItem::SELLABLE_KINDS, true)) {
            throw new DomainException('SELLABLE_KIND_UNSUPPORTED');
        }

        if (! in_array((string) $sellableEntity->entity_type, OrderItem::SELLABLE_KINDS, true)) {
            throw new DomainException('SELLABLE_ENTITY_TYPE_UNSUPPORTED');
        }

        $grantSnapshot = $this->grantPreviewService->preview($sellableEntity, $grantVersion);
        $metadata = $sellableEntity->metadata ?? [];
        $purchasedEntityId = $sellableEntity->entity_id ?: $sellableEntity->id;
        $purchasedEntitySlug = is_string($metadata['slug'] ?? null) ? $metadata['slug'] : null;

        $catalogSnapshot = [
            'version' => 1,
            'sellable' => [
                'id' => (int) $sellableEntity->id,
                'kind' => (string) $sellableEntity->sellable_kind,
                'sku' => (string) $sellableEntity->sku,
                'title' => (string) $sellableEntity->title,
                'entity_type' => (string) $sellableEntity->entity_type,
                'entity_id' => $sellableEntity->entity_id === null ? null : (int) $sellableEntity->entity_id,
                'status' => (string) $sellableEntity->status,
            ],
            'tariff' => SellableTariff::snapshot($metadata),
        ];
        $priceSnapshot = [
            'version' => 1,
            'currency' => (string) $order->currency,
            'catalog_price_cents' => (int) $sellableEntity->price_cents,
            'price_version' => (int) $sellableEntity->price_version,
            'unit_amount' => (int) $order->amount_total,
            'discount_amount' => (int) $order->amount_discount,
            'final_amount' => (int) $order->amount_final,
        ];
        $benefitSnapshot = [
            'version' => 1,
            'discount_amount' => (int) $order->amount_discount,
            'rules' => [],
            'purchase_bonus_policy_version' => config('bonuses.checkout_enabled', false) === true ? 1 : null,
        ];

        $attributes = [
            'order_id' => $order->id,
            'sellable_kind' => (string) $sellableEntity->sellable_kind,
            'sellable_id' => $sellableEntity->id,
            'sellable_sku' => (string) $sellableEntity->sku,
            'purchased_entity_type' => (string) $sellableEntity->entity_type,
            'purchased_entity_id' => $purchasedEntityId,
            'purchased_entity_slug' => $purchasedEntitySlug,
            'display_title' => (string) $sellableEntity->title,
            'quantity' => 1,
            'currency' => (string) $order->currency,
            'unit_amount' => (int) $order->amount_total,
            'subtotal_amount' => (int) $order->amount_total,
            'discount_amount' => (int) $order->amount_discount,
            'final_amount' => (int) $order->amount_final,
            'price_version' => (int) $sellableEntity->price_version,
            'catalog_snapshot' => $catalogSnapshot,
            'price_snapshot' => $priceSnapshot,
            'benefit_snapshot' => $benefitSnapshot,
            'grant_snapshot' => $grantSnapshot,
            'access_status' => OrderItem::ACCESS_NOT_GRANTED,
        ];

        $attributes['snapshot_hash'] = self::snapshotHash($attributes);

        return OrderItem::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws JsonException
     */
    public static function snapshotHash(array $attributes): string
    {
        $payload = self::canonicalize([
            'sellable_kind' => $attributes['sellable_kind'] ?? null,
            'sellable_id' => $attributes['sellable_id'] ?? null,
            'sellable_sku' => $attributes['sellable_sku'] ?? null,
            'purchased_entity_type' => $attributes['purchased_entity_type'] ?? null,
            'purchased_entity_id' => $attributes['purchased_entity_id'] ?? null,
            'purchased_entity_slug' => $attributes['purchased_entity_slug'] ?? null,
            'display_title' => $attributes['display_title'] ?? null,
            'quantity' => $attributes['quantity'] ?? null,
            'currency' => $attributes['currency'] ?? null,
            'unit_amount' => $attributes['unit_amount'] ?? null,
            'subtotal_amount' => $attributes['subtotal_amount'] ?? null,
            'discount_amount' => $attributes['discount_amount'] ?? null,
            'final_amount' => $attributes['final_amount'] ?? null,
            'price_id' => $attributes['price_id'] ?? null,
            'price_version' => $attributes['price_version'] ?? null,
            'catalog_snapshot' => $attributes['catalog_snapshot'] ?? null,
            'price_snapshot' => $attributes['price_snapshot'] ?? null,
            'benefit_snapshot' => $attributes['benefit_snapshot'] ?? null,
            'grant_snapshot' => $attributes['grant_snapshot'] ?? null,
        ]);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = self::canonicalize($item);
        }

        if (! $isList) {
            ksort($normalized);
        }

        return $normalized;
    }
}
