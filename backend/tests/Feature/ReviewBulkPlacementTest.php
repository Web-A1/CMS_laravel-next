<?php

namespace Tests\Feature;

use App\Exceptions\AdminReviewException;
use App\Models\AdminOperationEvent;
use App\Models\Review;
use App\Models\ReviewModerationEvent;
use App\Models\User;
use App\Services\Reviews\AdminReviewDeletion;
use App\Services\Reviews\AdminReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReviewBulkPlacementTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $reviews = [];
        foreach (['published', 'draft', 'hidden', 'published'] as $n => $status) {
            $review = Review::create(['display_name' => 'Автор '.$n, 'text' => 'Отзыв '.$n, 'source' => 'legacy_ocr',
                'origin_type' => 'legacy_import', 'status' => $status === 'hidden' ? 'published' : $status,
                'publication_status' => $status, 'published_at' => now()->subDay(), 'review_written_at' => '2026-01-15',
                'show_on_home' => $n === 3, 'author_photo_path' => 'review-authors/test-'.$n.'.png']);
            $review->sources()->create(['source_type' => 'legacy_site', 'source_ref' => 'placement-'.$n, 'raw_text' => $review->text]);
            $review->media()->create(['type' => 'screenshot', 'url' => '/storage/review-screenshots/test-'.$n.'.png', 'width' => 360, 'height' => 720]);
            $reviews[] = $review;
        }

        return [$actor, $reviews];
    }

    private function items(array $reviews): array
    {
        return array_map(fn ($r) => ['id' => $r->id, 'revision' => (int) $r->moderationEvents()->max('id')], $reviews);
    }

    public function test_bulk_on_off_preserves_publication_content_media_and_unselected_and_audits_each_review(): void
    {
        [$actor, $all] = $this->fixture();
        $selected = array_slice($all, 0, 3);
        $before = array_map(fn ($r) => $r->fresh()->getAttributes(), $all);
        $sources = DB::table('review_sources')->orderBy('id')->get()->toJson();
        $media = DB::table('review_media')->orderBy('id')->get()->toJson();
        $service = app(AdminReviewService::class);
        $items = $this->items($selected);
        $result = $service->placeMany($actor, array_reverse($items), true, 'bulk-on');
        $this->assertSame(['ids' => array_column($items, 'id'), 'showOnHome' => true, 'count' => 3], $result);
        $this->assertSame($result, $service->placeMany($actor, $items, true, 'bulk-on'));
        $this->assertDatabaseCount('review_moderation_events', 3);
        $this->assertDatabaseCount('admin_operation_events', 1);
        $this->getJson('/api/v1/reviews?placement=home')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/reviews')->assertOk()->assertJsonCount(2, 'data');
        foreach ($all as $n => $r) {
            $after = $r->fresh()->getAttributes();
            $this->assertSame(array_diff_key($before[$n], array_flip(['show_on_home', 'updated_at'])), array_diff_key($after, array_flip(['show_on_home', 'updated_at'])));
            $this->assertTrue($r->fresh()->show_on_home);
        }
        $this->assertSame($sources, DB::table('review_sources')->orderBy('id')->get()->toJson());
        $this->assertSame($media, DB::table('review_media')->orderBy('id')->get()->toJson());
        foreach (ReviewModerationEvent::all() as $event) {
            $this->assertSame('admin_placement', $event->event_type);
            $this->assertSame($actor->id, $event->actor_user_id);
            $this->assertSame(['showOnHome' => ['before' => false, 'after' => true]], $event->metadata['changes']);
        }
        $service->placeMany($actor, $this->items($selected), false, 'bulk-off');
        $this->getJson('/api/v1/reviews?placement=home')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $all[3]->id);
        $this->getJson('/api/v1/reviews/'.$all[0]->id)->assertOk();
        $this->assertDatabaseCount('review_image_deletions', 0);
        $this->assertDatabaseCount('review_moderation_events', 6);
    }

    public function test_later_stale_revision_rolls_back_the_entire_group(): void
    {
        [$actor, [$a, $b]] = $this->fixture();
        $items = $this->items([$a, $b]);
        $b->moderationEvents()->create(['event_type' => 'admin_update']);
        try {
            app(AdminReviewService::class)->placeMany($actor, $items, true, 'stale');
            $this->fail('A stale item must cancel all changes.');
        } catch (AdminReviewException $e) {
            $this->assertSame('ADMIN_REVIEW_REVISION_CONFLICT', $e->errorCode);
        }
        $this->assertFalse($a->fresh()->show_on_home);
        $this->assertFalse($b->fresh()->show_on_home);
        $this->assertDatabaseCount('review_moderation_events', 1);
        $this->assertDatabaseCount('admin_operation_events', 0);
    }

    public function test_missing_selected_review_does_not_change_the_others(): void
    {
        [$actor, [$a, $b]] = $this->fixture();
        $items = $this->items([$a, $b]);
        $b->delete();
        try {
            app(AdminReviewService::class)->placeMany($actor, $items, true, 'missing');
            $this->fail('Missing item must cancel the group.');
        } catch (AdminReviewException $e) {
            $this->assertSame(404, $e->httpStatus);
        }
        $this->assertFalse($a->fresh()->show_on_home);
        $this->assertDatabaseCount('review_moderation_events', 0);
    }

    public function test_batch_audit_failure_rolls_back_flags_and_individual_history(): void
    {
        [$actor, [$a, $b]] = $this->fixture();
        AdminOperationEvent::creating(fn () => throw new \RuntimeException('Synthetic receipt failure'));
        try {
            app(AdminReviewService::class)->placeMany($actor, $this->items([$a, $b]), true, 'audit');
            $this->fail('Audit must be transactional.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic receipt failure', $e->getMessage());
        } finally {
            AdminOperationEvent::flushEventListeners();
        }
        $this->assertFalse($a->fresh()->show_on_home);
        $this->assertFalse($b->fresh()->show_on_home);
        $this->assertDatabaseCount('review_moderation_events', 0);
    }

    public function test_used_batch_key_rejects_other_target_selection_edit_or_deletion(): void
    {
        [$actor, [$a, $b, $other]] = $this->fixture();
        $service = app(AdminReviewService::class);
        $items = $this->items([$a, $b]);
        $service->placeMany($actor, $items, true, 'used');
        foreach ([fn () => $service->placeMany($actor, $items, false, 'used'),
            fn () => $service->placeMany($actor, $this->items([$other]), true, 'used'),
            fn () => $service->execute($actor, 'placement', $other->id, ['showOnHome' => true, 'revision' => 0], 'used'),
            fn () => app(AdminReviewDeletion::class)->delete($actor, $other->id, ['confirmation' => (string) $other->id, 'revision' => 0], 'used'),
            fn () => app(AdminReviewDeletion::class)->deleteMany($actor, $this->items([$other]), '1', 'used')] as $attempt) {
            try {
                $attempt();
                $this->fail('Key reuse must fail.');
            } catch (AdminReviewException $e) {
                $this->assertSame('ADMIN_REVIEW_IDEMPOTENCY_CONFLICT', $e->errorCode);
            }
        }
        $this->assertFalse($other->fresh()->show_on_home);
        $this->assertDatabaseCount('reviews', 4);
        $this->assertDatabaseCount('review_moderation_events', 2);
    }

    public function test_single_edit_or_deletion_key_cannot_be_reused_for_bulk_placement(): void
    {
        [$actor, [$a, $b, $other]] = $this->fixture();
        $service = app(AdminReviewService::class);
        $service->execute($actor, 'placement', $a->id, ['showOnHome' => true, 'revision' => 0], 'edit');
        app(AdminReviewDeletion::class)->deleteMany($actor, $this->items([$b]), '1', 'delete');
        foreach (['edit', 'delete'] as $key) {
            try {
                $service->placeMany($actor, $this->items([$other]), true, $key);
                $this->fail('Previous operation key must fail.');
            } catch (AdminReviewException $e) {
                $this->assertSame('ADMIN_REVIEW_IDEMPOTENCY_CONFLICT', $e->errorCode);
            }
        }
        $this->assertFalse($other->fresh()->show_on_home);
    }

    public function test_invalid_selection_and_revoked_role_are_rejected(): void
    {
        [$actor, [$a]] = $this->fixture();
        $item = $this->items([$a])[0];
        foreach ([[], [$item, $item], [array_merge($item, ['text' => 'forbidden'])], array_fill(0, 101, $item)] as $items) {
            try {
                app(AdminReviewService::class)->placeMany($actor, $items, true, 'invalid');
                $this->fail('Invalid selection must fail.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $actor->update(['role' => 'admin_limited']);
        try {
            app(AdminReviewService::class)->placeMany($actor, [$item], true, 'revoked');
            $this->fail('Role must be rechecked.');
        } catch (AdminReviewException $e) {
            $this->assertSame(403, $e->httpStatus);
        }
        $this->assertFalse($a->fresh()->show_on_home);
    }
}
