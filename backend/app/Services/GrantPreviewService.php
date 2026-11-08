<?php

namespace App\Services;

use App\Models\SellableEntity;
use DomainException;
use InvalidArgumentException;

class GrantPreviewService
{
    public function __construct(private readonly AccessResolver $accessResolver) {}

    /**
     * @return array{
     *     version:int,
     *     sellable_id:int,
     *     sellable_kind:string,
     *     entity_type:string,
     *     entity_id:int|null,
     *     sku:string,
     *     atoms:array<int, array{kind:string,id:int}>,
     *     resolved_from:string,
     *     resolved_at:string
     * }
     */
    public function preview(SellableEntity $sellableEntity, ?int $version = null): array
    {
        $version ??= (int) $sellableEntity->grantRules()->max('grant_snapshot_version');

        if ($version < 1) {
            throw new DomainException('SELLABLE_HAS_NO_GRANT_RULES');
        }

        $rules = $sellableEntity->grantRules()
            ->where('grant_snapshot_version', $version)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        try {
            $atoms = $this->accessResolver->normalizeAtoms($rules
                ->map(fn ($rule): array => [
                    'kind' => (string) $rule->atom_kind,
                    'id' => (int) $rule->atom_id,
                ])
                ->all());
        } catch (InvalidArgumentException $e) {
            throw new DomainException('SELLABLE_GRANT_RULE_INVALID', previous: $e);
        }

        if ($atoms === []) {
            throw new DomainException('SELLABLE_HAS_NO_GRANT_RULES');
        }

        return [
            'version' => $version,
            'sellable_id' => (int) $sellableEntity->id,
            'sellable_kind' => (string) $sellableEntity->sellable_kind,
            'entity_type' => (string) $sellableEntity->entity_type,
            'entity_id' => $sellableEntity->entity_id === null ? null : (int) $sellableEntity->entity_id,
            'sku' => (string) $sellableEntity->sku,
            'atoms' => $atoms,
            'resolved_from' => 'sellable_grant_rules',
            'resolved_at' => now()->toISOString(),
        ];
    }

    /**
     * @param  array{atoms:array<int, array{kind:string,id:int}>}  $preview
     * @return array<int, array{kind:string,id:int}>
     */
    public function atomsForGrantSnapshot(array $preview): array
    {
        return $this->accessResolver->normalizeAtoms($preview['atoms']);
    }
}
