<?php

namespace Tests\Feature;

use App\Contracts\ProtectedFiles\TemporaryDownloadUrlIssuer;
use App\Jobs\GenerateProtectedFileDeliveryJob;
use App\Models\Guide;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialFile;
use App\Models\ProtectedFileDelivery;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuideFileDeliveryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Guide $guide;

    private LessonMaterial $material;

    private LessonMaterialFile $source;

    private UserEntitlementGrant $grant;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('protected_files.output_disk', 'local');
        Queue::fake();
        $this->user = User::factory()->create(['role' => 'user_no_purchase']);
        $this->guide = Guide::factory()->create(['is_active' => true]);
        $this->material = $this->guide->materials()->create(['kind' => 'pdf', 'title' => 'Guide PDF', 'is_published' => true]);
        $this->source = LessonMaterialFile::query()->create(['lesson_material_id' => $this->material->id, 'source_object_key' => 'protected-sources/guide/file.pdf', 'source_sha256' => str_repeat('a', 64), 'source_size_bytes' => 1024, 'source_mime_type' => 'application/pdf', 'download_filename' => 'guide.pdf']);
        $this->grant = UserEntitlementGrant::query()->create(['user_id' => $this->user->id, 'atom_kind' => 'guide', 'atom_id' => $this->guide->id, 'status' => 'active', 'source_type' => 'test_fixture', 'source_id' => 'guide-owner', 'idempotency_key' => 'guide-owner', 'granted_at' => now()]);
        $this->url = '/api/v1/learning/guides/'.$this->guide->slug.'/file-deliveries';
        Sanctum::actingAs($this->user);
    }

    public function test_owner_requests_and_prewarms_same_private_delivery_with_no_source_leak(): void
    {
        $this->getJson(str_replace('file-deliveries', 'file-materials', $this->url))->assertExactJson(['data' => ['files' => [['materialId' => $this->material->id, 'title' => 'Guide PDF']]]]);
        $first = $this->postJson($this->url, ['materialId' => $this->material->id])->assertAccepted()->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($this->url, ['materialId' => $this->material->id])->assertExactJson($first->json());
        $this->postJson($this->url.'/prewarm', [])->assertAccepted()->assertJsonPath('data.deliveries.0.deliveryId', $first->json('data.deliveryId'));
        $this->getJson($this->url.'/'.$first->json('data.deliveryId'))->assertOk()->assertExactJson($first->json());
        $this->assertSame(['deliveryId', 'status', 'filename', 'canRequestAccess'], array_keys($first->json('data')));
        foreach (['protected-sources', str_repeat('a', 64), 'COPY-', $this->user->email] as $secret) {
            $this->assertStringNotContainsString($secret, $first->getContent());
        }
        Queue::assertPushed(GenerateProtectedFileDeliveryJob::class, 1);
        $this->assertDatabaseCount('protected_file_deliveries', 1);
    }

    public function test_ready_owner_requires_ack_and_receives_short_lived_personal_url(): void
    {
        $delivery = $this->ready();
        $url = $this->url.'/'.$delivery->public_id.'/access-grants';
        $this->postJson($url, [])->assertUnprocessable();
        $this->postJson($url, ['confidentialityAcknowledged' => false])->assertUnprocessable();
        $this->mock(TemporaryDownloadUrlIssuer::class, function ($mock): void {
            $mock->shouldReceive('issue')->once()->withArgs(fn ($disk, $key, $expiresAt, $filename) => $disk === 'local' && $key === 'protected-copies/ready.pdf' && $expiresAt->diffInSeconds(now(), true) <= 120 && $filename === 'guide.pdf')->andReturn('https://copies.example/personal.pdf');
        });
        $this->postJson($url, ['confidentialityAcknowledged' => true])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('data.downloadUrl', 'https://copies.example/personal.pdf');
    }

    public function test_wrong_owner_material_delivery_and_numeric_lesson_collision_are_denied(): void
    {
        $delivery = $this->ready();
        $other = Guide::factory()->create(['is_active' => true]);
        $material = $other->materials()->create(['kind' => 'pdf', 'title' => 'Other', 'is_published' => true]);
        $this->postJson($this->url, ['materialId' => $material->id])->assertNotFound();
        $this->material->update(['guide_id' => $other->id]);
        $this->getJson($this->url.'/'.$delivery->public_id)->assertNotFound();
        $this->postJson($this->url.'/'.$delivery->public_id.'/access-grants', ['confidentialityAcknowledged' => true])->assertNotFound();
        $this->material->update(['guide_id' => $this->guide->id]);
        $subject = Subject::query()->create(['slug' => 'subject', 'title' => 'Subject']);
        $course = $subject->courses()->create(['slug' => 'course', 'title' => 'Course', 'price_cents' => 100]);
        $lesson = $course->lessons()->create(['slug' => 'lesson', 'title' => 'Lesson']);
        $lessonMaterial = $lesson->materials()->create(['kind' => 'pdf', 'title' => 'Lesson', 'is_published' => true]);
        $this->postJson($this->url, ['materialId' => $lessonMaterial->id])->assertNotFound();
        $delivery->update(['user_id' => User::factory()->create()->id]);
        $this->getJson($this->url.'/'.$delivery->public_id)->assertNotFound();
        $this->postJson($this->url.'/'.$delivery->public_id.'/access-grants', ['confidentialityAcknowledged' => true])->assertNotFound();
        $delivery->update(['user_id' => $this->user->id]);
        $this->grant->update(['atom_kind' => 'lesson']);
        $this->postJson($this->url, ['materialId' => $this->material->id])->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_revoked_and_expired_entitlements_deny_cached_ready_copy(): void
    {
        $delivery = $this->ready();
        $this->grant->update(['status' => 'revoked']);
        $this->getJson($this->url.'/'.$delivery->public_id)->assertForbidden();
        $this->postJson($this->url.'/'.$delivery->public_id.'/access-grants', ['confidentialityAcknowledged' => true])->assertForbidden();
        $this->grant->update(['status' => 'active', 'expires_at' => now()->subSecond()]);
        $this->postJson($this->url, ['materialId' => $this->material->id])->assertForbidden();
    }

    public function test_unpublished_inactive_replaced_and_not_ready_copies_fail_closed(): void
    {
        $delivery = $this->ready();
        $status = $this->url.'/'.$delivery->public_id;
        $this->material->update(['is_published' => false]);
        $this->getJson($status)->assertNotFound();
        $this->material->update(['is_published' => true]);
        $this->guide->update(['is_active' => false]);
        $this->getJson($status)->assertNotFound();
        $this->guide->update(['is_active' => true]);
        $this->source->update(['source_sha256' => str_repeat('c', 64)]);
        $this->getJson($status)->assertServiceUnavailable();
        $this->postJson($status.'/access-grants', ['confidentialityAcknowledged' => true])->assertServiceUnavailable();
        $this->source->update(['source_sha256' => str_repeat('a', 64)]);
        $delivery->update(['status' => 'pending', 'ready_at' => null]);
        $this->postJson($status.'/access-grants', ['confidentialityAcknowledged' => true])->assertConflict();
    }

    public function test_invalid_payloads_and_unknown_browser_are_denied_before_generation(): void
    {
        $this->postJson($this->url, ['materialId' => (string) $this->material->id])->assertUnprocessable();
        $this->postJson($this->url, ['materialId' => $this->material->id, 'extra' => true])->assertUnprocessable();
        $this->postJson($this->url.'/prewarm', ['extra' => true])->assertUnprocessable();
        config(['trusted_browser.mode' => 'enforce', 'sanctum.stateful' => ['localhost']]);
        $delivery = $this->ready();
        $this->withHeader('Origin', 'http://localhost')->getJson($this->url.'/'.$delivery->public_id)->assertForbidden()->assertJsonPath('error.code', 'TRUSTED_BROWSER_REQUIRED');
        $this->postJson($this->url.'/'.$delivery->public_id.'/access-grants', ['confidentialityAcknowledged' => true])->assertForbidden()->assertJsonPath('error.code', 'TRUSTED_BROWSER_REQUIRED');
        $this->withHeader('Origin', 'http://localhost')->postJson($this->url, ['materialId' => $this->material->id])->assertForbidden()->assertJsonPath('error.code', 'TRUSTED_BROWSER_REQUIRED');
        Queue::assertNothingPushed();
    }

    private function ready(): ProtectedFileDelivery
    {
        return ProtectedFileDelivery::query()->create(['public_id' => (string) str()->uuid(), 'user_id' => $this->user->id, 'lesson_material_file_id' => $this->source->id, 'source_sha256' => $this->source->source_sha256, 'copy_code' => 'COPY-'.strtoupper(str()->random(16)), 'status' => 'ready', 'output_object_key' => 'protected-copies/ready.pdf', 'output_sha256' => str_repeat('b', 64), 'output_size_bytes' => 2048, 'ready_at' => now()]);
    }

    public function test_impersonation_preserves_existing_readonly_post_guard_and_disables_media_reads(): void
    {
        $delivery = $this->ready();
        Auth::forgetGuards();
        $actor = User::factory()->create(['role' => 'admin_full']);
        $this->actingAs($actor, 'web')->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/admin/users']);
        $this->postJson('/api/v1/admin/users/'.$this->user->id.'/impersonation', ['return_path' => '/admin/users'])->assertCreated();
        $this->getJson(str_replace('file-deliveries', 'file-materials', $this->url))->assertForbidden()->assertJsonPath('error.code', 'IMPERSONATION_MEDIA_DISABLED');
        $this->getJson($this->url.'/'.$delivery->public_id)->assertForbidden()->assertJsonPath('error.code', 'IMPERSONATION_MEDIA_DISABLED');
        $this->postJson($this->url, ['materialId' => $this->material->id])->assertForbidden()->assertJsonPath('error.code', 'IMPERSONATION_READ_ONLY');
        $this->postJson($this->url.'/'.$delivery->public_id.'/access-grants', ['confidentialityAcknowledged' => true])->assertForbidden()->assertJsonPath('error.code', 'IMPERSONATION_READ_ONLY');
        Queue::assertNothingPushed();
    }
}
