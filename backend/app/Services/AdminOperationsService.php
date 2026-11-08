<?php

namespace App\Services;

use App\Models\AdminOperationEvent;
use App\Models\AdminShortLink;
use App\Models\AdminSupportTicket;
use App\Models\AdminTask;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\Support\SupportOperations;
use App\Support\ImpersonationContext;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminOperationsService
{
    public function tickets(User $actor, string $operation, array $input = [], ?int $id = null, string $key = '', ?\Closure $configureQuery = null): array
    {
        abort_unless(in_array($operation, ['list', 'create', 'update', 'delete'], true), 405);
        $actor = $this->authorize($actor, 'support.'.($operation === 'list' ? 'read' : 'write'), $operation !== 'list');
        abort_if($operation === 'delete', 405, 'Удаление переписки недоступно.');
        $support = app(SupportOperations::class);
        if ($operation === 'update') {
            return ['data' => $support->change($actor, $id, $input, $key)];
        }
        if ($operation === 'create') {
            $data = validator($input, ['subject' => ['sometimes', 'string', 'max:180'], 'description' => ['required', 'string', 'max:20000'], 'contact_email' => ['nullable', 'email:rfc', 'max:254'], 'user_id' => ['prohibited'], 'assignee_id' => ['prohibited']])->validate();

            return DB::transaction(function () use ($support, $actor, $data, $key): array {
                abort_unless($key !== '' && strlen($key) <= 128, 422);
                abort_if(AdminOperationEvent::where('idempotency_key', $key)->exists(), 409);
                $ticket = $support->createTicket($data['contact_email'] ?? null, $data['description'], 'admin', isset($data['subject']) ? ['subject' => $data['subject']] : []);
                $support->inbound($ticket, $data['description'], $data['contact_email'] ?? '');
                $support->audit($actor, 'create', $ticket, $key, []);

                return ['data' => $ticket->fresh()];
            });
        }

        return $this->resource($actor, $operation, $input, $key, AdminSupportTicket::class, $id, [
            'subject' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:20000'],
            'status' => ['sometimes', 'in:open,pending,resolved,closed'],
            'priority' => ['sometimes', 'in:low,normal,high'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'metadata' => ['nullable', 'array'],
        ], $configureQuery);
    }

    public function tasks(User $actor, string $operation, array $input = [], ?int $id = null, string $key = '', ?\Closure $configureQuery = null, ?array $expectedSnapshot = null): array
    {
        abort_unless(in_array($operation, ['list', 'create', 'update', 'delete'], true), 405);
        $actor = $this->authorize($actor, 'tasks.'.($operation === 'list' ? 'read' : 'write'), $operation !== 'list');

        return $this->resource($actor, $operation, $input, $key, AdminTask::class, $id, [
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:20000'],
            'status' => ['sometimes', 'in:open,in_progress,done,cancelled'],
            'priority' => ['sometimes', 'in:low,normal,high'],
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
            'created_by' => ['prohibited'],
        ], $configureQuery, $expectedSnapshot);
    }

    public function links(User $actor, string $operation, array $input = [], ?int $id = null, string $key = '', ?\Closure $configureQuery = null, ?array $expectedSnapshot = null): array
    {
        abort_unless(in_array($operation, ['list', 'create', 'update', 'delete'], true), 405);
        $actor = $this->authorize($actor, 'short-links.'.($operation === 'list' ? 'read' : 'write'), $operation !== 'list');

        return $this->resource($actor, $operation, $input, $key, AdminShortLink::class, $id, [
            'slug' => ['required', 'regex:/^[a-z0-9][a-z0-9_\/-]{1,79}$/D', 'not_regex:/\.\./'],
            'target_path' => ['required', 'string', 'max:512', 'starts_with:/'],
            'is_active' => ['sometimes', 'boolean'],
            'created_by' => ['prohibited'],
        ], $configureQuery, $expectedSnapshot);
    }

    public function promos(User $actor, string $operation, array $input = [], ?int $id = null, string $key = '', ?\Closure $configureQuery = null): array
    {
        abort_unless(in_array($operation, ['list', 'create', 'update'], true), 405);
        $permission = 'promos.'.match ($operation) {
            'list' => 'read', 'create' => 'create', default => 'write',
        };
        $actor = $this->authorize($actor, $permission, $operation !== 'list');
        if ($operation === 'list') {
            $query = PromoCode::query()->latest('id');
            if (isset($input['status']) && $input['status'] !== '') {
                $query->where('status', (string) $input['status']);
            }
            $configureQuery?->__invoke($query);
            $page = $query->paginate(min(max((int) ($input['per_page'] ?? 25), 1), 100), ['*'], 'page', max(1, (int) ($input['page'] ?? 1)));
            $page->through(function (PromoCode $promo): array {
                return [
                    ...$promo->only(['id', 'created_by', 'status', 'discount_kind', 'discount_minor', 'discount_bps', 'currency', 'starts_at', 'expires_at', 'activation_limit', 'per_user_limit']),
                    'usage_count' => $promo->reservations()->where('status', 'committed')->count(),
                ];
            });

            return ['data' => $page];
        }

        $key = trim($key);
        abort_unless($key !== '' && strlen($key) <= 128, 422, 'Для изменения требуется Idempotency-Key.');
        abort_if(AdminOperationEvent::query()->where('idempotency_key', $key)->exists(), 409, 'Операция с таким ключом уже выполнена.');

        $isCreate = $operation === 'create';
        $promo = $isCreate ? null : PromoCode::query()->findOrFail($id);
        $rules = [
            'created_by' => ['prohibited'],
            'deleted_at' => ['prohibited'],
            'code' => [$isCreate ? 'required' : 'sometimes', 'string', 'min:3', 'max:80', 'regex:/^[A-Za-z0-9_-]+$/'],
            'status' => ['sometimes', 'in:active,revoked,promotion_violation_review'],
            'discount_kind' => [$isCreate ? 'required' : 'sometimes', 'in:fixed_minor,percent_bps'],
            'discount_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'discount_bps' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => [$isCreate ? 'required' : 'sometimes', 'date'],
            'activation_limit' => [$isCreate ? 'required' : 'sometimes', 'integer', 'min:1'],
            'per_user_limit' => [$isCreate ? 'required' : 'sometimes', 'integer', 'min:1'],
            'bound_email_hash' => ['sometimes', 'nullable', 'string', 'size:64'],
        ];
        $data = validator($input, $rules)->validate();
        $code = array_key_exists('code', $data) ? strtoupper(trim((string) $data['code'])) : null;
        unset($data['code']);
        if ($code !== null) {
            $data['code_hash'] = hash('sha256', $code);
        }
        $data['rules_version'] = 'admin-v1';
        $data['currency'] ??= $promo?->currency ?? 'RUB';
        $kind = $data['discount_kind'] ?? $promo?->discount_kind;
        if ($kind === 'percent_bps') {
            abort_unless(array_key_exists('discount_bps', $data) ? $data['discount_bps'] !== null : $promo?->discount_bps !== null, 422, 'Для процентной скидки укажите discount_bps.');
            $data['discount_minor'] = null;
        } elseif ($kind === 'fixed_minor') {
            abort_unless(array_key_exists('discount_minor', $data) ? $data['discount_minor'] !== null : $promo?->discount_minor !== null, 422, 'Для фиксированной скидки укажите discount_minor.');
            $data['discount_bps'] = null;
        }

        if ($isCreate) {
            $promo = DB::transaction(function () use ($data, $actor, $key): PromoCode {
                $actor = $this->authorize($actor, 'promos.create', true);
                abort_if(AdminOperationEvent::query()->where('idempotency_key', $key)->exists(), 409);
                $promo = new PromoCode($data);
                $promo->created_by = $actor->id;
                $promo->save();
                AdminOperationEvent::query()->create(['actor_id' => $actor->id, 'operation' => 'create', 'resource' => 'PromoCode', 'resource_id' => $promo->id, 'idempotency_key' => $key, 'payload' => $this->safeAuditPayload('PromoCode', $promo->toArray())]);

                return $promo;
            });

            return ['data' => ['id' => $promo->id, 'code' => $code, ...$promo->only(['status', 'discount_kind', 'discount_minor', 'discount_bps', 'currency', 'starts_at', 'expires_at', 'activation_limit', 'per_user_limit'])]];
        }

        unset($data['code_hash']);
        $promo = DB::transaction(function () use ($data, $promo, $actor, $key): PromoCode {
            $actor = $this->authorize($actor, 'promos.write', true);
            abort_if(AdminOperationEvent::query()->where('idempotency_key', $key)->exists(), 409);
            $promo->fill($data)->save();
            AdminOperationEvent::query()->create(['actor_id' => $actor->id, 'operation' => 'update', 'resource' => 'PromoCode', 'resource_id' => $promo->id, 'idempotency_key' => $key, 'payload' => $this->safeAuditPayload('PromoCode', $promo->toArray())]);

            return $promo;
        });

        return ['data' => $this->promoPayload($promo)];
    }

    public function orders(User $actor, array $input = [], ?\Closure $configureQuery = null): array
    {
        $this->authorize($actor, 'payments.read_order');
        $query = Order::query()->with('user:id,name,email')->latest('id');
        foreach (['payment_status', 'access_status', 'currency'] as $field) {
            if (isset($input[$field]) && $input[$field] !== '') {
                $query->where($field, (string) $input[$field]);
            }
        }
        if (isset($input['from']) && $input['from'] !== '') {
            $query->where('created_at', '>=', Carbon::parse($input['from'])->startOfDay());
        }
        if (isset($input['to']) && $input['to'] !== '') {
            $query->where('created_at', '<', Carbon::parse($input['to'])->addDay()->startOfDay());
        }
        $configureQuery?->__invoke($query);
        $page = $query->paginate(min(max((int) ($input['per_page'] ?? 25), 1), 100), ['*'], 'page', max(1, (int) ($input['page'] ?? 1)));
        $page->through(fn (Order $order): array => [
            ...$order->only(['id', 'currency', 'amount_total', 'amount_discount', 'amount_final', 'amount_paid', 'amount_refunded', 'payment_status', 'access_status', 'settlement_type']),
            'created_at' => $order->created_at?->toISOString(),
            'paid_at' => $order->paid_at?->toISOString(),
            'user' => $order->user?->only(['id', 'name', 'email']),
        ]);

        return ['data' => $page];
    }

    /** @param class-string<Model> $class */
    private function resource(User $actor, string $operation, array $input, string $key, string $class, ?int $id, array $rules, ?\Closure $configureQuery = null, ?array $expectedSnapshot = null): array
    {
        if ($operation === 'list') {
            $query = $class::query()->latest('id');
            if ($class === AdminSupportTicket::class && isset($input['status']) && $input['status'] !== '') {
                $query->where('status', (string) $input['status']);
            }
            if ($class === AdminTask::class && isset($input['status']) && $input['status'] !== '') {
                $query->where('status', (string) $input['status']);
            }
            if ($class === AdminShortLink::class && array_key_exists('active', $input)) {
                $query->where('is_active', filter_var($input['active'], FILTER_VALIDATE_BOOLEAN));
            }

            $configureQuery?->__invoke($query);
            if ($class === AdminTask::class && ! Rbac::hasPermission($actor, 'tasks.write')) {
                $query->withGlobalScope('assigned_staff', fn ($scoped) => $scoped->where('assignee_id', $actor->id));
            }
            $perPage = min(max((int) ($input['per_page'] ?? 25), 1), 100);

            return ['data' => $query->paginate($perPage, ['*'], 'page', max(1, (int) ($input['page'] ?? 1)))];
        }

        $key = trim($key);
        abort_unless($key !== '' && strlen($key) <= 128, 422, 'Для изменения требуется Idempotency-Key.');
        $existing = AdminOperationEvent::query()->where('idempotency_key', $key)->first();
        abort_if($existing !== null, 409, 'Операция с таким ключом уже выполнена.');

        if ($operation === 'delete') {
            abort_unless($id !== null, 404);
            $payload = DB::transaction(function () use ($class, $id, $actor, $key): array {
                if ($class === AdminTask::class) {
                    $actor = $this->lockTaskUsers($actor);
                }
                $actor = $this->authorize($actor, match ($class) {
                    AdminTask::class => 'tasks.write',
                    AdminShortLink::class => 'short-links.write',
                    default => 'support.write',
                }, true);
                $model = $class::query()->lockForUpdate()->findOrFail($id);
                $model->delete();
                $payload = ['id' => $id, 'deleted' => true];
                $this->audit($actor, $key, 'delete', $class, $id, $payload);

                return $payload;
            });

            return ['data' => $payload];
        }

        $data = validator($input, $rules)->validate();
        if ($class === AdminTask::class && array_key_exists('description', $data) && $data['description'] === '') {
            $data['description'] = null;
        }
        if (in_array($class, [AdminTask::class, AdminShortLink::class], true) && $operation === 'create') {
            $data['created_by'] = $actor->id;
        }

        if ($operation === 'create') {
            if ($class === AdminShortLink::class) {
                $this->assertSafeTargetPath((string) $data['target_path']);
            }
            $model = DB::transaction(function () use ($class, $data, $actor, $key): Model {
                if ($class === AdminTask::class) {
                    $actor = $this->lockTaskUsers($actor, $data);
                }
                $actor = $this->authorize($actor, match ($class) {
                    AdminTask::class => 'tasks.write',
                    AdminShortLink::class => 'short-links.write',
                    default => 'support.write',
                }, true);
                $model = $class::query()->create($data);
                $this->audit($actor, $key, 'create', $class, (int) $model->getKey(), $model->toArray());

                return $model;
            });

            return ['data' => $model];
        }

        abort_unless($id !== null, 404);
        if ($class === AdminShortLink::class && array_key_exists('target_path', $data)) {
            $this->assertSafeTargetPath((string) $data['target_path']);
        }
        $model = DB::transaction(function () use ($data, $actor, $key, $class, $id, $expectedSnapshot): Model {
            $actor = $class === AdminTask::class
                ? $this->lockTaskUsers($actor, $data)
                : User::query()->lockForUpdate()->findOrFail($actor->id);
            $actor = $this->authorize($actor, match ($class) {
                AdminTask::class => 'tasks.write',
                AdminShortLink::class => 'short-links.write',
                default => 'support.write',
            }, true);
            $model = $class::query()->lockForUpdate()->findOrFail($id);
            abort_if(AdminOperationEvent::query()->where('idempotency_key', $key)->exists(), 409, 'Операция с таким ключом уже выполнена.');
            if ($expectedSnapshot !== null) {
                $fields = match ($class) {
                    AdminTask::class => count($expectedSnapshot) === 2 ? ['title', 'description'] : ['title', 'description', 'assignee_id', 'status'],
                    AdminShortLink::class => ['slug', 'target_path'],
                    default => [],
                };
                abort_unless(count($expectedSnapshot) === count($fields) && ! array_diff($fields, array_keys($expectedSnapshot)), 422);
                foreach ($fields as $field) {
                    abort_unless($model->getAttribute($field) === $expectedSnapshot[$field], 409, 'Запись изменилась. Откройте действие заново.');
                }
            }
            $model->fill($data);
            if (! $model->isDirty()) {
                return $model;
            }
            $model->save();
            $this->audit($actor, $key, 'update', $class, $id, $model->toArray());

            return $model;
        });

        return ['data' => $model];
    }

    private function lockTaskUsers(User $actor, array $data = []): User
    {
        $ids = [$actor->id];
        if (isset($data['assignee_id'])) {
            $ids[] = (int) $data['assignee_id'];
        }
        $users = User::query()->whereIn('id', array_unique($ids))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if (isset($data['assignee_id'])) {
            $assignee = $users->get((int) $data['assignee_id']);
            abort_unless($assignee && $assignee->usesAdminWorkspace() && ! $assignee->blocksAuthenticationForMigration(), 422, 'Исполнитель должен быть действующим сотрудником.');
        }

        return $users->get($actor->id) ?? abort(403);
    }

    public function completeAssignedTask(User $actor, int $id, array $input = [], string $key = ''): array
    {
        $actor = $this->authorize($actor, 'tasks.complete_assigned', true);

        return DB::transaction(function () use ($actor, $id, $input, $key): array {
            $actor = $this->lockTaskUsers($actor);
            $actor = $this->authorize($actor, 'tasks.complete_assigned', true);
            $task = AdminTask::query()->lockForUpdate()->findOrFail($id);
            abort_unless($task->assignee_id === $actor->id, 403);
            abort_unless(in_array($task->status, ['open', 'in_progress'], true), 409, 'Задача уже закрыта.');
            abort_unless($input === [], 422, 'Завершение задачи не принимает поля для изменения.');
            $key = trim($key);
            abort_unless($key !== '' && strlen($key) <= 128, 422);
            abort_if(AdminOperationEvent::query()->where('idempotency_key', $key)->exists(), 409);
            $task->status = 'done';
            $task->save();
            $this->audit($actor, $key, 'complete', AdminTask::class, $id, $task->toArray());

            return ['data' => $task];
        });
    }

    public function deleteOwnPromo(User $actor, int $id, array $input = [], string $key = ''): array
    {
        $actor = $this->authorize($actor, 'promos.delete_own', true);

        return DB::transaction(function () use ($actor, $id, $input, $key): array {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $actor = $this->authorize($actor, 'promos.delete_own', true);
            $promo = PromoCode::withTrashed()->lockForUpdate()->findOrFail($id);
            abort_unless($promo->created_by === $actor->id, 403);
            abort_unless($input === [], 422);
            $key = trim($key);
            abort_unless($key !== '' && strlen($key) <= 128, 422);
            abort_if(AdminOperationEvent::query()->where('idempotency_key', $key)->exists() || $promo->trashed(), 409);
            $promo->delete();
            $payload = ['id' => $id, 'deleted' => true];
            $this->audit($actor, $key, 'delete', PromoCode::class, $id, $payload);

            return ['data' => $payload];
        });
    }

    private function assertSafeTargetPath(string $target): void
    {
        $path = parse_url($target, PHP_URL_PATH);
        $isInternalPath = str_starts_with($target, '/')
            && ! str_starts_with($target, '//')
            && ! str_contains($target, '\\')
            && is_string($path)
            && ! preg_match('#^/(?:api(?:/|$)|admin(?:/|$)|(?:auth/)?logout(?:/|$))#i', $path);

        if (! $isInternalPath) {
            throw ValidationException::withMessages(['target_path' => 'Ссылка должна вести на безопасный внутренний путь.']);
        }
    }

    /** @return array<string, mixed> */
    private function promoPayload(PromoCode $promo): array
    {
        return [
            ...$promo->only(['id', 'status', 'discount_kind', 'discount_minor', 'discount_bps', 'currency', 'starts_at', 'expires_at', 'activation_limit', 'per_user_limit']),
            'usage_count' => $promo->reservations()->where('status', 'committed')->count(),
        ];
    }

    private function audit(User $actor, string $key, string $operation, string $class, ?int $id, array $payload): void
    {
        AdminOperationEvent::query()->create([
            'actor_id' => $actor->id,
            'operation' => strtolower($operation),
            'resource' => class_basename($class),
            'resource_id' => $id,
            'idempotency_key' => $key,
            'payload' => $this->safeAuditPayload(class_basename($class), $payload),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function safeAuditPayload(string $resource, array $payload): array
    {
        $allowed = ['id', 'status', 'priority', 'slug', 'target_path', 'is_active', 'created_by', 'assignee_id', 'due_at', 'discount_kind', 'discount_minor', 'discount_bps', 'currency', 'expires_at', 'activation_limit', 'per_user_limit', 'rules_version', 'deleted'];

        return array_intersect_key($payload, array_flip($allowed));
    }

    private function authorize(User $actor, string $permission, bool $write = false): User
    {
        $current = User::query()->find($actor->getKey());
        abort_unless($current && ! $current->blocksAuthenticationForMigration()
            && Rbac::hasPermission($current, 'admin.access') && Rbac::hasPermission($current, $permission), 403);
        abort_if($write && ImpersonationContext::isActive(request()), 403);

        return $current;
    }
}
