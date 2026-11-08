<?php

namespace Tests\Feature;

use App\Models\AdminUserEvent;
use App\Models\CoinLedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AccountProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_only_own_name_and_audits_without_granting_a_bonus(): void
    {
        $user = User::factory()->create(['name' => 'Исходное имя']);
        $other = User::factory()->create();
        CoinLedgerEntry::query()->create([
            'user_id' => $user->id, 'event_type' => 'coins_accrual', 'units' => 100,
            'idempotency_key' => 'profile-test-existing-bonus',
        ]);
        $before = $user->refresh()->getRawOriginal();
        $otherBefore = $other->refresh()->getRawOriginal();
        $ledger = DB::table('coin_ledger_entries')->get()->toJson();
        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me/profile', ['name' => '  Иван Иванов  '])
            ->assertOk()->assertJsonPath('id', $user->id)->assertJsonPath('name', 'Иван Иванов')
            ->assertJsonPath('email', $user->email)->assertJsonPath('role', $user->role)
            ->assertJsonPath('phone_verified', true)->assertJsonPath('must_verify_email', false);
        $after = $user->fresh()->getRawOriginal();
        unset($before['name'], $before['updated_at'], $after['name'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertSame($otherBefore, $other->fresh()->getRawOriginal());
        $this->assertSame($ledger, DB::table('coin_ledger_entries')->get()->toJson());
        $this->assertDatabaseCount('user_profiles', 0);
        $event = AdminUserEvent::query()->sole();
        $this->assertSame($user->id, $event->actor_id);
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame('profile', $event->type);
        $this->assertSame('Исходное имя', $event->actor_name);
        $this->assertSame(['name' => ['before' => 'Исходное имя', 'after' => 'Иван Иванов']], $event->changes);
        $user->refresh();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('name', 'Иван Иванов');
        $this->patchJson('/api/v1/me/profile', ['name' => 'Иван Иванов'])->assertOk();
        $this->assertDatabaseCount('admin_user_events', 1);
        $this->assertSame($ledger, DB::table('coin_ledger_entries')->get()->toJson());
    }

    #[DataProvider('invalidNames')]
    public function test_rejects_invalid_names_without_mutation(mixed $name): void
    {
        $user = User::factory()->create();
        $before = $user->refresh()->getRawOriginal();
        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me/profile', ['name' => $name])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('admin_user_events', 0);
    }

    public static function invalidNames(): array
    {
        return [[''], ['   '], [null], [42], [['name']], [str_repeat('я', 256)]];
    }

    public function test_rejects_extra_fields_and_missing_name(): void
    {
        $user = User::factory()->create();
        $before = $user->refresh()->getRawOriginal();
        $this->actingAs($user, 'sanctum');
        foreach (['id', 'user_id', 'email', 'phone', 'role', 'phone_verified_at', 'email_verified_at', 'balance'] as $field) {
            $this->patchJson('/api/v1/me/profile', ['name' => 'Новое имя', $field => 'injected'])
                ->assertUnprocessable()->assertJsonValidationErrors('request');
        }
        $this->patchJson('/api/v1/me/profile', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertSame($before, $user->fresh()->getRawOriginal());
        $this->assertDatabaseCount('admin_user_events', 0);
    }

    public function test_guest_cannot_change_a_profile(): void
    {
        $this->patchJson('/api/v1/me/profile', ['name' => 'Новое имя'])->assertUnauthorized();
        $this->assertDatabaseCount('admin_user_events', 0);
    }

    public function test_accepts_the_255_character_boundary(): void
    {
        $user = User::factory()->create();
        $name = str_repeat('я', 255);
        $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me/profile', ['name' => $name])
            ->assertOk()->assertJsonPath('name', $name);
        $this->assertSame($name, $user->fresh()->name);
    }

    public function test_impersonation_cannot_change_either_identity(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $subject = User::factory()->create();
        $this->actingAs($actor, 'web')->withHeaders([
            'Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/admin/users',
            'X-CSRF-TOKEN' => csrf_token(), 'X-XSRF-TOKEN' => csrf_token(),
        ])->postJson("/api/v1/admin/users/{$subject->id}/impersonation", ['return_path' => '/admin/users'])
            ->assertCreated();
        $this->patchJson('/api/v1/me/profile', ['name' => 'Нельзя сохранить'])
            ->assertForbidden()->assertJsonPath('error.code', 'IMPERSONATION_READ_ONLY');
        $this->assertSame($actor->name, $actor->fresh()->name);
        $this->assertSame($subject->name, $subject->fresh()->name);
        $this->assertDatabaseCount('admin_user_events', 0);
    }

    public function test_audit_failure_rolls_back_the_name(): void
    {
        $user = User::factory()->create();
        $eventName = 'eloquent.creating: '.AdminUserEvent::class;
        Event::listen($eventName, static fn () => throw new RuntimeException('Audit unavailable'));
        try {
            $this->actingAs($user, 'sanctum')->patchJson('/api/v1/me/profile', ['name' => 'Не сохранено'])
                ->assertStatus(500);
            $this->assertSame($user->name, $user->fresh()->name);
            $this->assertDatabaseCount('admin_user_events', 0);
        } finally {
            Event::forget($eventName);
        }
    }
}
