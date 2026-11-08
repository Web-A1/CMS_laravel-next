<?php

namespace Tests\Feature;

use App\Models\AdminOperationEvent;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAssessment;
use App\Models\LessonAssessmentVersion;
use App\Models\Order;
use App\Models\Review;
use App\Models\ReviewImageAsset;
use App\Models\Subject;
use App\Models\User;
use App\Services\AdminUserBulkDeletion;
use App\Services\ProtectedFiles\ProtectedFileOutputRemoval;
use Database\Seeders\LessonAssessmentSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class AdminUserBulkDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_and_unpaid_profiles_are_erased_with_history_and_control_rows_preserved(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $paid = User::factory()->create(['role' => 'user_with_purchase']);
        $unpaid = User::factory()->create(['role' => 'user_no_purchase']);
        $control = User::factory()->create();
        $order = Order::create(['user_id' => $paid->id, 'amount_total' => 100, 'amount_final' => 100]);
        $controlOrder = Order::create(['user_id' => $control->id, 'amount_total' => 10, 'amount_final' => 10]);
        $review = Review::create(['user_id' => $paid->id, 'display_name' => 'Learner', 'text' => 'Review', 'source' => 'admin']);
        $log = DB::table('admin_user_events')->insertGetId(['user_id' => $paid->id, 'actor_id' => $actor->id,
            'actor_name' => 'Admin', 'type' => 'comment', 'changes' => '{}', 'created_at' => now()]);
        $paymentLog = DB::table('payment_audit_log')->insertGetId(['order_id' => $order->id, 'action' => 'test',
            'created_at' => now()]);
        $service = app(AdminUserBulkDeletion::class);
        $ids = [$unpaid->id, $paid->id];
        $preview = $service->preview($actor, $ids);
        $key = (string) Str::uuid();
        $result = $service->deleteMany($actor, $ids, $preview['fingerprint'], '2', $key);
        $this->assertTrue($result['deleted']);
        $this->assertSame($result, $service->deleteMany($actor, $ids, $preview['fingerprint'], '2', $key));
        $this->assertDatabaseMissing('users', ['id' => $paid->id]);
        $this->assertDatabaseMissing('users', ['id' => $unpaid->id]);
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        $this->assertDatabaseHas('users', ['id' => $control->id]);
        $this->assertDatabaseHas('orders', ['id' => $controlOrder->id]);
        $this->assertDatabaseHas('admin_user_events', ['id' => $log, 'user_id' => $paid->id]);
        $this->assertDatabaseHas('payment_audit_log', ['id' => $paymentLog, 'order_id' => $order->id]);
        $this->assertSame(1, AdminOperationEvent::where('operation', 'delete_users')->count());
    }

    public function test_stale_preview_and_invalid_batch_cannot_delete_any_profile(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $users = User::factory()->count(2)->create(['role' => 'user_no_purchase']);
        $service = app(AdminUserBulkDeletion::class);
        $ids = $users->modelKeys();
        $preview = $service->preview($actor, $ids);
        $users[1]->update(['name' => 'Changed']);
        $this->refuses(409, fn () => $service->deleteMany($actor, $ids, $preview['fingerprint'], '2', 'stale'));
        foreach ($ids as $id) {
            $this->assertDatabaseHas('users', ['id' => $id]);
        }
        $this->refuses(403, fn () => $service->preview($actor, [$users[0]->id, $actor->id]));
        $users[1]->update(['role' => 'teacher']);
        $this->refuses(403, fn () => $service->preview($actor, $ids));
    }

    public function test_pending_payment_blocks_entire_batch_and_revoked_actor_cannot_replay(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $user = User::factory()->create(['role' => 'user_with_purchase']);
        $service = app(AdminUserBulkDeletion::class);
        $order = Order::create(['user_id' => $user->id, 'amount_total' => 100, 'amount_final' => 100]);
        DB::table('payment_attempts')->insert(['order_id' => $order->id, 'provider' => 'test', 'status' => 'pending',
            'amount' => 100, 'currency' => 'RUB', 'created_at' => now(), 'updated_at' => now()]);
        $this->refuses(409, fn () => $service->preview($actor, [$user->id]));
        DB::table('payment_attempts')->where('order_id', $order->id)->update(['status' => 'completed']);
        $preview = $service->preview($actor, [$user->id]);
        $service->deleteMany($actor, [$user->id], $preview['fingerprint'], '1', 'once');
        $actor->update(['role' => 'user_no_purchase']);
        $this->refuses(403, fn () => $service->deleteMany($actor, [$user->id], $preview['fingerprint'], '1', 'once'));
    }

    public function test_deep_owned_graph_is_removed_without_mutating_immutable_history(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $user = User::factory()->create(['role' => 'user_with_purchase']);
        $fixture = self::seedHistory($actor, $user);
        $service = app(AdminUserBulkDeletion::class);
        $preview = $service->preview($actor, [$user->id]);
        $service->deleteMany($actor, [$user->id], $preview['fingerprint'], '1', 'deep');
        foreach ($fixture['owned'] as $table => $id) {
            $this->assertDatabaseMissing($table, ['id' => $id]);
        }
        foreach ($fixture['retained'] as $table => $row) {
            $this->assertEquals($row, DB::table($table)->find($row->id), $table);
        }
        $this->assertDatabaseHas('video_watermark_sessions', ['id' => $fixture['watermark'], 'user_id' => $user->id, 'end_reason' => 'revoked']);
        $this->assertDatabaseHas('lessons', ['id' => $fixture['lesson']]);
    }

    public function test_shared_review_asset_and_other_review_survive_owner_deletion(): void
    {
        Storage::fake('public');
        $actor = User::factory()->create(['role' => 'admin_full']);
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        $control = User::factory()->create();
        $id = (string) Str::uuid();
        $path = 'review-authors/'.$id.'.webp';
        $review = Review::create(['user_id' => $user->id, 'display_name' => 'Owned', 'text' => 'Owned', 'source' => 'admin', 'author_photo_path' => $path]);
        $other = Review::create(['user_id' => $control->id, 'display_name' => 'Other', 'text' => 'Other', 'source' => 'admin', 'author_photo_path' => $path]);
        ReviewImageAsset::create(['id' => $id, 'owner_id' => $user->id, 'review_id' => $review->id,
            'kind' => 'avatar', 'status' => 'ready', 'disk' => 'public', 'primary_path' => $path,
            'source_mime' => 'image/webp', 'width' => 192, 'height' => 192, 'variants' => []]);
        Storage::disk('public')->put($path, 'shared');
        $service = app(AdminUserBulkDeletion::class);
        $preview = $service->preview($actor, [$user->id]);
        $service->deleteMany($actor, [$user->id], $preview['fingerprint'], '1', 'shared');
        $this->assertDatabaseHas('reviews', ['id' => $other->id, 'author_photo_path' => $path]);
        $this->assertDatabaseHas('review_image_assets', ['id' => $id, 'status' => 'ready']);
        Storage::disk('public')->assertExists($path);
    }

    public function test_pdf_cleanup_is_durable_repeatable_and_rejects_source_paths(): void
    {
        config(['protected_files.output_disk' => 'local', 'protected_files.source_disk' => 'local']);
        Storage::fake('local');
        Queue::fake();
        $key = 'protected-copies/'.Str::uuid().'/'.Str::uuid().'.pdf';
        $source = 'protected-sources/original.pdf';
        Storage::disk('local')->put($key, 'personal');
        Storage::disk('local')->put($source, 'source');
        $removal = app(ProtectedFileOutputRemoval::class);
        $removal->schedule($key);
        $entry = DB::table('protected_file_output_deletions')->sole();
        $this->assertTrue($removal->run($entry->id));
        $this->assertTrue($removal->run($entry->id));
        $this->assertNotNull(DB::table('protected_file_output_deletions')->value('completed_at'));
        Storage::disk('local')->assertMissing($key);
        Storage::disk('local')->assertExists($source);
        $this->refuses(409, fn () => $removal->schedule($source));
    }

    public function test_environment_impersonation_expiry_and_changed_ids_are_rechecked(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $first = User::factory()->create(['role' => 'user_no_purchase']);
        $second = User::factory()->create(['role' => 'user_no_purchase']);
        $service = app(AdminUserBulkDeletion::class);
        $preview = $service->preview($actor, [$first->id]);
        $this->refuses(409, fn () => $service->deleteMany($actor, [$second->id], $preview['fingerprint'], '1', 'changed'));
        $this->travel(11)->minutes();
        $this->refuses(409, fn () => $service->deleteMany($actor, [$first->id], $preview['fingerprint'], '1', 'expired'));
        $this->travelBack();
        request()->attributes->set('impersonation.active', true);
        $this->refuses(403, fn () => $service->preview($actor, [$first->id]));
        request()->attributes->remove('impersonation.active');
        $this->app->instance('env', 'production');
        $this->refuses(403, fn () => $service->preview($actor, [$first->id]));
        $this->assertDatabaseHas('users', ['id' => $first->id]);
        $this->assertDatabaseHas('users', ['id' => $second->id]);
    }

    public function test_late_database_failure_rolls_back_all_users_children_and_receipt(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $users = User::factory()->count(2)->create(['role' => 'user_no_purchase']);
        foreach ($users as $user) {
            Order::create(['user_id' => $user->id, 'amount_total' => 1, 'amount_final' => 1]);
        }
        $service = app(AdminUserBulkDeletion::class);
        $preview = $service->preview($actor, $users->modelKeys());
        DB::unprepared('CREATE TRIGGER synthetic_delete_failure BEFORE DELETE ON users WHEN OLD.id = '.(int) $users[1]->id." BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try {
            $service->deleteMany($actor, $users->modelKeys(), $preview['fingerprint'], '2', 'rollback');
            $this->fail('Synthetic database failure must abort deletion.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('synthetic failure', $exception->getMessage());
        }
        foreach ($users as $user) {
            $this->assertDatabaseHas('users', ['id' => $user->id]);
            $this->assertDatabaseHas('orders', ['user_id' => $user->id]);
        }
        $this->assertSame(0, AdminOperationEvent::where('operation', 'delete_users')->count());
    }

    public function test_incomplete_side_effect_without_direct_order_reference_blocks_deletion(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $user = User::factory()->create(['role' => 'user_with_purchase']);
        $order = Order::create(['user_id' => $user->id, 'amount_total' => 1, 'amount_final' => 1]);
        $event = DB::table('outbox_events')->insertGetId(['order_id' => $order->id, 'event_type' => 'test', 'status' => 'processed', 'payload' => '{}']);
        DB::table('side_effect_executions')->insert(['outbox_event_id' => $event, 'handler' => 'test', 'dedup_key' => 'synthetic', 'status' => 'unknown']);
        $this->refuses(409, fn () => app(AdminUserBulkDeletion::class)->preview($actor, [$user->id]));
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_idempotency_key_is_bound_to_actor_selection_and_preview(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $otherActor = User::factory()->create(['role' => 'admin_full']);
        $first = User::factory()->create(['role' => 'user_no_purchase']);
        $second = User::factory()->create(['role' => 'user_no_purchase']);
        $service = app(AdminUserBulkDeletion::class);
        $preview = $service->preview($actor, [$first->id]);
        $otherPreview = $service->preview($otherActor, [$second->id]);
        $service->deleteMany($actor, [$first->id], $preview['fingerprint'], '1', 'shared-key');
        $this->refuses(409, fn () => $service->deleteMany($otherActor, [$second->id], $otherPreview['fingerprint'], '1', 'shared-key'));
        $this->assertDatabaseHas('users', ['id' => $second->id]);
    }

    public function test_pdf_cleanup_failure_remains_retryable(): void
    {
        $id = DB::table('protected_file_output_deletions')->insertGetId(['disk' => 'synthetic-output',
            'object_key' => 'protected-copies/'.Str::uuid().'/'.Str::uuid().'.pdf', 'created_at' => now(), 'updated_at' => now()]);
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->twice()->andReturn(true);
        $disk->shouldReceive('delete')->once()->ordered()->andReturn(false);
        $disk->shouldReceive('delete')->once()->ordered()->andReturn(true);
        Storage::shouldReceive('disk')->with('synthetic-output')->twice()->andReturn($disk);
        $removal = app(ProtectedFileOutputRemoval::class);
        $this->assertFalse($removal->run($id));
        $this->assertNull(DB::table('protected_file_output_deletions')->find($id)->completed_at);
        $this->assertNotNull(DB::table('protected_file_output_deletions')->find($id)->error);
        $this->assertTrue($removal->run($id));
        $this->assertNotNull(DB::table('protected_file_output_deletions')->find($id)->completed_at);
    }

    public function test_pending_retention_migration_gives_an_actionable_readiness_refusal(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $user = User::factory()->create(['role' => 'user_no_purchase']);
        DB::table('migrations')->where('migration', '2026_10_20_120000_preserve_user_deletion_audit_history')->delete();
        $this->refuses(503, fn () => app(AdminUserBulkDeletion::class)->preview($actor, [$user->id]));
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public static function seedHistory(User $actor, User $user): array
    {
        $stamp = ['created_at' => now(), 'updated_at' => now()];
        $hash = str_repeat('a', 64);
        $suffix = (string) Str::uuid();
        $subject = Subject::create(['title' => 'Shared subject', 'slug' => 'subject-'.$suffix]);
        $course = Course::create(['subject_id' => $subject->id, 'title' => 'Shared course', 'slug' => 'course-'.$suffix, 'price_cents' => 0]);
        $lesson = Lesson::create(['course_id' => $course->id, 'title' => 'Shared lesson', 'slug' => 'lesson-'.$suffix]);
        $assessment = LessonAssessment::create(['lesson_id' => $lesson->id]);
        $fixture = LessonAssessmentSeeder::validatedFixture();
        $version = LessonAssessmentVersion::forceCreate(['lesson_assessment_id' => $assessment->id, 'status' => 'draft',
            'schema_version' => $fixture['schemaVersion'], 'content_version' => $fixture['contentVersion'], 'scoring_policy' => $fixture['scoringPolicy'],
            'source_assignment_sha256' => $hash, 'source_key_sha256' => $hash, 'sections' => [],
            'questions' => [], 'answer_key' => (object) []]);
        $attempt = DB::table('lesson_assessment_attempts')->insertGetId($stamp + ['user_id' => $user->id,
            'lesson_assessment_id' => $assessment->id, 'lesson_assessment_version_id' => $version->id, 'status' => 'in_progress']);
        $response = DB::table('lesson_assessment_attempt_responses')->insertGetId($stamp + ['lesson_assessment_attempt_id' => $attempt,
            'question_id' => 'test-question', 'response' => '{}']);
        $manual = DB::table('lesson_assessment_manual_reviews')->insertGetId($stamp + ['lesson_assessment_attempt_id' => $attempt,
            'lesson_assessment_attempt_response_id' => $response, 'question_id' => 'test-question', 'response_snapshot' => '{}',
            'response_snapshot_sha256' => $hash, 'rubric_snapshot' => '{}', 'rubric_snapshot_sha256' => $hash]);
        $event = DB::table('lesson_assessment_review_events')->insertGetId(['lesson_assessment_attempt_id' => $attempt,
            'lesson_assessment_manual_review_id' => $manual, 'actor_id' => $actor->id, 'event_type' => 'review_updated',
            'previous_attempt_status' => 'pending_review', 'attempt_status' => 'pending_review', 'revision' => 1,
            'idempotency_key_hash' => $hash, 'request_fingerprint_sha256' => $hash, 'created_at' => now()]);
        $order = Order::create(['user_id' => $user->id, 'amount_total' => 0, 'amount_final' => 0]);
        $reservation = DB::table('coin_reservations')->insertGetId($stamp + ['user_id' => $user->id, 'order_id' => $order->id,
            'idempotency_key' => 'coin-'.$suffix, 'intent_hash' => $hash, 'units' => 1, 'status' => 'released', 'expires_at' => now(), 'proof' => '{}']);
        $ledger = DB::table('coin_ledger_entries')->insertGetId($stamp + ['user_id' => $user->id, 'order_id' => $order->id,
            'reservation_id' => $reservation, 'event_type' => 'grant', 'units' => 1, 'idempotency_key' => 'ledger-'.$suffix]);
        $allocation = DB::table('coin_reservation_allocations')->insertGetId($stamp + ['reservation_id' => $reservation, 'lot_id' => $ledger, 'units' => 1]);
        $account = DB::table('trusted_browser_accounts')->insertGetId($stamp + ['user_id' => $user->id, 'cookie_key' => bin2hex(random_bytes(16))]);
        $trustedLog = DB::table('trusted_browser_audit_events')->insertGetId(['account_id' => $account, 'user_id' => $user->id,
            'actor_user_id' => $actor->id, 'event_type' => 'test', 'metadata' => '{"original":true}', 'created_at' => now()]);
        $watermark = (string) Str::uuid();
        DB::table('video_watermark_sessions')->insert($stamp + ['id' => $watermark, 'code' => 'CMS-'.strtoupper(substr(str_replace(['0', '1', 'i', 'o'], '2', $suffix), 0, 4)).'-ABCD',
            'auth_session_hash' => hash('sha256', $suffix), 'user_id' => $user->id, 'started_at' => now(), 'last_used_at' => now()]);
        $run = (string) Str::uuid();
        DB::table('legacy_user_import_runs')->insert(['id' => $run, 'source_database' => 'synthetic', 'snapshot_id' => $suffix,
            'mapping_version' => 1, 'manifest_sha256' => $hash, 'approved_report_sha256' => $hash,
            'target_environment' => 'testing', 'target_database' => 'isolated', 'status' => 'completed', 'checkpoints' => '{}', 'started_at' => now()]);
        $revision = $stamp + ['source_fingerprint' => $hash, 'first_import_run_id' => $run, 'last_import_run_id' => $run];
        $profile = DB::table('legacy_user_profiles')->insertGetId($revision + ['user_id' => $user->id, 'source_member_id' => $user->id,
            'display_name' => 'Legacy learner', 'source_email' => $user->email, 'mapping_status' => 'matched', 'payload' => '{}', 'managed_values' => '{}', 'issues' => '[]']);
        $legacyOrder = DB::table('legacy_user_orders')->insertGetId($revision + ['source_order_id' => $order->id,
            'legacy_user_profile_id' => $profile, 'source_member_id' => $user->id, 'ownership_status' => 'owned', 'source_status' => 1,
            'amount_minor' => 0, 'amount_decimal' => 0, 'currency' => 'RUB', 'currency_basis' => 'test', 'payload' => '{}', 'issues' => '[]']);
        $record = DB::table('legacy_user_records')->insertGetId($revision + ['source_table' => 'synthetic', 'source_id' => $user->id,
            'legacy_user_profile_id' => $profile, 'source_member_id' => $user->id, 'ownership_status' => 'owned', 'legacy_user_order_id' => $legacyOrder,
            'category' => 'history', 'payload' => '{"retained":true}', 'issues' => '[]']);
        $review = Review::create(['user_id' => $user->id, 'display_name' => 'Learner', 'text' => 'Owned review', 'source' => 'admin']);
        $reviewEvent = DB::table('review_moderation_events')->insertGetId($stamp + ['review_id' => $review->id,
            'actor_user_id' => $actor->id, 'event_type' => 'test', 'metadata' => '{"retained":true}']);
        $retained = [];
        foreach (['lesson_assessment_review_events' => $event, 'coin_ledger_entries' => $ledger, 'coin_reservation_allocations' => $allocation,
            'trusted_browser_audit_events' => $trustedLog, 'legacy_user_records' => $record, 'review_moderation_events' => $reviewEvent] as $table => $id) {
            $retained[$table] = DB::table($table)->find($id);
        }

        return ['owned' => ['lesson_assessment_attempts' => $attempt, 'lesson_assessment_attempt_responses' => $response,
            'lesson_assessment_manual_reviews' => $manual, 'coin_reservations' => $reservation, 'orders' => $order->id,
            'trusted_browser_accounts' => $account, 'legacy_user_profiles' => $profile, 'legacy_user_orders' => $legacyOrder, 'reviews' => $review->id],
            'retained' => $retained, 'watermark' => $watermark, 'lesson' => $lesson->id];
    }

    protected function refuses(int $status, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Operation unexpectedly succeeded');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }
}
