<?php

namespace Tests\Feature;

use App\Contracts\ProtectedFiles\TemporaryDownloadUrlIssuer;
use App\Jobs\GenerateProtectedFileDeliveryJob;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialFile;
use App\Models\ProtectedFileDelivery;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class ProtectedFileDeliveryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('protected_files.output_disk', 'local');
    }

    public function test_entitled_user_requests_one_idempotent_private_delivery_without_storage_locator(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $source = LessonMaterialFile::query()->create([
            'lesson_material_id' => $material->id,
            'source_object_key' => 'protected-sources/lesson-1/notes.pdf',
            'source_sha256' => str_repeat('a', 64),
            'source_size_bytes' => 1024,
            'source_mime_type' => 'application/pdf',
            'download_filename' => 'notes.pdf',
        ]);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $url = $this->deliveriesUrl($subject, $course, $lesson);
        $first = $this->postJson($url, ['materialId' => $material->id])
            ->assertAccepted()
            ->assertHeader('Cache-Control', 'no-store, private');
        $second = $this->postJson($url, ['materialId' => $material->id])
            ->assertAccepted();

        $this->assertSame($first->json(), $second->json());
        $this->assertSame(
            ['deliveryId', 'status', 'filename', 'canRequestAccess'],
            array_keys($first->json('data')),
        );
        $this->assertSame('pending', $first->json('data.status'));
        $this->assertSame('notes.pdf', $first->json('data.filename'));
        $this->assertFalse($first->json('data.canRequestAccess'));
        $this->assertDatabaseCount('protected_file_deliveries', 1);

        $delivery = ProtectedFileDelivery::query()->sole();
        $this->assertSame($source->id, $delivery->lesson_material_file_id);
        $this->assertSame($user->id, $delivery->user_id);
        $this->assertSame(str_repeat('a', 64), $delivery->source_sha256);
        $this->assertMatchesRegularExpression('/\ACOPY-[A-Z0-9]{16}\z/', $delivery->copy_code);

        $payload = $first->getContent() ?: '';
        foreach (['protected-sources', 'sourceSha256', 'sourceObjectKey', 'copyCode', $user->email] as $forbidden) {
            $this->assertStringNotContainsString((string) $forbidden, $payload);
        }

        Queue::assertPushed(GenerateProtectedFileDeliveryJob::class, 1);
    }

    public function test_free_preview_user_requests_private_delivery_without_grant(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $lesson->forceFill(['is_free_preview' => true])->save();
        $material = $this->createPdfMaterial($lesson);
        $this->createSource($material);
        Sanctum::actingAs($user);

        $this->postJson($this->deliveriesUrl($subject, $course, $lesson), [
            'materialId' => $material->id,
        ])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.filename', 'notes.pdf');

        $this->assertDatabaseCount('protected_file_deliveries', 1);
        Queue::assertPushed(GenerateProtectedFileDeliveryJob::class, 1);
    }

    public function test_entitled_user_prewarms_all_valid_lesson_pdf_deliveries_without_temporary_urls(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $firstMaterial = $this->createPdfMaterial($lesson, true, 'Первый PDF');
        $firstSource = $this->createSource($firstMaterial);
        $secondMaterial = $this->createPdfMaterial($lesson, true, 'Второй PDF');
        $secondSource = $this->createSource($secondMaterial);
        $readyDelivery = $this->createDelivery($user, $secondSource, ProtectedFileDelivery::STATUS_READY);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->postJson($this->prewarmUrl($subject, $course, $lesson), [])
            ->assertAccepted()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(2, 'data.deliveries')
            ->assertJsonPath('data.deliveries.0.materialId', $firstMaterial->id)
            ->assertJsonPath('data.deliveries.0.status', ProtectedFileDelivery::STATUS_PENDING)
            ->assertJsonPath('data.deliveries.0.filename', 'notes.pdf')
            ->assertJsonPath('data.deliveries.0.canRequestAccess', false)
            ->assertJsonPath('data.deliveries.1.materialId', $secondMaterial->id)
            ->assertJsonPath('data.deliveries.1.deliveryId', $readyDelivery->public_id)
            ->assertJsonPath('data.deliveries.1.status', ProtectedFileDelivery::STATUS_READY)
            ->assertJsonPath('data.deliveries.1.canRequestAccess', true);

        $payload = $response->getContent() ?: '';
        foreach (['downloadUrl', 'expiresAt', 'protected-sources', 'sourceSha256', 'sourceObjectKey', 'copyCode', $user->email] as $forbidden) {
            $this->assertStringNotContainsString((string) $forbidden, $payload);
        }

        $this->assertDatabaseCount('protected_file_deliveries', 2);
        $this->assertSame($firstSource->id, ProtectedFileDelivery::query()
            ->where('user_id', $user->id)
            ->where('lesson_material_file_id', $firstSource->id)
            ->value('lesson_material_file_id'));
        Queue::assertPushed(GenerateProtectedFileDeliveryJob::class, 1);
    }

    public function test_prewarm_reuses_ready_deliveries_without_queue_dispatch(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $source = $this->createSource($material);
        $delivery = $this->createDelivery($user, $source, ProtectedFileDelivery::STATUS_READY);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $this->postJson($this->prewarmUrl($subject, $course, $lesson), [])
            ->assertOk()
            ->assertJsonPath('data.deliveries.0.materialId', $material->id)
            ->assertJsonPath('data.deliveries.0.deliveryId', $delivery->public_id)
            ->assertJsonPath('data.deliveries.0.status', ProtectedFileDelivery::STATUS_READY)
            ->assertJsonPath('data.deliveries.0.canRequestAccess', true);

        $this->assertDatabaseCount('protected_file_deliveries', 1);
        Queue::assertNothingPushed();
    }

    public function test_prewarm_denies_access_before_material_source_or_delivery_queries(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $this->createSource($material);
        Sanctum::actingAs($user);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->postJson($this->prewarmUrl($subject, $course, $lesson), [])
            ->assertForbidden()
            ->assertExactJson($this->accessDeniedPayload());

        $this->assertFalse(collect($queries)->contains(
            fn (string $query): bool => str_contains($query, 'lesson_materials')
                || str_contains($query, 'lesson_material_files')
                || str_contains($query, 'protected_file_deliveries'),
        ));
    }

    public function test_unauthenticated_user_cannot_request_check_or_access_delivery(): void
    {
        [$subject, $course, $lesson] = $this->createLesson();
        $base = $this->deliveriesUrl($subject, $course, $lesson);

        $this->postJson($base, ['materialId' => 1])->assertUnauthorized();
        $this->postJson("{$base}/prewarm", [])->assertUnauthorized();
        $this->getJson("{$base}/not-a-delivery")->assertUnauthorized();
        $this->postJson("{$base}/not-a-delivery/access-grants", [
            'confidentialityAcknowledged' => true,
        ])->assertUnauthorized();
    }

    public function test_entitlement_is_denied_before_material_source_or_delivery_queries(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $this->createSource($material);
        Sanctum::actingAs($user);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->postJson($this->deliveriesUrl($subject, $course, $lesson), [
            'materialId' => $material->id,
        ])->assertForbidden()->assertExactJson($this->accessDeniedPayload());

        $this->assertFalse(collect($queries)->contains(
            fn (string $query): bool => str_contains($query, 'lesson_materials')
                || str_contains($query, 'lesson_material_files')
                || str_contains($query, 'protected_file_deliveries'),
        ));
    }

    public function test_entitled_user_without_verified_phone_can_request_delivery(): void
    {
        Queue::fake();
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $this->createSource($material);
        $url = $this->deliveriesUrl($subject, $course, $lesson);

        $unverified = User::factory()->create([
            'role' => 'user_no_purchase',
            'phone' => null,
            'phone_verified_at' => null,
        ]);
        $this->createGrant($unverified, $lesson, 'unverified');
        Sanctum::actingAs($unverified);
        $this->postJson($url, ['materialId' => $material->id])
            ->assertAccepted()->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseCount('protected_file_deliveries', 1);
        Queue::assertPushed(GenerateProtectedFileDeliveryJob::class, 1);
    }

    public function test_permission_middleware_closes_access_before_delivery_queries_or_jobs(): void
    {
        Queue::fake();
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $this->createSource($material);
        $withoutPermission = User::factory()->create(['role' => 'guest']);
        Sanctum::actingAs($withoutPermission);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->postJson($this->deliveriesUrl($subject, $course, $lesson), ['materialId' => $material->id])->assertForbidden();

        $this->assertFalse(collect($queries)->contains(
            fn (string $query): bool => str_contains($query, 'lesson_materials')
                || str_contains($query, 'lesson_material_files')
                || str_contains($query, 'protected_file_deliveries'),
        ));
        $this->assertDatabaseCount('protected_file_deliveries', 0);
        Queue::assertNothingPushed();
    }

    public function test_status_and_access_recheck_entitlement_before_delivery_query(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $source = $this->createSource($material);
        $delivery = $this->createDelivery($user, $source, ProtectedFileDelivery::STATUS_READY);
        Sanctum::actingAs($user);
        $url = $this->deliveryUrl($subject, $course, $lesson, $delivery);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson($url)->assertForbidden()->assertExactJson($this->accessDeniedPayload());
        $this->postJson("{$url}/access-grants", [
            'confidentialityAcknowledged' => true,
        ])->assertForbidden()->assertExactJson($this->accessDeniedPayload());

        $this->assertFalse(collect($queries)->contains(
            fn (string $query): bool => str_contains($query, 'protected_file_deliveries')
                || str_contains($query, 'lesson_material_files'),
        ));
    }

    public function test_invalid_or_extra_request_fields_fail_before_source_query(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);
        $url = $this->deliveriesUrl($subject, $course, $lesson);

        foreach ([
            [],
            ['materialId' => '1'],
            ['materialId' => 0],
            ['materialId' => 1, 'sourceObjectKey' => 'leak.pdf'],
        ] as $payload) {
            $this->postJson($url, $payload)
                ->assertUnprocessable()
                ->assertExactJson([
                    'error' => [
                        'code' => 'LEARNING_FILE_REQUEST_INVALID',
                        'message' => 'Не удалось определить файл.',
                    ],
                ]);
        }
    }

    public function test_foreign_unpublished_non_pdf_and_missing_materials_share_not_found_contract(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        [, , $otherLesson] = $this->createLesson('physiology', 'physiology-course', 'heart');
        $foreign = $this->createPdfMaterial($otherLesson);
        $unpublished = $this->createPdfMaterial($lesson, false, 'Черновик');
        $video = LessonMaterial::query()->create([
            'lesson_id' => $lesson->id,
            'kind' => LessonMaterial::KIND_VIDEO,
            'title' => 'Видео',
            'is_published' => true,
        ]);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        foreach ([$foreign->id, $unpublished->id, $video->id, 999999] as $materialId) {
            $this->postJson($this->deliveriesUrl($subject, $course, $lesson), [
                'materialId' => $materialId,
            ])->assertNotFound()->assertExactJson($this->fileNotFoundPayload());
        }
    }

    public function test_missing_source_fails_closed_without_queue_dispatch(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $this->postJson($this->deliveriesUrl($subject, $course, $lesson), [
            'materialId' => $material->id,
        ])->assertServiceUnavailable()->assertExactJson($this->fileUnavailablePayload());

        Queue::assertNothingPushed();
    }

    public function test_unsafe_filename_fails_closed_in_request_status_and_access_responses(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $source = $this->createSource($material);
        $source->update(['download_filename' => '../private-notes.pdf']);
        $this->createGrant($user, $lesson);
        $delivery = $this->createDelivery($user, $source, ProtectedFileDelivery::STATUS_READY);
        Sanctum::actingAs($user);

        $this->postJson($this->deliveriesUrl($subject, $course, $lesson), [
            'materialId' => $material->id,
        ])->assertServiceUnavailable()->assertExactJson($this->fileUnavailablePayload());
        Queue::assertNothingPushed();

        $source->update([
            'download_filename' => 'notes.pdf',
            'source_object_key' => '../private/notes.pdf',
        ]);
        $this->postJson($this->deliveriesUrl($subject, $course, $lesson), [
            'materialId' => $material->id,
        ])->assertServiceUnavailable()->assertExactJson($this->fileUnavailablePayload());
        Queue::assertNothingPushed();

        $url = $this->deliveryUrl($subject, $course, $lesson, $delivery);
        $this->getJson($url)->assertServiceUnavailable()->assertExactJson($this->fileUnavailablePayload());

        $issuer = Mockery::mock(TemporaryDownloadUrlIssuer::class);
        $issuer->shouldNotReceive('issue');
        $this->app->instance(TemporaryDownloadUrlIssuer::class, $issuer);
        $this->postJson("{$url}/access-grants", [
            'confidentialityAcknowledged' => true,
        ])->assertServiceUnavailable()->assertExactJson($this->fileUnavailablePayload());
    }

    public function test_failed_delivery_is_requeued_only_while_attempts_remain(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $source = $this->createSource($material);
        $this->createGrant($user, $lesson);
        $delivery = $this->createDelivery($user, $source, ProtectedFileDelivery::STATUS_FAILED);
        $delivery->update([
            'attempt_count' => 1,
            'last_error_code' => 'pdf_unsupported',
            'failed_at' => now(),
        ]);
        Sanctum::actingAs($user);
        $url = $this->deliveriesUrl($subject, $course, $lesson);

        $this->postJson($url, ['materialId' => $material->id])
            ->assertAccepted()
            ->assertJsonPath('data.deliveryId', $delivery->public_id)
            ->assertJsonPath('data.status', ProtectedFileDelivery::STATUS_FAILED);
        Queue::assertPushed(GenerateProtectedFileDeliveryJob::class, 1);

        Queue::fake();
        $delivery->update(['attempt_count' => 3]);
        $this->postJson($url, ['materialId' => $material->id])
            ->assertAccepted()
            ->assertJsonPath('data.deliveryId', $delivery->public_id);
        Queue::assertNothingPushed();
        $this->assertSame(3, $delivery->fresh()->attempt_count);
    }

    public function test_stale_pending_delivery_is_requeued_after_lost_dispatch(): void
    {
        Queue::fake();
        config()->set('protected_files.pending_retry_seconds', 30);
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $source = $this->createSource($material);
        $this->createGrant($user, $lesson);
        $delivery = $this->createDelivery($user, $source, ProtectedFileDelivery::STATUS_PENDING);
        $delivery->forceFill([
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ])->save();
        Sanctum::actingAs($user);

        $this->postJson($this->prewarmUrl($subject, $course, $lesson), [])
            ->assertAccepted()
            ->assertJsonPath('data.deliveries.0.deliveryId', $delivery->public_id)
            ->assertJsonPath('data.deliveries.0.status', ProtectedFileDelivery::STATUS_PENDING);

        Queue::assertPushed(GenerateProtectedFileDeliveryJob::class, 1);
    }

    public function test_status_check_requeues_stale_pending_delivery_after_lost_dispatch(): void
    {
        Queue::fake();
        config()->set('protected_files.pending_retry_seconds', 30);
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $source = $this->createSource($material);
        $this->createGrant($user, $lesson);
        $delivery = $this->createDelivery($user, $source, ProtectedFileDelivery::STATUS_PENDING);
        $delivery->forceFill([
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ])->save();
        Sanctum::actingAs($user);

        $this->getJson($this->deliveryUrl($subject, $course, $lesson, $delivery))
            ->assertOk()
            ->assertJsonPath('data.deliveryId', $delivery->public_id)
            ->assertJsonPath('data.status', ProtectedFileDelivery::STATUS_PENDING);

        Queue::assertPushed(GenerateProtectedFileDeliveryJob::class, 1);
    }

    public function test_only_delivery_owner_can_read_status_and_internal_fields_are_hidden(): void
    {
        $owner = User::factory()->create(['role' => 'user_no_purchase']);
        $other = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $source = $this->createSource($material);
        $this->createGrant($owner, $lesson);
        $this->createGrant($other, $lesson, 'other');
        $delivery = $this->createDelivery($owner, $source);
        $url = $this->deliveryUrl($subject, $course, $lesson, $delivery);

        Sanctum::actingAs($other);
        $this->getJson($url)->assertNotFound()->assertExactJson($this->fileNotFoundPayload());

        Sanctum::actingAs($owner);
        $response = $this->getJson($url)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'data' => [
                    'deliveryId' => $delivery->public_id,
                    'status' => 'pending',
                    'filename' => 'notes.pdf',
                    'canRequestAccess' => false,
                ],
            ]);

        foreach (['sourceObjectKey', 'sourceSha256', 'outputObjectKey', 'lastErrorCode', 'copyCode'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent() ?: '');
        }

        $delivery->update([
            'status' => ProtectedFileDelivery::STATUS_FAILED,
            'last_error_code' => 'private-parser-sentinel',
            'failed_at' => now(),
        ]);
        $failed = $this->getJson($url)
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'deliveryId' => $delivery->public_id,
                    'status' => 'failed',
                    'filename' => 'notes.pdf',
                    'canRequestAccess' => false,
                ],
            ]);
        $this->assertStringNotContainsString('private-parser-sentinel', $failed->getContent() ?: '');
    }

    public function test_mismatched_route_hierarchy_hides_delivery_before_owner_lookup(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, , $lesson] = $this->createLesson();
        [, $otherCourse, $otherLesson] = $this->createLesson('physiology', 'physiology-course', 'heart');
        $material = $this->createPdfMaterial($lesson);
        $source = $this->createSource($material);
        $delivery = $this->createDelivery($user, $source);
        $this->createGrant($user, $lesson);
        Sanctum::actingAs($user);

        $url = "/api/v1/learning/subjects/{$subject->slug}/courses/{$otherCourse->slug}"
            ."/lessons/{$otherLesson->slug}/file-deliveries/{$delivery->public_id}";

        $this->getJson($url)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'LEARNING_LESSON_NOT_FOUND');
    }

    public function test_access_grant_requires_exact_acknowledgement_and_ready_state(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $source = $this->createSource($material);
        $this->createGrant($user, $lesson);
        $delivery = $this->createDelivery($user, $source);
        Sanctum::actingAs($user);
        $url = $this->deliveryUrl($subject, $course, $lesson, $delivery).'/access-grants';

        foreach ([
            [],
            ['confidentialityAcknowledged' => false],
            ['confidentialityAcknowledged' => true, 'extra' => true],
        ] as $payload) {
            $this->postJson($url, $payload)
                ->assertUnprocessable()
                ->assertExactJson([
                    'error' => [
                        'code' => 'LEARNING_FILE_ACCESS_ACKNOWLEDGEMENT_REQUIRED',
                        'message' => 'Подтвердите правила использования файла.',
                    ],
                ]);
        }

        $this->postJson($url, ['confidentialityAcknowledged' => true])
            ->assertConflict()
            ->assertExactJson([
                'error' => [
                    'code' => 'LEARNING_FILE_NOT_READY',
                    'message' => 'Персональная копия файла ещё не готова.',
                ],
            ]);
    }

    public function test_ready_owner_receives_exact_short_lived_url_after_entitlement_recheck(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $source = $this->createSource($material);
        $grant = $this->createGrant($user, $lesson);
        $delivery = $this->createDelivery($user, $source, ProtectedFileDelivery::STATUS_READY);
        Sanctum::actingAs($user);

        $issuer = Mockery::mock(TemporaryDownloadUrlIssuer::class);
        $issuer->shouldReceive('issue')
            ->once()
            ->with('local', 'protected-copies/ready.pdf', Mockery::type(\DateTimeInterface::class), 'notes.pdf')
            ->andReturn('https://files.example.test/copy.pdf?signature=short-lived');
        $this->app->instance(TemporaryDownloadUrlIssuer::class, $issuer);

        $url = $this->deliveryUrl($subject, $course, $lesson, $delivery).'/access-grants';
        $response = $this->postJson($url, ['confidentialityAcknowledged' => true])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson([
                'data' => [
                    'downloadUrl' => 'https://files.example.test/copy.pdf?signature=short-lived',
                    'expiresAt' => now()->addSeconds(120)->toJSON(),
                    'filename' => 'notes.pdf',
                ],
            ]);

        foreach ([$source->source_object_key, $source->source_sha256, $delivery->copy_code, $user->email] as $forbidden) {
            $this->assertStringNotContainsString((string) $forbidden, $response->getContent() ?: '');
        }

        $grant->update([
            'status' => UserEntitlementGrant::STATUS_REVOKED,
            'revoked_at' => now(),
        ]);
        $issuer->shouldNotReceive('issue');

        $this->postJson($url, ['confidentialityAcknowledged' => true])
            ->assertForbidden()
            ->assertExactJson($this->accessDeniedPayload());
    }

    public function test_old_ready_delivery_cannot_issue_url_after_source_version_changes(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $source = $this->createSource($material);
        $this->createGrant($user, $lesson);
        $oldDelivery = $this->createDelivery($user, $source, ProtectedFileDelivery::STATUS_READY);
        $source->update([
            'source_object_key' => "protected-sources/{$material->id}/notes-v2.pdf",
            'source_sha256' => str_repeat('c', 64),
            'source_size_bytes' => 2048,
        ]);
        Sanctum::actingAs($user);

        $issuer = Mockery::mock(TemporaryDownloadUrlIssuer::class);
        $issuer->shouldNotReceive('issue');
        $this->app->instance(TemporaryDownloadUrlIssuer::class, $issuer);
        $oldUrl = $this->deliveryUrl($subject, $course, $lesson, $oldDelivery);

        $this->getJson($oldUrl)
            ->assertServiceUnavailable()
            ->assertExactJson($this->fileUnavailablePayload());
        $this->postJson("{$oldUrl}/access-grants", [
            'confidentialityAcknowledged' => true,
        ])->assertServiceUnavailable()->assertExactJson($this->fileUnavailablePayload());

        $newResponse = $this->postJson($this->deliveriesUrl($subject, $course, $lesson), [
            'materialId' => $material->id,
        ])->assertAccepted();

        $this->assertNotSame($oldDelivery->public_id, $newResponse->json('data.deliveryId'));
        $this->assertDatabaseCount('protected_file_deliveries', 2);
        $this->assertDatabaseHas('protected_file_deliveries', [
            'public_id' => $newResponse->json('data.deliveryId'),
            'source_sha256' => str_repeat('c', 64),
        ]);
        Queue::assertPushed(GenerateProtectedFileDeliveryJob::class, 1);
    }

    public function test_invalid_temporary_url_configuration_fails_closed(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $source = $this->createSource($material);
        $this->createGrant($user, $lesson);
        $delivery = $this->createDelivery($user, $source, ProtectedFileDelivery::STATUS_READY);
        Sanctum::actingAs($user);
        config()->set('protected_files.temporary_url_ttl_seconds', 3600);

        $issuer = Mockery::mock(TemporaryDownloadUrlIssuer::class);
        $issuer->shouldNotReceive('issue');
        $this->app->instance(TemporaryDownloadUrlIssuer::class, $issuer);

        $this->postJson(
            $this->deliveryUrl($subject, $course, $lesson, $delivery).'/access-grants',
            ['confidentialityAcknowledged' => true],
        )->assertServiceUnavailable()->assertExactJson($this->fileUnavailablePayload());
    }

    public function test_unsafe_ready_output_key_is_never_sent_to_url_issuer(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $source = $this->createSource($material);
        $this->createGrant($user, $lesson);
        $delivery = $this->createDelivery($user, $source, ProtectedFileDelivery::STATUS_READY);
        $delivery->update(['output_object_key' => '../private/source.pdf']);
        Sanctum::actingAs($user);

        $issuer = Mockery::mock(TemporaryDownloadUrlIssuer::class);
        $issuer->shouldNotReceive('issue');
        $this->app->instance(TemporaryDownloadUrlIssuer::class, $issuer);

        $this->postJson(
            $this->deliveryUrl($subject, $course, $lesson, $delivery).'/access-grants',
            ['confidentialityAcknowledged' => true],
        )->assertServiceUnavailable()->assertExactJson($this->fileUnavailablePayload());
    }

    public function test_temporary_url_issuer_failure_does_not_expose_internal_error(): void
    {
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        [$subject, $course, $lesson] = $this->createLesson();
        $material = $this->createPdfMaterial($lesson);
        $source = $this->createSource($material);
        $this->createGrant($user, $lesson);
        $delivery = $this->createDelivery($user, $source, ProtectedFileDelivery::STATUS_READY);
        Sanctum::actingAs($user);

        $issuer = Mockery::mock(TemporaryDownloadUrlIssuer::class);
        $issuer->shouldReceive('issue')
            ->once()
            ->andThrow(new \RuntimeException('private storage credential sentinel'));
        $this->app->instance(TemporaryDownloadUrlIssuer::class, $issuer);

        $response = $this->postJson(
            $this->deliveryUrl($subject, $course, $lesson, $delivery).'/access-grants',
            ['confidentialityAcknowledged' => true],
        )->assertServiceUnavailable()->assertExactJson($this->fileUnavailablePayload());

        $this->assertStringNotContainsString(
            'private storage credential sentinel',
            $response->getContent() ?: '',
        );
    }

    /** @return array{Subject, Course, Lesson} */
    private function createLesson(
        string $subjectSlug = 'anatomy',
        string $courseSlug = 'osteology',
        string $lessonSlug = 'bones',
    ): array {
        $subject = Subject::query()->create([
            'slug' => $subjectSlug,
            'title' => "Предмет {$subjectSlug}",
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => $courseSlug,
            'title' => "Курс {$courseSlug}",
            'price_cents' => 100000,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => $lessonSlug,
            'title' => "Урок {$lessonSlug}",
        ]);

        return [$subject, $course, $lesson];
    }

    private function createPdfMaterial(
        Lesson $lesson,
        bool $published = true,
        string $title = 'Конспект PDF',
    ): LessonMaterial {
        return LessonMaterial::query()->create([
            'lesson_id' => $lesson->id,
            'kind' => LessonMaterial::KIND_PDF,
            'title' => $title,
            'is_published' => $published,
        ]);
    }

    private function createSource(LessonMaterial $material): LessonMaterialFile
    {
        return LessonMaterialFile::query()->create([
            'lesson_material_id' => $material->id,
            'source_object_key' => "protected-sources/{$material->id}/notes.pdf",
            'source_sha256' => str_repeat('a', 64),
            'source_size_bytes' => 1024,
            'source_mime_type' => 'application/pdf',
            'download_filename' => 'notes.pdf',
        ]);
    }

    private function createDelivery(
        User $user,
        LessonMaterialFile $source,
        string $status = ProtectedFileDelivery::STATUS_PENDING,
    ): ProtectedFileDelivery {
        return ProtectedFileDelivery::query()->create([
            'public_id' => (string) str()->uuid(),
            'user_id' => $user->id,
            'lesson_material_file_id' => $source->id,
            'source_sha256' => $source->source_sha256,
            'copy_code' => 'COPY-'.strtoupper(str()->random(16)),
            'status' => $status,
            'output_object_key' => $status === ProtectedFileDelivery::STATUS_READY
                ? 'protected-copies/ready.pdf'
                : null,
            'output_sha256' => $status === ProtectedFileDelivery::STATUS_READY
                ? str_repeat('b', 64)
                : null,
            'output_size_bytes' => $status === ProtectedFileDelivery::STATUS_READY ? 2048 : null,
            'ready_at' => $status === ProtectedFileDelivery::STATUS_READY ? now() : null,
        ]);
    }

    private function createGrant(
        User $user,
        Lesson $lesson,
        string $suffix = 'owner',
    ): UserEntitlementGrant {
        return UserEntitlementGrant::query()->create([
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lesson->id,
            'status' => UserEntitlementGrant::STATUS_ACTIVE,
            'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
            'source_id' => "protected-file-lesson-{$lesson->id}-{$suffix}",
            'idempotency_key' => "protected-file-{$user->id}-{$lesson->id}-{$suffix}",
            'granted_at' => now(),
        ]);
    }

    private function deliveriesUrl(Subject $subject, Course $course, Lesson $lesson): string
    {
        return "/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}/lessons/{$lesson->slug}/file-deliveries";
    }

    private function prewarmUrl(Subject $subject, Course $course, Lesson $lesson): string
    {
        return $this->deliveriesUrl($subject, $course, $lesson).'/prewarm';
    }

    private function deliveryUrl(
        Subject $subject,
        Course $course,
        Lesson $lesson,
        ProtectedFileDelivery $delivery,
    ): string {
        return $this->deliveriesUrl($subject, $course, $lesson)."/{$delivery->public_id}";
    }

    /** @return array{error: array{code: string, message: string}} */
    private function accessDeniedPayload(): array
    {
        return ['error' => [
            'code' => 'LEARNING_ACCESS_DENIED',
            'message' => 'У пользователя нет доступа к этому уроку.',
        ]];
    }

    /** @return array{error: array{code: string, message: string}} */
    private function fileNotFoundPayload(): array
    {
        return ['error' => [
            'code' => 'LEARNING_FILE_NOT_FOUND',
            'message' => 'Файл не найден.',
        ]];
    }

    /** @return array{error: array{code: string, message: string}} */
    private function fileUnavailablePayload(): array
    {
        return ['error' => [
            'code' => 'LEARNING_FILE_UNAVAILABLE',
            'message' => 'Файл временно недоступен.',
        ]];
    }
}
