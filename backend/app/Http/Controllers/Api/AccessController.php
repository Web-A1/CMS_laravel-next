<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserEntitlementGrant;
use App\Services\AccessResolver;
use App\Services\EntitlementGrantService;
use App\Support\ImpersonationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class AccessController extends Controller
{
    public function check(Request $request, AccessResolver $accessResolver): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(AccessResolver::ENTITY_TYPES)],
            'entity_id' => ['exclude_if:entity_type,box', 'required', 'integer', 'min:1'],
            'atoms' => ['required_if:entity_type,box', 'array', 'min:1'],
            'atoms.*.kind' => ['required_with:atoms', 'string', Rule::in(UserEntitlementGrant::ATOM_KINDS)],
            'atoms.*.id' => ['required_with:atoms', 'integer', 'min:1'],
        ]);

        try {
            $result = $accessResolver->check(
                ImpersonationContext::effectiveUser($request),
                (string) $validated['entity_type'],
                $validated['entity_id'] ?? null,
                $validated['atoms'] ?? []
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'ACCESS_INVALID_REQUEST',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        }

        return response()->json(['data' => $result]);
    }

    public function grant(Request $request, EntitlementGrantService $grantService): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'atoms' => ['required', 'array', 'min:1'],
            'atoms.*.kind' => ['required', 'string', Rule::in(UserEntitlementGrant::ATOM_KINDS)],
            'atoms.*.id' => ['required', 'integer', 'min:1'],
            'source_type' => ['sometimes', 'string', Rule::in(UserEntitlementGrant::SOURCE_TYPES)],
            'source_id' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'string', Rule::in([
                UserEntitlementGrant::STATUS_ACTIVE,
                UserEntitlementGrant::STATUS_PENDING,
            ])],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'order_item_id' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
        ]);

        try {
            $result = $grantService->grantAtoms(
                userId: (int) $validated['user_id'],
                atoms: $validated['atoms'],
                sourceType: (string) ($validated['source_type'] ?? UserEntitlementGrant::SOURCE_TEST_FIXTURE),
                sourceId: $validated['source_id'] ?? null,
                idempotencyKey: $validated['idempotency_key'] ?? null,
                status: (string) ($validated['status'] ?? UserEntitlementGrant::STATUS_ACTIVE),
                orderId: isset($validated['order_id']) ? (int) $validated['order_id'] : null,
                orderItemId: isset($validated['order_item_id']) ? (int) $validated['order_item_id'] : null,
                metadata: $validated['metadata'] ?? [],
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'error' => [
                    'code' => 'ACCESS_GRANT_INVALID_REQUEST',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        }

        return response()->json([
            'created_count' => $result['created_count'],
            'data' => $result['grants']->map(fn (UserEntitlementGrant $grant): array => [
                'id' => $grant->id,
                'user_id' => $grant->user_id,
                'atom_kind' => $grant->atom_kind,
                'atom_id' => $grant->atom_id,
                'status' => $grant->status,
                'source_type' => $grant->source_type,
                'source_id' => $grant->source_id,
                'idempotency_key' => $grant->idempotency_key,
            ])->values(),
        ], 201);
    }
}
