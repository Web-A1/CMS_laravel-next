<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VideoWatermarkSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VideoWatermarkAdminLookupApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_full_can_lookup_watermark_codes_and_lookup_is_audited(): void
    {
        $student = User::factory()->create([
            'name' => 'Student',
            'email' => 'student@example.test',
            'phone' => '+79990000000',
        ]);
        $admin = User::factory()->create(['role' => 'admin_full']);
        $limited = User::factory()->create(['role' => 'admin_limited']);
        $session = $this->createSession($student);

        Sanctum::actingAs($limited);
        $this->postJson('/api/v1/admin/video-watermarks/lookup', ['code' => 'CMS 7K4M 92QF'])
            ->assertForbidden();
        $this->assertDatabaseCount('video_watermark_lookup_audit_events', 0);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/v1/admin/video-watermarks/lookup', ['code' => 'CMS 7K4M 92QF'])
            ->assertOk()
            ->assertJsonPath('data.code', 'CMS-7K4M-92QF')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.user.id', $student->id)
            ->assertJsonPath('data.user.displayName', 'Student')
            ->assertJsonPath('data.user.adminHref', "/admin/users/{$student->id}")
            ->assertJsonMissingPath('data.user.email')
            ->assertJsonMissingPath('data.user.phone')
            ->assertJsonMissingPath('data.authSessionHash')
            ->assertJsonMissingPath('data.ipAddress')
            ->assertJsonMissingPath('data.userAgent')
            ->assertJsonMissingPath('data.device');
        $this->assertSame(
            ['code', 'status', 'startedAt', 'lastUsedAt', 'endedAt', 'user'],
            array_keys($response->json('data')),
        );
        $this->assertSame(['id', 'displayName', 'adminHref'], array_keys($response->json('data.user')));

        $this->assertDatabaseHas('video_watermark_lookup_audit_events', [
            'actor_user_id' => $admin->id,
            'watermark_session_id' => $session->id,
            'normalized_code' => 'CMS7K4M92QF',
            'result' => 'found',
        ]);
    }

    public function test_admin_full_receives_a_neutral_response_and_audit_for_not_found_or_malformed_code(): void
    {
        $admin = User::factory()->create(['role' => 'admin_full']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/video-watermarks/lookup', ['code' => 'CMS 7K4M 92QF'])
            ->assertOk()
            ->assertExactJson(['data' => null]);
        $this->assertDatabaseHas('video_watermark_lookup_audit_events', [
            'actor_user_id' => $admin->id,
            'normalized_code' => 'CMS7K4M92QF',
            'result' => 'not_found',
        ]);

        $this->postJson('/api/v1/admin/video-watermarks/lookup', ['code' => 'not a watermark code'])
            ->assertOk()
            ->assertExactJson(['data' => null]);
        $this->assertDatabaseHas('video_watermark_lookup_audit_events', [
            'actor_user_id' => $admin->id,
            'normalized_code' => null,
            'result' => 'not_found',
        ]);
    }

    public function test_lookup_validates_code_before_audit(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));

        $this->postJson('/api/v1/admin/video-watermarks/lookup', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/admin/video-watermarks/lookup', ['code' => str_repeat('A', 65)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/admin/video-watermarks/lookup', ['code' => ['CMS-7K4M-92QF']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
        $this->assertDatabaseCount('video_watermark_lookup_audit_events', 0);
    }

    public function test_all_other_rbac_profiles_are_forbidden_before_lookup_or_audit(): void
    {
        foreach (['admin_limited', 'manager', 'teacher', 'user_with_purchase', 'user_no_purchase'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));

            $this->postJson('/api/v1/admin/video-watermarks/lookup', ['code' => 'CMS 7K4M 92QF'])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('video_watermark_lookup_audit_events', 0);
    }

    private function createSession(User $user): VideoWatermarkSession
    {
        return VideoWatermarkSession::query()->create([
            'id' => (string) Str::uuid(),
            'code' => 'CMS-7K4M-92QF',
            'auth_session_hash' => hash('sha256', 'session-one'),
            'user_id' => $user->id,
            'started_at' => now()->subMinutes(10),
            'last_used_at' => now(),
        ]);
    }
}
