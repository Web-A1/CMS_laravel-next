<?php

namespace App\Services;

use App\Models\AdminUserEvent;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Models\UserProfile;
use App\Support\Rbac;
use App\Support\SellableTariff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminUserQueries
{
    private const USER_ROLES = ['user_with_purchase', 'user_no_purchase'];

    private const STAFF_ROLES = User::STAFF_ROLES;

    public function index(User $actor, array $filters, ?\Closure $configureQuery = null): array
    {
        $actor = $actor->fresh();
        abort_unless($actor && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, 'users.read'), 403);
        abort_if($actor->blocksAuthenticationForMigration(), 403);
        $scope = $this->listScope($actor, $filters);
        $query = User::query();
        if ($scope === 'staff') {
            abort_unless(in_array($actor->role, ['admin_full', 'admin_limited'], true), 403);
            $query->whereIn('role', self::STAFF_ROLES);
        } else {
            $query->whereIn('role', self::USER_ROLES);
        }
        if ($scope === 'users' && ! in_array($actor->role, ['admin_full', 'admin_limited', 'polina'], true)) {
            $query->where('manager_id', $actor->id);
        }
        $this->search($actor, $filters, $query);
        $query->orderByDesc('created_at')->orderByDesc('id');
        $configureQuery?->__invoke($query);
        if ($actor->role === 'polina') {
            $query->withGlobalScope('student_targets', fn ($scoped) => $scoped->whereIn('role', self::USER_ROLES));
        }
        $page = $query->paginate($this->perPage($actor, $filters), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $totals = $scope === 'users' && Rbac::hasPermission($actor, 'payments.read_order')
            ? Order::query()->whereIn('user_id', $page->getCollection()->modelKeys())->where('payment_status', 'paid')
                ->selectRaw('user_id, currency, COUNT(*) AS paid_count, SUM(amount_paid) AS paid_amount')
                ->groupBy('user_id', 'currency')->get()->groupBy('user_id')
            : collect();
        $legacyProfiles = $this->legacyProfilesFor($page->getCollection()->modelKeys());
        $page->through(fn (User $user) => [
            ...$user->only(['id', 'name', 'first_name', 'last_name', 'email', 'phone', 'role', 'manager_id']),
            'created_at' => $user->created_at?->toISOString(),
            'migration_hold' => $user->blocksAuthenticationForMigration(),
            'legacy_profile' => $this->legacyProfileSummary($legacyProfiles->get($user->id), $user),
            'paid_orders' => $scope === 'users' && Rbac::hasPermission($actor, 'payments.read_order')
                ? ($totals->get($user->id) ?? collect())->map(fn ($row) => [
                    'currency' => $row->currency, 'count' => (int) $row->paid_count, 'amount' => (int) $row->paid_amount,
                ])->values()->all() : null,
        ]);

        return $this->page($page);
    }

    public function show(User $actor, array $filters, User $user): array
    {
        $actor = $actor->fresh();
        abort_unless($actor && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, 'users.read'), 403);
        $user = $user->fresh();
        abort_unless($user, 404);
        $this->authorizeScope($actor, $filters, $user);
        $user->load(['profile', 'tags']);
        $consent = DB::table('registration_consents')->where('user_id', $user->id)->first([
            'privacy_policy_version', 'offer_version', 'privacy_policy_accepted_at', 'offer_accepted_at',
            'marketing_opt_in', 'marketing_accepted_at',
        ]);
        if ($consent) {
            $consent->marketing_opt_in = (bool) $consent->marketing_opt_in;
        }

        return [
            ...$user->only(['id', 'name', 'first_name', 'last_name', 'email', 'phone', 'role', 'manager_id']),
            'created_at' => $user->created_at?->toISOString(),
            'email_verified_at' => $user->email_verified_at?->toISOString(),
            'phone_verified_at' => $user->phone_verified_at?->toISOString(),
            'legacy_auto_verified' => (bool) $user->legacy_auto_verified,
            'migration_hold' => $user->blocksAuthenticationForMigration(),
            'legacy_profile' => $this->legacyProfileSummary($this->legacyProfileForUser($user->id), $user),
            'revision' => AdminUserManagement::revisionFor($user, (int) ($user->profile?->revision ?? 0)),
            'profile' => collect(UserProfile::FIELDS)->mapWithKeys(fn ($field) => [$field => $user->profile?->$field])->all(),
            'tags' => $user->tags->pluck('name')->all(),
            'manager' => $user->manager_id ? User::query()->whereKey($user->manager_id)->first(['id', 'name', 'role']) : null,
            'consent' => $consent,
        ];
    }

    public function managers(User $actor, array $filters): array
    {
        $actor = $actor->fresh();
        abort_unless($actor && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, 'users.read') && Rbac::hasPermission($actor, 'users.assign_manager'), 403);
        $query = User::query()->whereIn('role', ['manager', 'teacher']);
        $this->search($actor, $filters, $query);
        $page = $query->orderBy('name')->orderBy('id')->paginate($this->perPage($actor, $filters), ['id', 'name', 'role'], 'page', (int) ($filters['page'] ?? 1));

        return $this->page($page);
    }

    public function orders(User $actor, array $filters, User $user): array
    {
        $actor = $actor->fresh();
        abort_unless($actor && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, 'users.read') && Rbac::hasPermission($actor, 'payments.read_order'), 403);
        $user = $user->fresh();
        abort_unless($user, 404);
        $this->authorizeScope($actor, $filters, $user);
        $page = Order::query()->where('user_id', $user->id)->with('items')
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($this->perPage($actor, $filters), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $page->through(fn (Order $order) => [
            ...$order->only(['id', 'currency', 'amount_total', 'amount_discount', 'amount_final', 'amount_paid',
                'amount_refunded', 'payment_status', 'access_status', 'settlement_type']),
            'created_at' => $order->created_at?->toISOString(),
            'paid_at' => $order->paid_at?->toISOString(),
            'items' => $order->items->map(fn ($item) => [...$item->only([
                'id', 'display_title', 'quantity', 'currency', 'unit_amount', 'subtotal_amount',
                'discount_amount', 'final_amount', 'sellable_kind', 'sellable_id', 'purchased_entity_slug', 'access_status',
            ]), 'tariff' => SellableTariff::fromSnapshot($item->catalog_snapshot)])->all(),
        ]);

        return $this->page($page);
    }

    public function entitlements(User $actor, array $filters, User $user): array
    {
        $actor = $actor->fresh();
        abort_unless($actor && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, 'users.read') && Rbac::hasPermission($actor, 'access.read'), 403);
        $user = $user->fresh();
        abort_unless($user, 404);
        $this->authorizeScope($actor, $filters, $user);
        $page = UserEntitlementGrant::query()->forUser($user->id)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($this->perPage($actor, $filters), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $lessonIds = $page->getCollection()->where('atom_kind', 'lesson')->pluck('atom_id');
        $lessons = Lesson::query()->whereIn('id', $lessonIds)->get(['id', 'title', 'course_id', 'is_active'])->keyBy('id');
        $page->through(function (UserEntitlementGrant $grant) use ($lessons): array {
            $lesson = $grant->atom_kind === 'lesson' ? $lessons->get($grant->atom_id) : null;

            return [
                ...$grant->only(['id', 'atom_kind', 'atom_id', 'status', 'source_type', 'source_id',
                    'order_id', 'order_item_id', 'revoked_reason']),
                'granted_at' => $grant->granted_at?->toISOString(),
                'expires_at' => $grant->expires_at?->toISOString(),
                'revoked_at' => $grant->revoked_at?->toISOString(),
                'effective_active' => $grant->status === 'active' && (! $grant->expires_at || $grant->expires_at->isFuture()),
                'title' => $lesson?->title,
                'content_active' => $lesson ? (bool) $lesson->is_active : null,
                'course_id' => $lesson?->course_id,
            ];
        });

        return $this->page($page);
    }

    public function courses(User $actor, array $filters, User $user, AccessResolver $resolver): array
    {
        $actor = $actor->fresh();
        abort_unless($actor && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, 'users.read') && Rbac::hasPermission($actor, 'access.read'), 403);
        $user = $user->fresh();
        abort_unless($user, 404);
        $this->authorizeScope($actor, $filters, $user);
        $page = Course::query()->whereHas('lessons', fn ($query) => $query->whereIn('id',
            UserEntitlementGrant::query()->forUser($user->id)->where('atom_kind', 'lesson')->select('atom_id')))
            ->orderBy('id')->paginate($this->perPage($actor, $filters), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $coverage = $resolver->coursesForUser($user->id, $page->getCollection());
        $page->through(fn (Course $course) => ['id' => $course->id, 'title' => $course->title, ...$coverage[$course->id]]);

        return $this->page($page);
    }

    public function events(User $actor, array $filters, User $user): array
    {
        $actor = $actor->fresh();
        abort_unless($actor && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, 'users.read') && Rbac::hasPermission($actor, 'audit.read'), 403);
        $user = $user->fresh();
        abort_unless($user, 404);
        $this->authorizeScope($actor, $filters, $user);
        $page = AdminUserEvent::query()->where('user_id', $user->id)
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($this->perPage($actor, $filters), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $page->through(fn ($event) => [
            ...$event->only(['id', 'actor_id', 'actor_name', 'type', 'comment', 'changes']),
            'created_at' => $event->created_at->toISOString(),
        ]);

        return $this->page($page);
    }

    private function authorizeScope(User $actor, array $filters, User $user): void
    {
        abort_if($actor->blocksAuthenticationForMigration(), 403);
        abort_if($actor->role === 'polina' && ($filters['scope'] ?? null) === 'staff', 403);
        abort_unless(Rbac::canAccessUserScope($actor, $user), 403);
    }

    private function search(User $actor, array $filters, Builder $query): void
    {
        $data = $this->validateFilters($filters, ['q' => ['nullable', 'string', 'max:255']]);
        $q = trim($data['q'] ?? '');
        if ($q === '') {
            return;
        }
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
        $phone = User::normalizePhone($q);
        $query->where(function (Builder $search) use ($q, $like, $phone, $operator): void {
            $search->whereRaw("name {$operator} ? ESCAPE '!'", [$like])
                ->orWhereRaw("email {$operator} ? ESCAPE '!'", [$like])
                ->orWhereRaw("phone {$operator} ? ESCAPE '!'", [$like]);
            if (ctype_digit($q) && strlen($q) < 19) {
                $search->orWhere('id', (int) $q);
            }
            if ($phone && strlen($phone) >= 5) {
                $search->orWhereLike('phone', '%'.$phone.'%');
            }
            $search->orWhereIn('id', DB::table('legacy_user_profiles')
                ->select('user_id')
                ->whereNotNull('user_id')
                ->where(function ($legacy) use ($q, $like, $phone, $operator): void {
                    $legacy->whereRaw("display_name {$operator} ? ESCAPE '!'", [$like])
                        ->orWhereRaw("source_email {$operator} ? ESCAPE '!'", [$like])
                        ->orWhereRaw("source_phone {$operator} ? ESCAPE '!'", [$like]);
                    if (ctype_digit($q) && strlen($q) <= 20) {
                        $legacy->orWhere('source_member_id', $q);
                    }
                    if ($phone && strlen($phone) >= 5) {
                        $legacy->orWhereRaw("source_phone {$operator} ? ESCAPE '!'", ['%'.$phone.'%']);
                    }
                }));
        });
    }

    private function listScope(User $actor, array $filters): string
    {
        $data = $this->validateFilters($filters, [
            'scope' => ['sometimes', 'string', 'in:users,staff'],
        ]);

        return $data['scope'] ?? 'users';
    }

    private function legacyProfilesFor(array $userIds)
    {
        $ids = array_values(array_filter($userIds, fn ($id): bool => is_int($id) || ctype_digit((string) $id)));
        if ($ids === []) {
            return collect();
        }

        return DB::table('legacy_user_profiles')
            ->whereIn('user_id', $ids)
            ->get()
            ->keyBy('user_id');
    }

    private function legacyProfileForUser(int $userId): ?object
    {
        return DB::table('legacy_user_profiles')->where('user_id', $userId)->first();
    }

    private function legacyProfileSummary(?object $profile, User $user): ?array
    {
        if (! $profile) {
            return null;
        }

        return [
            'source_member_id' => (string) $profile->source_member_id,
            'user_id' => $user->id,
            'name' => $profile->display_name,
            'email' => $profile->source_email,
            'phone' => $profile->source_phone,
            'mapping_status' => $profile->mapping_status,
            'source_created_at' => $this->date($profile->source_created_at),
            'migration_hold' => $user->blocksAuthenticationForMigration(),
        ];
    }

    private function date(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->utc()->toISOString();
    }

    private function perPage(User $actor, array $filters): int
    {
        $data = $this->validateFilters($filters, [
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        return (int) ($data['per_page'] ?? 20);
    }

    private function page(LengthAwarePaginator $page): array
    {
        return ['data' => $page->items(), 'meta' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
        ]];
    }

    private function validateFilters(array $filters, array $rules): array
    {
        return Validator::make($filters, $rules)->validate();
    }
}
