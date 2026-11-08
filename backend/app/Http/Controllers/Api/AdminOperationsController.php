<?php

namespace App\Http\Controllers\Api;

use App\Services\AdminOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminOperationsController
{
    public function __construct(private readonly AdminOperationsService $operations) {}

    public function tickets(Request $request, ?int $id = null): JsonResponse
    {
        $operation = match ($request->method()) {
            'GET' => 'list', 'POST' => 'create', 'PATCH' => 'update', 'DELETE' => 'delete',
        };

        return $this->respond($this->operations->tickets(
            $request->user(), $operation, $request->all(), $id, (string) $request->header('Idempotency-Key'),
        ), $operation === 'create' ? 201 : 200);
    }

    public function tasks(Request $request, ?int $id = null): JsonResponse
    {
        $operation = match ($request->method()) {
            'GET' => 'list', 'POST' => 'create', 'PATCH' => 'update', 'DELETE' => 'delete',
        };

        return $this->respond($this->operations->tasks(
            $request->user(), $operation, $request->all(), $id, (string) $request->header('Idempotency-Key'),
        ), $operation === 'create' ? 201 : 200);
    }

    public function links(Request $request, ?int $id = null): JsonResponse
    {
        $operation = match ($request->method()) {
            'GET' => 'list', 'POST' => 'create', 'PATCH' => 'update', 'DELETE' => 'delete',
        };

        return $this->respond($this->operations->links(
            $request->user(), $operation, $request->all(), $id, (string) $request->header('Idempotency-Key'),
        ), $operation === 'create' ? 201 : 200);
    }

    public function promos(Request $request, ?int $id = null): JsonResponse
    {
        $operation = match ($request->method()) {
            'GET' => 'list', 'POST' => 'create', 'PATCH' => 'update', 'DELETE' => 'delete',
        };

        return $this->respond($this->operations->promos(
            $request->user(), $operation, $request->all(), $id, (string) $request->header('Idempotency-Key'),
        ), $operation === 'create' ? 201 : 200);
    }

    public function orders(Request $request): JsonResponse
    {
        return $this->respond($this->operations->orders($request->user(), $request->query()));
    }

    public function completeTask(Request $request, int $id): JsonResponse
    {
        return $this->respond($this->operations->completeAssignedTask(
            $request->user(), $id, $request->all(), (string) $request->header('Idempotency-Key'),
        ));
    }

    public function deleteOwnPromo(Request $request, int $id): JsonResponse
    {
        return $this->respond($this->operations->deleteOwnPromo(
            $request->user(), $id, $request->all(), (string) $request->header('Idempotency-Key'),
        ));
    }

    private function respond(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'private, no-store');
    }
}
