<?php

namespace App\Services;

use App\Models\UserEntitlementGrant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EntitlementGrantService
{
    public function __construct(private readonly AccessResolver $accessResolver) {}

    /**
     * @param  array<int, array{kind:string,id:int|string}>  $atoms
     * @param  array<string, mixed>  $metadata
     * @return array{grants:Collection<int, UserEntitlementGrant>, created_count:int}
     */
    public function grantAtoms(
        int $userId,
        array $atoms,
        string $sourceType = UserEntitlementGrant::SOURCE_TEST_FIXTURE,
        ?string $sourceId = null,
        ?string $idempotencyKey = null,
        string $status = UserEntitlementGrant::STATUS_ACTIVE,
        ?int $orderId = null,
        ?int $orderItemId = null,
        array $metadata = [],
    ): array {
        if (! in_array($sourceType, UserEntitlementGrant::SOURCE_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported entitlement source type [{$sourceType}].");
        }

        if (! in_array($status, [UserEntitlementGrant::STATUS_ACTIVE, UserEntitlementGrant::STATUS_PENDING], true)) {
            throw new InvalidArgumentException("Unsupported grant status [{$status}].");
        }

        $normalizedAtoms = $this->accessResolver->normalizeAtoms($atoms);

        return DB::transaction(function () use (
            $userId,
            $normalizedAtoms,
            $sourceType,
            $sourceId,
            $idempotencyKey,
            $status,
            $orderId,
            $orderItemId,
            $metadata,
        ): array {
            $grants = collect();
            $createdCount = 0;

            foreach ($normalizedAtoms as $atom) {
                $lookup = [
                    'user_id' => $userId,
                    'atom_kind' => $atom['kind'],
                    'atom_id' => $atom['id'],
                    'source_type' => $sourceType,
                    'idempotency_key' => $idempotencyKey ?: $this->defaultIdempotencyKey($sourceType, $sourceId, $atom),
                ];
                $values = [
                    'source_id' => $sourceId,
                    'order_id' => $orderId,
                    'order_item_id' => $orderItemId,
                    'status' => $status,
                    'granted_at' => $status === UserEntitlementGrant::STATUS_ACTIVE ? now() : null,
                    'metadata' => $metadata,
                ];

                $grant = UserEntitlementGrant::query()->where($lookup)->first();

                if ($grant) {
                    $this->assertSameGrantIntent($grant, $values);
                } else {
                    $grant = UserEntitlementGrant::query()->create([...$lookup, ...$values]);
                    $createdCount++;
                }

                $grants->push($grant);
            }

            return [
                'grants' => $grants,
                'created_count' => $createdCount,
            ];
        });
    }

    /**
     * @param  array<int, int|string>  $orderItemIds
     * @return array{matched:int, revoked:int, already_inactive:int}
     */
    public function revokeOrderGrants(int $userId, int $orderId, array $orderItemIds, string $reason): array
    {
        if ($userId < 1 || $orderId < 1) {
            throw new InvalidArgumentException('Order grant revoke requires positive user and order ids.');
        }

        $orderItemIds = $this->positiveIds($orderItemIds);
        if ($orderItemIds === []) {
            return [
                'matched' => 0,
                'revoked' => 0,
                'already_inactive' => 0,
            ];
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Order grant revoke reason is required.');
        }

        return DB::transaction(function () use ($userId, $orderId, $orderItemIds, $reason): array {
            /** @var Collection<int, UserEntitlementGrant> $grants */
            $grants = UserEntitlementGrant::query()
                ->where('user_id', $userId)
                ->where('order_id', $orderId)
                ->whereIn('order_item_id', $orderItemIds)
                ->where('source_type', UserEntitlementGrant::SOURCE_ORDER_ITEM)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $revokableGrantIds = $grants
                ->filter(static fn (UserEntitlementGrant $grant): bool => in_array(
                    (string) $grant->status,
                    [UserEntitlementGrant::STATUS_ACTIVE, UserEntitlementGrant::STATUS_PENDING],
                    true,
                ))
                ->pluck('id')
                ->all();

            if ($revokableGrantIds !== []) {
                DB::table('user_entitlement_grants')
                    ->whereIn('id', $revokableGrantIds)
                    ->update([
                        'status' => UserEntitlementGrant::STATUS_REVOKED,
                        'revoked_at' => now(),
                        'revoked_reason' => $reason,
                    ]);
            }

            $matched = $grants->count();
            $revoked = count($revokableGrantIds);

            return [
                'matched' => $matched,
                'revoked' => $revoked,
                'already_inactive' => $matched - $revoked,
            ];
        });
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    private function positiveIds(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (int|string $id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }

    /**
     * @param  array{kind:string,id:int}  $atom
     */
    private function defaultIdempotencyKey(string $sourceType, ?string $sourceId, array $atom): string
    {
        $source = $sourceId ?: 'manual';

        return "{$sourceType}:{$source}:{$atom['kind']}:{$atom['id']}";
    }

    /**
     * @param  array{source_id:?string,order_id:?int,order_item_id:?int,status:string,granted_at:mixed,metadata:array<string, mixed>}  $values
     */
    private function assertSameGrantIntent(UserEntitlementGrant $grant, array $values): void
    {
        $existing = [
            'source_id' => $grant->source_id,
            'order_id' => $grant->order_id ? (int) $grant->order_id : null,
            'order_item_id' => $grant->order_item_id ? (int) $grant->order_item_id : null,
            'status' => $grant->status,
            'metadata' => $grant->metadata ?? [],
        ];
        $incoming = [
            'source_id' => $values['source_id'],
            'order_id' => $values['order_id'],
            'order_item_id' => $values['order_item_id'],
            'status' => $values['status'],
            'metadata' => $values['metadata'],
        ];

        if ($existing !== $incoming) {
            throw new InvalidArgumentException('Entitlement grant idempotency key was reused with different grant data.');
        }
    }
}
