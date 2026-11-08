<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminFoundationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/me')->assertUnauthorized();
    }

    public function test_admin_me_rejects_non_staff_user(): void
    {
        foreach (['user_no_purchase', 'user_with_purchase'] as $role) {
            Sanctum::actingAs(User::factory()->create([
                'role' => $role,
            ]));

            $this->getJson('/api/v1/admin/me')->assertForbidden();
        }
    }

    public function test_admin_me_returns_staff_identity_and_permissions(): void
    {
        $user = User::factory()->create([
            'name' => 'Admin User',
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'email' => 'admin@example.com',
            'phone' => '+79990000000',
            'role' => 'admin_full',
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/admin/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('name', 'Admin User')
            ->assertJsonPath('first_name', 'Иван')
            ->assertJsonPath('last_name', 'Петров')
            ->assertJsonPath('email', 'admin@example.com')
            ->assertJsonPath('phone', '+79990000000')
            ->assertJsonPath('role', 'admin_full');

        $this->assertContains('admin.access', $response->json('permissions'));
        $this->assertContains('admin.self.view', $response->json('permissions'));
        $this->assertContains('admin.self.update', $response->json('permissions'));
        $this->assertContains('video-watermarks.read', $response->json('permissions'));
        $this->assertSame(
            ['id', 'name', 'first_name', 'last_name', 'email', 'phone', 'role', 'permissions'],
            array_keys($response->json())
        );
    }

    public function test_admin_me_allows_all_staff_roles(): void
    {
        foreach (['admin_full', 'admin_limited', 'manager', 'teacher'] as $role) {
            Sanctum::actingAs(User::factory()->create([
                'role' => $role,
            ]));

            $this->getJson('/api/v1/admin/me')
                ->assertOk()
                ->assertJsonPath('role', $role);
        }
    }

    public function test_staff_can_update_only_their_own_display_name(): void
    {
        foreach (['admin_full', 'admin_limited', 'manager', 'teacher'] as $role) {
            $user = User::factory()->create([
                'name' => 'Old Name',
                'email' => "{$role}@example.com",
                'role' => $role,
            ]);
            Sanctum::actingAs($user);

            $this->patchJson('/api/v1/admin/me', ['name' => '  New Name  '])
                ->assertOk()
                ->assertJsonPath('name', 'New Name')
                ->assertJsonPath('email', "{$role}@example.com")
                ->assertJsonPath('role', $role);

            $this->assertDatabaseHas('users', [
                'id' => $user->id,
                'name' => 'New Name',
                'email' => "{$role}@example.com",
                'role' => $role,
            ]);
        }
    }

    public function test_admin_profile_update_validates_name_and_rejects_other_fields(): void
    {
        $user = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'role' => 'admin_full',
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/admin/me', ['name' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->patchJson('/api/v1/admin/me', [
            'name' => 'Changed Name',
            'email' => 'attacker@example.com',
            'role' => 'user_no_purchase',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request');

        $user->refresh();
        $this->assertSame('Admin User', $user->name);
        $this->assertSame('admin@example.com', $user->email);
        $this->assertSame('admin_full', $user->role);
    }

    public function test_non_staff_user_cannot_update_admin_profile(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'user_with_purchase',
        ]));

        $this->patchJson('/api/v1/admin/me', ['name' => 'Changed Name'])
            ->assertForbidden();
    }
}
