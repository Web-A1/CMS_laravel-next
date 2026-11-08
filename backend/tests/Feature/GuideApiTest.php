<?php

namespace Tests\Feature;

use App\Models\Guide;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuideApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_library_requires_authentication_and_returns_only_current_owned_active_guides(): void
    {
        $this->getJson('/api/v1/learning/guides')->assertUnauthorized();
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        Sanctum::actingAs($user);
        $this->getJson('/api/v1/learning/guides')->assertExactJson(['data' => []]);
        $owned = Guide::factory()->create(['is_active' => true]);
        $inactive = Guide::factory()->create();
        Guide::factory()->create(['is_active' => true]);
        foreach ([$owned, $inactive] as $guide) {
            UserEntitlementGrant::query()->create(['user_id' => $user->id, 'atom_kind' => 'guide', 'atom_id' => $guide->id, 'status' => 'active', 'source_type' => 'test_fixture', 'source_id' => 'guide-'.$guide->id, 'idempotency_key' => 'guide-'.$guide->id, 'granted_at' => now()]);
        }
        $this->getJson('/api/v1/learning/guides')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson(['data' => [$owned->only(['id', 'slug', 'title'])]]);
        UserEntitlementGrant::query()->update(['status' => 'revoked']);
        $this->getJson('/api/v1/learning/guides')->assertExactJson(['data' => []]);
    }

    public function test_manifest_denies_unowned_and_inactive_guides_without_source_data(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $guide = Guide::factory()->create(['is_active' => true]);
        Sanctum::actingAs($user);
        $url = '/api/v1/learning/guides/'.$guide->slug.'/file-materials';
        $this->getJson($url)->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $guide->update(['is_active' => false]);
        $this->getJson($url)->assertNotFound();
        $this->getJson('/api/v1/learning/guides/missing/file-materials')->assertNotFound()->assertHeader('Cache-Control', 'no-store, private');
    }
}
