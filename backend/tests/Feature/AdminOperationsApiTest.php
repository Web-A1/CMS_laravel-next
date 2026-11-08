<?php

namespace Tests\Feature;

use App\Models\AdminOperationEvent;
use App\Models\AdminShortLink;
use App\Models\AdminTask;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\AdminOperationsService;
use App\Services\Bonuses\PromoReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class AdminOperationsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_require_full_admin_and_validate_internal_fields(): void
    {
        $this->getJson('/api/v1/admin/tasks')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin_limited']));
        $this->getJson('/api/v1/admin/tasks')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        $this->postJson('/api/v1/admin/tasks', [
            'title' => 'Новая задача',
            'created_by' => 999,
        ])->assertUnprocessable();
    }

    public function test_short_link_rejects_external_and_parent_targets(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));

        $this->postJson('/api/v1/admin/short-links', [
            'slug' => 'campaign',
            'target_path' => 'https://example.test',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/admin/short-links', [
            'slug' => '../private',
            'target_path' => '/admin',
        ])->assertUnprocessable();

        foreach (['/logout', '/auth/logout', '/api/v1/orders', '/admin/users', '//example.test/path', '/courses\\private'] as $index => $target) {
            $this->withHeader('Idempotency-Key', 'short-link-blocked-'.$index)->postJson('/api/v1/admin/short-links', [
                'slug' => 'blocked-'.$index,
                'target_path' => $target,
            ])->assertUnprocessable()->assertJsonValidationErrors('target_path');
        }

        $this->withHeader('Idempotency-Key', 'short-link-create-1')->postJson('/api/v1/admin/short-links', [
            'slug' => 'campaign',
            'target_path' => '/courses/anatomy',
        ])->assertCreated()->assertJsonPath('data.created_by', auth()->id());
    }

    public function test_promo_crud_uses_domain_discount_kinds_and_audits_idempotently(): void
    {
        $admin = User::factory()->create(['role' => 'admin_full']);
        Sanctum::actingAs($admin);
        $payload = [
            'code' => 'SPRING-ADMIN', 'status' => 'active', 'discount_kind' => 'percent_bps', 'discount_bps' => 1500,
            'expires_at' => now()->addDay()->toISOString(), 'activation_limit' => 10, 'per_user_limit' => 1,
        ];

        $this->withHeader('Idempotency-Key', 'promo-create-1')->postJson('/api/v1/admin/promos', $payload)
            ->assertCreated()->assertJsonPath('data.code', 'SPRING-ADMIN');
        $promo = PromoCode::query()->firstOrFail();
        $this->withHeader('Idempotency-Key', 'promo-update-1')->patchJson('/api/v1/admin/promos/'.$promo->id, ['status' => 'revoked'])
            ->assertOk()->assertJsonPath('data.status', 'revoked')
            ->assertJsonMissingPath('data.code_hash')
            ->assertJsonMissingPath('data.bound_email_hash');
        $this->withHeader('Idempotency-Key', 'promo-update-1')->patchJson('/api/v1/admin/promos/'.$promo->id, ['status' => 'active'])->assertConflict();
        $this->assertSame(PromoReservationService::codeHash('SPRING-ADMIN'), $promo->fresh()->code_hash);
        $this->assertDatabaseCount('admin_operation_events', 2);
    }

    public function test_operation_audit_does_not_store_support_message_bodies(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        $this->withHeader('Idempotency-Key', 'ticket-create-1')->postJson('/api/v1/admin/support-tickets', ['subject' => 'Вопрос', 'description' => 'Секретный текст обращения'])->assertCreated();
        $event = AdminOperationEvent::query()->latest('id')->firstOrFail();
        $this->assertArrayNotHasKey('description', $event->payload);
        $this->assertArrayNotHasKey('subject', $event->payload);
    }

    public function test_tasks_and_links_api_updates_remain_compatible_without_snapshot_payload(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        $this->withHeader('Idempotency-Key', 'task-compatible-create')->postJson('/api/v1/admin/tasks', ['title' => 'Original'])->assertCreated();
        $task = AdminTask::sole();
        $this->withHeader('Idempotency-Key', 'task-compatible-update')->patchJson('/api/v1/admin/tasks/'.$task->id, ['title' => 'Changed'])->assertOk();
        $this->assertSame('Changed', $task->fresh()->title);
        $this->withHeader('Idempotency-Key', 'link-compatible-create')->postJson('/api/v1/admin/short-links', ['slug' => 'compat-link', 'target_path' => '/courses'])->assertCreated();
        $link = AdminShortLink::sole();
        $this->withHeader('Idempotency-Key', 'link-compatible-update')->patchJson('/api/v1/admin/short-links/'.$link->id, ['slug' => 'compat-link', 'target_path' => '/faq'])->assertOk();
        $this->assertSame('/faq', $link->fresh()->target_path);
        $this->assertDatabaseCount('admin_operation_events', 4);
    }

    public function test_manual_snapshot_cas_precedes_no_op_and_audit_omits_draft_text(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $service = app(AdminOperationsService::class);
        foreach ([['tasks', ['title' => 'Original', 'description' => 'Private'], ['title' => 'Changed', 'description' => 'Private']], ['links', ['slug' => 'cas-link', 'target_path' => '/courses'], ['slug' => 'cas-link', 'target_path' => '/faq']]] as [$method, $original, $changed]) {
            $model = $service->$method($actor, 'create', $original, key: $method.'-cas-create')['data'];
            $snapshot = $model->only(array_keys($original));
            $service->$method($actor, 'update', $changed, $model->id, $method.'-cas-update', expectedSnapshot: $snapshot);
            try {
                // Even the now-current value must not silently ACK an outdated editor.
                $service->$method($actor, 'update', $changed, $model->id, $method.'-cas-stale', expectedSnapshot: $snapshot);
                $this->fail('Stale snapshot must conflict before no-op.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
                $this->assertSame($changed, $model->fresh()->only(array_keys($changed)));
            }
            try {
                $service->$method($actor, 'update', $changed, $model->id, $method.'-cas-update', expectedSnapshot: $changed);
                $this->fail('Duplicate key must keep its conflict contract.');
            } catch (HttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
        }
        $this->assertDatabaseCount('admin_operation_events', 4);
        $this->assertStringNotContainsString('Private', AdminOperationEvent::all()->toJson());
    }

    public function test_manual_update_no_op_keeps_timestamps_and_audit_after_normalization(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $service = app(AdminOperationsService::class);
        foreach ([['tasks', ['title' => 'Original', 'description' => null]], ['links', ['slug' => 'noop-link', 'target_path' => '/courses']]] as [$method, $input]) {
            $model = $service->$method($actor, 'create', $input, key: $method.'-noop-create')['data'];
            $before = $model->fresh()->getAttributes();
            $snapshot = $model->only(array_keys($input));
            $this->travel(10)->seconds();
            $payload = $method === 'tasks' ? [...$input, 'description' => ''] : $input;
            $service->$method($actor, 'update', $payload, $model->id, $method.'-noop-update', expectedSnapshot: array_reverse($snapshot, true));
            $this->assertSame($before, $model->fresh()->getAttributes());
        }
        $this->assertDatabaseCount('admin_operation_events', 2);
    }
}
