<?php

namespace App\Services;

use App\Models\User;
use App\Services\LegacyUsers\LegacyUserSourcePolicy;
use App\Support\Rbac;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class AdminLegacyUserQueries
{
    private const CATEGORIES = [
        'profile' => 'Исходная анкета',
        'orders' => 'Скидки, чеки и рассрочки',
        'bonuses' => 'Бонусы и партнёрские начисления',
        'support' => 'Заметки и обращения',
        'learning' => 'Учебная активность',
        'communications' => 'Анкеты и подписки',
        'promo' => 'Купоны и акции',
        'diagnostics' => 'История входов и устройств',
        'catalog' => 'Справочники старого сайта',
    ];

    private const REDACTED = '[redacted]';

    private const REDACTED_URL = '[redacted-url]';

    private const SENSITIVE_KEY_PARTS = [
        'password',
        'passwd',
        'token',
        'secret',
        'cookie',
        'signature',
        'signed',
        'hash',
        'url',
        'uri',
        'path',
    ];

    public function index(User $actor, array $filters, ?\Closure $configureQuery = null): array
    {
        $actor = $actor->fresh();
        abort_unless($actor && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, 'users.read'), 403);
        $data = $this->validateFilters($filters, ['q' => ['nullable', 'string', 'max:255'], 'unmapped_only' => ['sometimes', 'boolean']]);
        abort_if(filter_var($filters['unmapped_only'] ?? false, FILTER_VALIDATE_BOOLEAN) && $actor->role !== 'admin_full', 403);

        $query = DB::table('legacy_user_profiles');
        $this->applyProfileScope($actor, $filters, $query);
        if (filter_var($filters['unmapped_only'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNull('user_id');
        }
        $this->search($query, trim($data['q'] ?? ''));

        $query->orderBy('source_member_id');
        $configureQuery?->__invoke($query);
        $page = $query->paginate($this->perPage($actor, $filters), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $users = $this->usersForProfiles($page->getCollection()->pluck('user_id')->all());
        $page->through(fn (object $profile): array => $this->identity($profile, $users->get($profile->user_id)));

        return $this->page($page);
    }

    public function show(User $actor, array $filters, string $sourceMemberId): array
    {
        $actor = $actor->fresh();
        abort_unless($actor && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, 'users.read'), 403);
        $profile = $this->profile($actor, $filters, $sourceMemberId);
        $user = $profile->user_id ? User::find($profile->user_id) : null;
        $payload = $this->safeJson($profile->payload);
        if (! Rbac::hasPermission($actor, 'payments.read_order') && is_array($payload)) {
            unset($payload['bonus_balance']);
        }

        $manager = $profile->source_manager_id
            ? DB::table('legacy_user_records')->where('source_table', 'user')->where('source_id', $profile->source_manager_id)->value('payload')
            : null;
        $manager = is_string($manager) ? $this->safeJson($manager) : null;

        $categories = $this->categories($actor, $filters);
        $counts = $this->recordsQuery($actor, $filters, $profile->id)
            ->selectRaw('category, COUNT(*) AS total')
            ->groupBy('category')
            ->pluck('total', 'category');
        $orders = Rbac::hasPermission($actor, 'payments.read_order')
            ? DB::table('legacy_user_orders')->where('legacy_user_profile_id', $profile->id)
                ->selectRaw('source_status, currency, currency_basis, COUNT(*) AS total, SUM(amount_minor) AS amount_minor')
                ->groupBy('source_status', 'currency', 'currency_basis')
                ->orderBy('source_status')
                ->orderBy('currency')
                ->get()
                ->map(fn (object $row): array => [
                    'status' => (int) $row->source_status,
                    'currency' => $row->currency,
                    'currency_basis' => $row->currency_basis,
                    'count' => (int) $row->total,
                    'amount_minor' => (string) $row->amount_minor,
                ])->all()
            : null;

        return $this->identity($profile, $user) + [
            'source_manager' => is_array($manager) ? [
                'id' => (string) $profile->source_manager_id,
                'name' => (string) (($manager['name'] ?? null) ?: ($manager['username'] ?? 'Сотрудник старого сайта')),
            ] : null,
            'payload' => $payload,
            'issues' => $this->safeJson($profile->issues),
            'orders_by_status' => $orders,
            'access_count' => Rbac::hasPermission($actor, 'access.read')
                ? DB::table('legacy_user_accesses')->where('legacy_user_profile_id', $profile->id)->count()
                : null,
            'categories' => collect($categories)->map(fn (string $label, string $key): array => [
                'id' => $key,
                'label' => $label,
                'count' => (int) ($counts[$key] ?? 0),
            ])->values()->all(),
        ];
    }

    public function orders(User $actor, array $filters, string $sourceMemberId, LegacyUserSourcePolicy $policy): array
    {
        $actor = $actor->fresh();
        abort_unless($actor && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, 'users.read') && Rbac::hasPermission($actor, 'payments.read_order'), 403);
        $profile = $this->profile($actor, $filters, $sourceMemberId);
        abort_unless(Rbac::hasPermission($actor, 'payments.read_order'), 403);

        $page = DB::table('legacy_user_orders')
            ->where('legacy_user_profile_id', $profile->id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($actor, $filters), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $items = DB::table('legacy_user_order_items')
            ->where('legacy_user_profile_id', $profile->id)
            ->whereIn('legacy_user_order_id', $page->getCollection()->pluck('id'))
            ->orderBy('id')
            ->get()
            ->groupBy('legacy_user_order_id');
        $page->through(fn (object $order): array => [
            'id' => (int) $order->id,
            'source_order_id' => (string) $order->source_order_id,
            'source_status' => (int) $order->source_status,
            'status_label' => $policy->orderStatuses()[$order->source_status] ?? 'Неизвестный статус',
            'amount_minor' => (string) $order->amount_minor,
            'currency' => $order->currency,
            'currency_basis' => $order->currency_basis,
            'recorded_at' => $this->date($order->recorded_at),
            'payload' => $this->safeJson($order->payload),
            'issues' => $this->safeJson($order->issues),
            'items' => ($items[$order->id] ?? collect())->map(fn (object $item): array => [
                'id' => (int) $item->id,
                'source_item_id' => (string) $item->source_item_id,
                'source_order_id' => (string) $item->source_order_id,
                'source_product_id' => $item->source_product_id === null ? null : (string) $item->source_product_id,
                'title' => $item->title,
                'quantity' => (int) $item->quantity,
                'unit_amount_minor' => (string) $item->unit_amount_minor,
                'coupon_unit_amount_minor' => $item->coupon_unit_amount_minor === null ? null : (string) $item->coupon_unit_amount_minor,
                'cost_minor' => (string) $item->cost_minor,
                'payload' => $this->safeJson($item->payload),
                'issues' => $this->safeJson($item->issues),
            ])->all(),
        ]);

        return $this->page($page);
    }

    public function accesses(User $actor, array $filters, string $sourceMemberId): array
    {
        $actor = $actor->fresh();
        abort_unless($actor && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, 'users.read') && Rbac::hasPermission($actor, 'access.read'), 403);
        $profile = $this->profile($actor, $filters, $sourceMemberId);
        abort_unless(Rbac::hasPermission($actor, 'access.read'), 403);

        $page = DB::table('legacy_user_accesses')
            ->where('legacy_user_profile_id', $profile->id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($actor, $filters), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $titles = DB::table('legacy_user_records')
            ->where('source_table', 'platform_page')
            ->whereIn('source_id', $page->getCollection()->pluck('source_page_id')->filter())
            ->get()
            ->keyBy('source_id');
        $page->through(function (object $row) use ($titles): array {
            $title = isset($titles[$row->source_page_id]) ? $this->safeJson($titles[$row->source_page_id]->payload) : null;

            return [
                'id' => (int) $row->id,
                'source_access_id' => (string) $row->source_access_id,
                'source_page_id' => $row->source_page_id === null ? null : (string) $row->source_page_id,
                'source_subject_id' => $row->source_subject_id === null ? null : (string) $row->source_subject_id,
                'candidate_lesson_id' => $row->candidate_lesson_id === null ? null : (int) $row->candidate_lesson_id,
                'title' => is_array($title) && isset($title['h1']) && is_string($title['h1']) ? $title['h1'] : null,
                'mapping_status' => $row->mapping_status,
                'recorded_at' => $this->date($row->recorded_at),
                'payload' => $this->safeJson($row->payload),
                'issues' => $this->safeJson($row->issues),
            ];
        });

        return $this->page($page);
    }

    public function records(User $actor, array $filters, string $sourceMemberId): array
    {
        $actor = $actor->fresh();
        abort_unless($actor && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, 'users.read'), 403);
        $profile = $this->profile($actor, $filters, $sourceMemberId);
        $data = $this->validateFilters($filters, ['category' => ['required', Rule::in(array_keys(self::CATEGORIES))]]);
        abort_unless(array_key_exists($data['category'], $this->categories($actor, $filters)), 403);

        $page = $this->recordsQuery($actor, $filters, $profile->id)
            ->where('category', $data['category'])
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($actor, $filters), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $page->through(fn (object $row): array => $this->record($row));

        return $this->page($page);
    }

    public function unresolved(User $actor, array $filters): array
    {
        $actor = $actor->fresh();
        abort_unless($actor && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, 'users.read'), 403);
        abort_unless($actor->role === 'admin_full', 403);

        $data = $this->validateFilters($filters, ['category' => ['sometimes', Rule::in(array_keys(self::CATEGORIES))]]);
        $query = DB::table('legacy_user_records')
            ->whereNull('legacy_user_profile_id')
            ->whereIn('ownership_status', ['unlinked', 'orphan', 'ambiguous']);
        if (isset($data['category'])) {
            $query->where('category', $data['category']);
        }

        $page = $query->orderByDesc('id')->paginate($this->perPage($actor, $filters), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $page->through(fn (object $row): array => $this->record($row));

        return $this->page($page);
    }

    private function applyProfileScope(User $actor, array $filters, Builder $query): void
    {
        $actor = $actor;
        if ($actor->role === 'admin_full') {
            return;
        }

        $query->whereNotNull('user_id');
        if ($actor->role === 'admin_limited') {
            return;
        }

        $query->whereIn('user_id', User::query()
            ->where('manager_id', $actor->id)
            ->select('id'));
    }

    private function profile(User $actor, array $filters, string $sourceMemberId): object
    {
        abort_unless(preg_match('/^[1-9]\d{0,19}$/', $sourceMemberId) === 1, 404);

        $profile = DB::table('legacy_user_profiles')->where('source_member_id', $sourceMemberId)->first();
        abort_unless($profile, 404);
        if ($profile->user_id === null) {
            abort_unless($actor->role === 'admin_full', 403);
        } else {
            $user = User::findOrFail($profile->user_id);
            abort_unless(Rbac::canAccessUserScope($actor, $user), 403);
        }

        return $profile;
    }

    private function search(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $phone = User::normalizePhone($search);
        $query->where(function (Builder $query) use ($search, $like, $operator, $phone): void {
            $query->whereRaw("display_name {$operator} ? ESCAPE '!'", [$like])
                ->orWhereRaw("source_email {$operator} ? ESCAPE '!'", [$like])
                ->orWhereRaw("source_phone {$operator} ? ESCAPE '!'", [$like]);
            if (ctype_digit($search) && strlen($search) <= 20) {
                $query->orWhere('source_member_id', $search);
            }
            if ($phone && strlen($phone) >= 5) {
                $query->orWhereRaw("source_phone {$operator} ? ESCAPE '!'", ['%'.$phone.'%']);
            }
        });
    }

    private function usersForProfiles(array $userIds)
    {
        $ids = array_values(array_filter($userIds, fn ($id): bool => is_int($id) || ctype_digit((string) $id)));
        if ($ids === []) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    private function categories(User $actor, array $filters): array
    {
        $categories = self::CATEGORIES;
        if (! Rbac::hasPermission($actor, 'payments.read_order')) {
            unset($categories['orders'], $categories['bonuses'], $categories['promo']);
        }
        if (! Rbac::hasPermission($actor, 'access.read')) {
            unset($categories['learning']);
        }
        if (! Rbac::hasPermission($actor, 'audit.read')) {
            unset($categories['support'], $categories['communications']);
        }
        if ($actor->role !== 'admin_full') {
            unset($categories['diagnostics'], $categories['catalog']);
        }

        return $categories;
    }

    private function recordsQuery(User $actor, array $filters, int $profileId): Builder
    {
        return DB::table('legacy_user_records')
            ->where('legacy_user_profile_id', $profileId)
            ->where('ownership_status', 'linked')
            ->whereIn('category', array_keys($this->categories($actor, $filters)));
    }

    private function record(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'source_table' => $row->source_table,
            'source_id' => (string) $row->source_id,
            'source_order_id' => $row->source_order_id === null ? null : (string) $row->source_order_id,
            'source_actor_id' => $row->source_actor_id === null ? null : (string) $row->source_actor_id,
            'category' => $row->category,
            'ownership_status' => $row->ownership_status,
            'recorded_at' => $this->date($row->recorded_at),
            'payload' => $this->safeJson($row->payload),
            'issues' => $this->safeJson($row->issues),
        ];
    }

    private function identity(object $profile, ?User $user = null): array
    {
        return [
            'source_member_id' => (string) $profile->source_member_id,
            'user_id' => $profile->user_id === null ? null : (int) $profile->user_id,
            'name' => $profile->display_name,
            'email' => $profile->source_email,
            'phone' => $profile->source_phone,
            'mapping_status' => $profile->mapping_status,
            'source_created_at' => $this->date($profile->source_created_at),
            'migration_hold' => $user?->blocksAuthenticationForMigration() ?? true,
        ];
    }

    private function date(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->utc()->toISOString();
    }

    private function safeJson(?string $value): mixed
    {
        $decoded = json_decode($value ?: '[]', true, 64, JSON_THROW_ON_ERROR);

        return $this->redactValue($decoded);
    }

    private function redactValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey($key)) {
            $key = strtolower($key);

            return str_contains($key, 'url') || str_contains($key, 'uri') || str_contains($key, 'path')
                ? self::REDACTED_URL
                : self::REDACTED;
        }

        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $childKey => $childValue) {
                $redacted[$childKey] = $this->redactValue($childValue, is_string($childKey) ? $childKey : null);
            }

            return $redacted;
        }

        if (is_string($value) && $this->looksLikeUrl($value)) {
            return self::REDACTED_URL;
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (str_contains($key, $part)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeUrl(string $value): bool
    {
        return preg_match('~(?:https?:)?//|www\.|/(?:storage|private|uploads?|downloads?)/|(?:^|[?&])(token|signature|expires)=~i', $value) === 1;
    }

    private function perPage(User $actor, array $filters): int
    {
        $data = $this->validateFilters($filters, [
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        return (int) ($data['per_page'] ?? 20);
    }

    private function page(LengthAwarePaginator $page): array
    {
        return ['data' => $page->items(), 'meta' => [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ]];
    }
    private function validateFilters(array $filters, array $rules): array
    {
        return Validator::make($filters, $rules)->validate();
    }
}
