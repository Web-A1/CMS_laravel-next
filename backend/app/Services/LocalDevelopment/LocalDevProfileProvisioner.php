<?php

namespace App\Services\LocalDevelopment;

use App\Exceptions\LocalDevProfilesException;
use App\Models\AdminUserEvent;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LocalDevProfileProvisioner
{
    private const FIXTURE_OWNER = 'local_dev_profiles_v1';

    private const IDEMPOTENCY_KEY = 'local-dev-profiles-v1';

    private const PASSWORD = 'password';

    /**
     * @return array{profiles:int,all_lessons:int,partial_lessons:int,empty_lessons:int}
     */
    public function provision(): array
    {
        $this->assertSafeTarget();

        return DB::transaction(function (): array {
            $allLessonIds = $this->activeLearningLessonQuery()
                ->orderBy('lessons.id')
                ->pluck('lessons.id')
                ->map(static fn (int|string $id): int => (int) $id)
                ->all();

            if ($allLessonIds === []) {
                throw new LocalDevProfilesException('fixture_catalog_mismatch');
            }

            $partialLessonIds = $this->resolvePartialLessonIds();
            $profiles = $this->profiles();
            $users = [];

            foreach ($profiles as $key => $profile) {
                $users[$key] = $this->upsertProfile($profile);
            }

            $users['all']->forceFill(['manager_id' => $users['reviewer']->id])->save();

            $this->reconcileGrants($users['all'], $allLessonIds, 'all');
            $this->reconcileGrants($users['partial'], $partialLessonIds, 'partial');
            $this->reconcileGrants($users['empty'], [], 'empty');
            $this->reconcileGrants($users['reviewer'], [], 'reviewer');

            return [
                'profiles' => count($users),
                'all_lessons' => count($allLessonIds),
                'partial_lessons' => count($partialLessonIds),
                'empty_lessons' => 0,
            ];
        }, 3);
    }

    private function assertSafeTarget(): void
    {
        if (! app()->environment('local')) {
            throw new LocalDevProfilesException('local_environment_required');
        }

        $connectionName = DB::getDefaultConnection();
        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection)) {
            throw new LocalDevProfilesException('local_database_required');
        }

        $driver = (string) ($connection['driver'] ?? '');
        $database = (string) ($connection['database'] ?? '');

        if ($driver === 'sqlite' && $database === ':memory:') {
            return;
        }

        $url = $connection['url'] ?? null;
        $host = $connection['host'] ?? null;
        $allowedDatabase = (string) config('local_development.dev_profiles.database.database');
        $allowedHosts = config('local_development.dev_profiles.database.hosts', []);

        if (
            $driver !== 'pgsql'
            || (is_string($url) && trim($url) !== '')
            || ! is_string($host)
            || ! is_array($allowedHosts)
            || ! in_array($host, $allowedHosts, true)
            || $database !== $allowedDatabase
        ) {
            throw new LocalDevProfilesException('local_database_required');
        }
    }

    /** Prepare a separate, owned set; never promote the regular learner/reviewer fixtures. */
    public function provisionAdminUsers(): array
    {
        $this->assertSafeTarget();

        return DB::transaction(function (): array {
            $lessons = $this->activeLearningLessonQuery()->orderBy('lessons.id')->limit(3)->get();
            if ($lessons->count() !== 3) {
                throw new LocalDevProfilesException('fixture_catalog_mismatch');
            }
            $definitions = [
                'admin' => ['Администратор', 'admin_full'],
                'limited' => ['Сотрудник', 'admin_limited'],
                'manager' => ['Менеджер', 'manager'],
                'own' => ['Анна Тестовая', 'user_with_purchase'],
                'other' => ['Борис Тестовый', 'user_no_purchase'],
            ];
            $users = [];
            foreach ($definitions as $key => [$name, $role]) {
                $email = "users-{$key}@test.test";
                $owner = "admin_users_v1:{$key}";
                $existing = User::query()->where('email', $email)->lockForUpdate()->first();
                if ($existing) {
                    $owned = AdminUserEvent::query()->where('user_id', $existing->id)->where('type', 'local_fixture')
                        ->where('changes->fixture_key->after', $owner)->exists();
                    if (! $owned) {
                        throw new LocalDevProfilesException('fixture_user_ownership_conflict');
                    }
                    $users[$key] = $existing;

                    continue;
                }
                $phone = '+799900001'.str_pad((string) count($users), 2, '0', STR_PAD_LEFT);
                if (User::query()->where('phone', $phone)->exists()) {
                    throw new LocalDevProfilesException('fixture_user_ownership_conflict');
                }
                $users[$key] = $this->upsertProfile(['email' => $email, 'name' => $name, 'phone' => $phone, 'role' => $role]);
                AdminUserEvent::query()->create([
                    'user_id' => $users[$key]->id, 'actor_id' => null, 'actor_name' => 'Локальная тестовая команда',
                    'type' => 'local_fixture', 'changes' => ['fixture_key' => ['before' => null, 'after' => $owner]],
                    'created_at' => now(),
                ]);
            }
            // Provision only once: reruns preserve UI edits and do not erase support history.
            $learner = $users['own'];
            $marker = AdminUserEvent::query()->where('user_id', $learner->id)->where('type', 'local_fixture_data')->exists();
            if (! $marker) {
                $learner->forceFill(['manager_id' => $users['manager']->id])->save();
                foreach (['paid', 'pending', 'failed', 'refunded'] as $status) {
                    $paid = in_array($status, ['paid', 'refunded'], true);
                    $order = Order::query()->create([
                        'user_id' => $learner->id, 'currency' => 'RUB', 'amount_total' => 125000,
                        'amount_final' => 125000, 'amount_paid' => $paid ? 125000 : 0,
                        'amount_refunded' => $status === 'refunded' ? 125000 : 0, 'payment_status' => $status,
                        'access_status' => $status === 'paid' ? 'granted' : 'not_granted', 'paid_at' => $paid ? now() : null,
                    ]);
                    $item = OrderItem::query()->create([
                        'order_id' => $order->id, 'sellable_kind' => 'lesson', 'sellable_id' => $lessons[0]->id,
                        'purchased_entity_type' => 'lesson', 'purchased_entity_id' => $lessons[0]->id,
                        'display_title' => 'Тестовая покупка: '.$lessons[0]->title, 'quantity' => 1, 'currency' => 'RUB',
                        'unit_amount' => 125000, 'subtotal_amount' => 125000, 'final_amount' => 125000,
                        'snapshot_hash' => hash('sha256', "admin-users-v1:{$status}"),
                        'grant_snapshot' => [['kind' => 'lesson', 'id' => $lessons[0]->id]],
                        'access_status' => $status === 'paid' ? 'granted' : 'not_granted',
                    ]);
                    if ($status === 'paid') {
                        UserEntitlementGrant::query()->create([
                            'user_id' => $learner->id, 'atom_kind' => 'lesson', 'atom_id' => $lessons[0]->id,
                            'status' => 'active', 'source_type' => 'test_fixture', 'source_id' => 'admin_users_v1:paid',
                            'order_id' => $order->id, 'order_item_id' => $item->id,
                            'idempotency_key' => 'admin-users-v1-paid', 'granted_at' => now(),
                            'metadata' => ['fixture_owner' => 'admin_users_v1'],
                        ]);
                    }
                }
                foreach (['expired', 'revoked'] as $index => $status) {
                    UserEntitlementGrant::query()->create([
                        'user_id' => $learner->id, 'atom_kind' => 'lesson', 'atom_id' => $lessons[$index + 1]->id,
                        'status' => $status === 'expired' ? 'active' : 'revoked',
                        'source_type' => 'test_fixture', 'source_id' => "admin_users_v1:{$status}",
                        'idempotency_key' => "admin-users-v1-{$status}", 'granted_at' => now()->subDays(2),
                        'expires_at' => $status === 'expired' ? now()->subDay() : null,
                        'revoked_at' => $status === 'revoked' ? now()->subDay() : null,
                        'revoked_reason' => $status === 'revoked' ? 'Тестовая запись' : null,
                        'metadata' => ['fixture_owner' => 'admin_users_v1'],
                    ]);
                }
                AdminUserEvent::query()->create([
                    'user_id' => $learner->id, 'actor_id' => null, 'actor_name' => 'Локальная тестовая команда',
                    'type' => 'local_fixture_data', 'comment' => 'Добавлены синтетические заказы и доступы для проверки раздела.',
                    'created_at' => now(),
                ]);
            }

            return ['profiles' => count($users), 'all_lessons' => 0, 'partial_lessons' => 1, 'empty_lessons' => 0];
        }, 3);
    }

    /**
     * @return array<string, array{email:string,name:string,phone:string,role:string}>
     */
    private function profiles(): array
    {
        $profiles = config('local_development.dev_profiles.profiles');

        if (! is_array($profiles) || array_keys($profiles) !== ['all', 'partial', 'empty', 'reviewer']) {
            throw new LocalDevProfilesException('fixture_configuration_invalid');
        }

        foreach ($profiles as $profile) {
            if (
                ! is_array($profile)
                || ! is_string($profile['email'] ?? null)
                || ! is_string($profile['name'] ?? null)
                || ! is_string($profile['phone'] ?? null)
                || ! is_string($profile['role'] ?? null)
            ) {
                throw new LocalDevProfilesException('fixture_configuration_invalid');
            }
        }

        return $profiles;
    }

    /**
     * @param  array{email:string,name:string,phone:string,role:string}  $profile
     */
    private function upsertProfile(array $profile): User
    {
        $user = User::query()->firstOrNew(['email' => $profile['email']]);
        $user->forceFill([
            'name' => $profile['name'],
            'email' => $profile['email'],
            'phone' => $profile['phone'],
            'phone_verified_at' => '2026-01-01 00:00:00',
            'email_verified_at' => null,
            'password_state' => 'set',
            'last_auth_method' => 'email',
            'legacy_auto_verified' => true,
            'role' => $profile['role'],
            'manager_id' => null,
        ]);

        $existingPassword = (string) $user->getRawOriginal('password');
        if (
            $existingPassword === ''
            || ! Hash::check(self::PASSWORD, $existingPassword)
            || Hash::needsRehash($existingPassword)
        ) {
            $user->password = Hash::make(self::PASSWORD);
        }

        $user->save();

        return $user;
    }

    /**
     * @return list<int>
     */
    private function resolvePartialLessonIds(): array
    {
        $configuration = config('local_development.dev_profiles.partial_access');
        $fullCourses = is_array($configuration) ? ($configuration['full_courses'] ?? null) : null;
        $individualLessons = is_array($configuration) ? ($configuration['individual_lessons'] ?? null) : null;

        if (! is_array($fullCourses) || ! is_array($individualLessons)) {
            throw new LocalDevProfilesException('fixture_configuration_invalid');
        }

        $lessonIds = [];

        foreach ($fullCourses as $selector) {
            if (! is_array($selector)) {
                throw new LocalDevProfilesException('fixture_configuration_invalid');
            }

            $course = $this->resolveCourse($selector);
            $courseLessonIds = $course->lessons()
                ->active()
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (int|string $id): int => (int) $id)
                ->all();

            if ($courseLessonIds === []) {
                throw new LocalDevProfilesException('fixture_catalog_mismatch');
            }

            foreach ($courseLessonIds as $lessonId) {
                $lessonIds[$lessonId] = true;
            }
        }

        foreach ($individualLessons as $selector) {
            if (! is_array($selector) || ! is_string($selector['lesson'] ?? null)) {
                throw new LocalDevProfilesException('fixture_configuration_invalid');
            }

            $course = $this->resolveCourse($selector);
            $lesson = $course->lessons()
                ->active()
                ->where('slug', $selector['lesson'])
                ->first();

            if (! $lesson) {
                throw new LocalDevProfilesException('fixture_catalog_mismatch');
            }

            $lessonIds[(int) $lesson->id] = true;
        }

        if ($lessonIds === []) {
            throw new LocalDevProfilesException('fixture_catalog_mismatch');
        }

        $ids = array_keys($lessonIds);
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $selector
     */
    private function resolveCourse(array $selector): Course
    {
        $subjectSlug = $selector['subject'] ?? null;
        $courseSlug = $selector['course'] ?? null;

        if (! is_string($subjectSlug) || ! is_string($courseSlug)) {
            throw new LocalDevProfilesException('fixture_configuration_invalid');
        }

        $course = Course::query()
            ->active()
            ->where('slug', $courseSlug)
            ->whereHas('subject', fn (Builder $query): Builder => $query
                ->active()
                ->where('slug', $subjectSlug))
            ->first();

        if (! $course) {
            throw new LocalDevProfilesException('fixture_catalog_mismatch');
        }

        return $course;
    }

    /**
     * @param  list<int>  $lessonIds
     */
    private function reconcileGrants(User $user, array $lessonIds, string $profile): void
    {
        if ($profile === 'reviewer') {
            UserEntitlementGrant::query()
                ->where('user_id', $user->id)
                ->delete();

            return;
        }

        $managedGrants = UserEntitlementGrant::query()
            ->where('user_id', $user->id)
            ->where('source_type', UserEntitlementGrant::SOURCE_TEST_FIXTURE)
            ->where('metadata->fixture_owner', self::FIXTURE_OWNER);

        $managedGrants
            ->where(function (Builder $query) use ($lessonIds): void {
                $query->where('atom_kind', '!=', UserEntitlementGrant::ATOM_LESSON);

                if ($lessonIds === []) {
                    $query->orWhere('atom_kind', UserEntitlementGrant::ATOM_LESSON);
                } else {
                    $query->orWhereNotIn('atom_id', $lessonIds);
                }
            })
            ->delete();

        foreach ($lessonIds as $lessonId) {
            $grant = UserEntitlementGrant::query()->firstOrNew([
                'user_id' => $user->id,
                'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
                'atom_id' => $lessonId,
                'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
                'idempotency_key' => self::IDEMPOTENCY_KEY,
            ]);
            $grant->forceFill([
                'status' => UserEntitlementGrant::STATUS_ACTIVE,
                'source_id' => 'local-dev-profile:'.$profile,
                'order_id' => null,
                'order_item_id' => null,
                'granted_at' => $grant->granted_at ?? now(),
                'revoked_at' => null,
                'expires_at' => null,
                'revoked_reason' => null,
                'metadata' => [
                    'fixture_owner' => self::FIXTURE_OWNER,
                    'profile' => $profile,
                ],
            ]);
            $grant->save();
        }
    }

    /**
     * @return Builder<Lesson>
     */
    private function activeLearningLessonQuery(): Builder
    {
        return Lesson::query()
            ->active()
            ->whereHas('course', fn (Builder $course): Builder => $course
                ->active()
                ->whereHas('subject', fn (Builder $subject): Builder => $subject->active()));
    }
}
