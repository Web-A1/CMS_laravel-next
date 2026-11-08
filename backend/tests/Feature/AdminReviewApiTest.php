<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Review;
use App\Models\ReviewDuplicateCandidate;
use App\Models\ReviewEvidence;
use App\Models\ReviewMedia;
use App\Models\ReviewModerationEvent;
use App\Models\Subject;
use App\Models\User;
use App\Services\Reviews\ReviewImageAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminReviewApiTest extends TestCase
{
    use RefreshDatabase;

    private function input(array $overrides = []): array
    {
        return array_replace([
            'displayName' => 'Мария', 'isAnonymous' => false,
            'text' => 'Очень полезный курс: теперь я уверенно применяю знания на практике.',
            'reviewWrittenAt' => '2026-01-15', 'scope' => 'all',
            'subjectId' => null, 'courseId' => null,
        ], $overrides);
    }

    private function admin(): User
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        Sanctum::actingAs($actor);

        return $actor;
    }

    private function createReview(array $overrides = [], string $key = 'create-review'): array
    {
        return $this->postJson('/api/v1/admin/reviews', $this->input($overrides), ['Idempotency-Key' => $key])
            ->assertCreated()->json('data');
    }

    private function transition(array $review, string $action, string $key)
    {
        return $this->patchJson("/api/v1/admin/reviews/{$review['id']}/publication", [
            'action' => $action, 'revision' => $review['revision'],
        ], ['Idempotency-Key' => $key]);
    }

    public function test_prepared_assets_enforce_ownership_and_public_variants_are_explicit_and_private_metadata_is_absent(): void
    {
        $owner = $this->admin();
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();
        $assets = app(ReviewImageAssets::class);
        $photo = $assets->stage(UploadedFile::fake()->image('photo.jpg', 200, 300), 'avatar', $owner);
        $screen = $assets->stage(UploadedFile::fake()->image('review.png', 300, 500), 'screenshot', $owner);
        $assets->process($photo->id);
        $assets->process($screen->id);
        $this->admin();
        $input = ['authorPhotoPath' => $photo->primary_path, 'screenshotPaths' => [$screen->primary_path]];
        $this->postJson('/api/v1/admin/reviews', $this->input($input), ['Idempotency-Key' => 'foreign-asset'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'ADMIN_REVIEW_VALIDATION_FAILED');
        $this->assertDatabaseCount('reviews', 0);
        Sanctum::actingAs($owner);
        $review = $this->createReview($input);
        $this->transition($review, 'publish', 'publish-assets')->assertOk();
        $this->getJson('/api/v1/reviews')->assertOk()->assertJsonMissingPath('data.0.authorPhotoVariants')
            ->assertJsonMissingPath('data.0.media.0.variants');
        $this->getJson('/api/v1/reviews?image_variants=1')->assertOk()
            ->assertJsonCount(6, 'data.0.authorPhotoVariants')->assertJsonCount(6, 'data.0.media.0.variants')
            ->assertJsonMissingPath('data.0.media.0.variants.0.path')->assertJsonMissingPath('data.0.source_path')
            ->assertJsonPath('data.0.media.0.variants.0.format', 'webp');
    }

    public function test_screenshots_reject_traversal_missing_files_invalid_content_and_non_boolean_placement(): void
    {
        $this->admin();
        Storage::fake('public');
        Storage::disk('public')->put('review-screenshots/fake.jpg', '<script>not an image</script>');
        foreach (['../secret.png', 'review-screenshots/missing.png', 'review-screenshots/fake.jpg'] as $index => $path) {
            $this->postJson('/api/v1/admin/reviews', $this->input(['screenshotPaths' => [$path]]),
                ['Idempotency-Key' => 'bad-image-'.$index])->assertUnprocessable()->assertJsonValidationErrors(['screenshotPaths.0']);
        }
        $this->postJson('/api/v1/admin/reviews', $this->input(['showOnHome' => 'true']),
            ['Idempotency-Key' => 'bad-placement'])->assertUnprocessable()->assertJsonValidationErrors(['showOnHome']);
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_authentication_and_all_non_admin_roles_are_denied_every_endpoint(): void
    {
        $unauthorized = $this->getJson('/api/v1/admin/reviews')->assertUnauthorized()->assertJsonStructure(['error' => ['code', 'message']]);
        $this->assertStringContainsString('no-store', $unauthorized->headers->get('Cache-Control'));
        foreach (['teacher', 'manager', 'admin_limited', 'user_with_purchase', 'user_no_purchase'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            foreach (['', '/options', '/1'] as $path) {
                $denied = $this->getJson('/api/v1/admin/reviews'.$path)->assertForbidden()->assertJsonStructure(['error' => ['code', 'message']]);
                $this->assertStringContainsString('no-store', $denied->headers->get('Cache-Control'));
            }
            $this->postJson('/api/v1/admin/reviews', $this->input())->assertForbidden();
            $this->patchJson('/api/v1/admin/reviews/1', $this->input())->assertForbidden();
            $this->patchJson('/api/v1/admin/reviews/1/publication', ['action' => 'publish', 'revision' => 0])->assertForbidden();
        }
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_create_is_private_draft_with_exact_dto_and_no_internal_metadata(): void
    {
        $actor = $this->admin();
        $review = $this->createReview();
        $this->assertSame(['id', 'displayName', 'authorPhotoPath', 'showOnHome', 'screenshotPaths', 'sourceUrl', 'isAnonymous', 'text', 'reviewWrittenAt', 'publishedAt', 'publicationStatus', 'moderationStatus', 'dedupeStatus', 'originType', 'revision', 'subject', 'course', 'hasPhoto', 'canEdit', 'canPublish', 'canHide', 'events'], array_keys($review));
        $this->assertSame(['id', 'type', 'actorName', 'createdAt', 'fromPublicationStatus', 'toPublicationStatus', 'changes'], array_keys($review['events'][0]));
        $this->assertSame('draft', $review['publicationStatus']);
        $this->assertSame('needs_review', $review['moderationStatus']);
        $this->assertSame('unchecked', $review['dedupeStatus']);
        $this->assertSame('manual', $review['originType']);
        $this->assertNull($review['publishedAt']);
        $this->assertSame($review['events'][0]['id'], $review['revision']);
        $this->assertDatabaseHas('reviews', ['id' => $review['id'], 'source' => 'admin', 'user_id' => null]);
        $this->assertDatabaseHas('review_moderation_events', ['review_id' => $review['id'], 'actor_user_id' => $actor->id]);
        $response = $this->getJson('/api/v1/admin/reviews/'.$review['id'])->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('idempotency', $response->getContent());
        $this->assertStringNotContainsString('request_hash', $response->getContent());
    }

    public function test_author_photo_is_saved_published_and_removed_from_storage_when_cleared(): void
    {
        Storage::fake('public');
        $this->admin();
        $path = UploadedFile::fake()->image('author.jpg', 100, 100)->store('review-authors', 'public');
        $review = $this->createReview(['authorPhotoPath' => $path]);
        $this->assertSame($path, $review['authorPhotoPath']);
        $updated = $this->patchJson('/api/v1/admin/reviews/'.$review['id'], $this->input(['revision' => $review['revision']]), ['Idempotency-Key' => 'keep-photo'])
            ->assertOk()->json('data');
        $this->assertSame($path, $updated['authorPhotoPath']);
        $published = $this->transition($updated, 'publish', 'publish-photo')->assertOk()->json('data');
        $this->getJson('/api/v1/reviews/'.$review['id'])->assertOk()
            ->assertJsonPath('data.authorPhotoUrl', Storage::disk('public')->url($path))
            ->assertJsonMissingPath('data.authorPhotoPath')->assertJsonCount(0, 'data.media');
        $hidden = $this->transition($published, 'hide', 'hide-photo')->assertOk()->json('data');
        $this->patchJson('/api/v1/admin/reviews/'.$review['id'], $this->input(['revision' => $hidden['revision'], 'authorPhotoPath' => null]), ['Idempotency-Key' => 'remove-photo'])
            ->assertOk()->assertJsonPath('data.authorPhotoPath', null);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_author_photos_reject_unsafe_missing_or_non_image_files_and_hide_anonymous_identity(): void
    {
        Storage::fake('public');
        $this->admin();
        Storage::disk('public')->put('review-authors/not-image.jpg', 'plain text');
        foreach (['../private/portrait.jpg', 'https://other.test/photo.jpg', 'review-authors/missing.jpg', 'review-authors/file.svg', 'review-authors/not-image.jpg'] as $path) {
            $response = $this->postJson('/api/v1/admin/reviews', $this->input(['authorPhotoPath' => $path]), ['Idempotency-Key' => 'invalid-photo']);
            $this->assertSame(422, $response->status(), $path.' '.$response->getContent());
            $response->assertJsonValidationErrors('authorPhotoPath');
        }
        $path = UploadedFile::fake()->image('anonymous.jpg')->store('review-authors', 'public');
        $review = $this->createReview(['isAnonymous' => true, 'displayName' => '', 'authorPhotoPath' => $path]);
        $this->assertNull($review['authorPhotoPath']);
        $this->transition($review, 'publish', 'anonymous-photo')->assertOk();
        $this->getJson('/api/v1/reviews/'.$review['id'])->assertOk()->assertJsonPath('data.authorPhotoUrl', null);
        Review::findOrFail($review['id'])->update(['author_photo_path' => $path]);
        $this->getJson('/api/v1/reviews/'.$review['id'])->assertOk()->assertJsonPath('data.authorPhotoUrl', null);
    }

    public function test_list_exposes_subject_for_course_reviews_and_photo_presence_without_media_urls(): void
    {
        $this->admin();
        $subject = Subject::query()->create(['title' => 'Анатомия', 'slug' => 'anatomy']);
        $course = Course::query()->create(['title' => 'Остеология', 'slug' => 'osteology', 'subject_id' => $subject->id]);
        $review = $this->createReview(['scope' => 'course', 'courseId' => $course->id], 'course-with-photo');
        ReviewMedia::query()->create(['review_id' => $review['id'], 'type' => 'attachment', 'url' => '/private/review.jpg']);

        $response = $this->getJson('/api/v1/admin/reviews')->assertOk();
        $response->assertJsonPath('data.0.subject.title', 'Анатомия')
            ->assertJsonPath('data.0.hasPhoto', true)
            ->assertJsonMissingPath('data.0.media');
    }

    public function test_photo_flag_accepts_image_evidence_and_ignores_non_image_evidence(): void
    {
        $this->admin();
        $review = $this->createReview([], 'evidence-review');
        ReviewEvidence::query()->create(['review_id' => $review['id'], 'type' => 'webarchive', 'is_public' => true]);
        $this->getJson('/api/v1/admin/reviews')->assertOk()->assertJsonPath('data.0.hasPhoto', false);
        ReviewEvidence::query()->create(['review_id' => $review['id'], 'type' => 'image', 'is_public' => false]);
        $this->getJson('/api/v1/admin/reviews')->assertOk()->assertJsonPath('data.0.hasPhoto', true);
    }

    public function test_validation_rejects_protected_unknown_inconsistent_or_invalid_inputs(): void
    {
        $this->admin();
        foreach ([
            ['publicationStatus' => 'published'], ['source' => 'admin'], ['rating' => 5], ['revision' => 0],
            ['displayName' => ''], ['displayName' => str_repeat('я', 256)], ['text' => '   '], ['text' => str_repeat('я', 20001)],
            ['displayName' => ['Мария']], ['displayName' => (object) ['name' => 'Мария']],
            ['reviewWrittenAt' => now()->addDay()->toDateString()], ['reviewWrittenAt' => '2026-02-30'],
            ['scope' => 'unknown'], ['scope' => 'subject'], ['scope' => 'course'], ['subjectId' => 999999],
            ['isAnonymous' => 'false'],
        ] as $invalid) {
            $this->postJson('/api/v1/admin/reviews', $this->input($invalid), ['Idempotency-Key' => 'invalid'])
                ->assertUnprocessable()->assertJsonStructure(['error' => ['code', 'message'], 'errors']);
        }
        $this->postJson('/api/v1/admin/reviews', $this->input())->assertUnprocessable();
        $this->assertDatabaseCount('reviews', 0);
        $this->createReview(['isAnonymous' => true, 'displayName' => '']);
    }

    public function test_options_scopes_search_and_pagination(): void
    {
        $this->admin();
        $subject = Subject::query()->create(['title' => 'Медицина', 'slug' => 'medicine']);
        $course = Course::query()->create(['title' => 'Курс', 'slug' => 'course', 'subject_id' => $subject->id]);
        $this->getJson('/api/v1/admin/reviews/options')->assertOk()
            ->assertJsonPath('data.subjects.0.id', $subject->id)
            ->assertJsonPath('data.courses.0.subjectId', $subject->id);
        $this->createReview(['scope' => 'subject', 'subjectId' => $subject->id], 'subject');
        $courseReview = $this->createReview(['scope' => 'course', 'courseId' => $course->id, 'displayName' => 'Елена'], 'course');
        $this->assertSame($course->id, $courseReview['course']['id']);
        $this->postJson('/api/v1/admin/reviews', $this->input(['scope' => 'course', 'courseId' => $course->id, 'subjectId' => $subject->id]), ['Idempotency-Key' => 'invalid-scope'])->assertUnprocessable();
        $this->getJson('/api/v1/admin/reviews?status=draft&perPage=1&page=2')->assertOk()
            ->assertJsonPath('meta', ['currentPage' => 2, 'lastPage' => 2, 'perPage' => 1, 'total' => 2]);
        $this->getJson('/api/v1/admin/reviews?search='.rawurlencode('Елена'))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/reviews?status=invalid')->assertUnprocessable();
    }

    public function test_publication_hide_edit_republish_and_public_visibility(): void
    {
        $this->admin();
        $review = $this->createReview();
        $url = '/api/v1/reviews/'.$review['id'];
        $this->getJson($url)->assertNotFound();
        $published = $this->transition($review, 'publish', 'publish')->assertOk()->json('data');
        $this->assertSame('unique', $published['dedupeStatus']);
        $this->getJson($url)->assertOk();
        $this->getJson('/api/v1/reviews')->assertOk()->assertJsonCount(1, 'data');
        $publishedRevision = $published['revision'];
        $published = $this->patchJson('/api/v1/admin/reviews/'.$review['id'], $this->input(['revision' => $published['revision']]),
            ['Idempotency-Key' => 'edit-published'])->assertOk()->assertJsonPath('data.publicationStatus', 'published')->json('data');
        $this->assertSame($publishedRevision, $published['revision']);
        $hidden = $this->transition($published, 'hide', 'hide')->assertOk()->json('data');
        $this->getJson($url)->assertNotFound();
        $this->getJson('/api/v1/reviews')->assertOk()->assertJsonCount(0, 'data');
        $edited = $this->patchJson('/api/v1/admin/reviews/'.$review['id'], $this->input(['text' => 'Практические занятия помогли разобраться в сложных вопросах.', 'revision' => $hidden['revision']]), ['Idempotency-Key' => 'edit'])->assertOk()->json('data');
        $this->assertSame('hidden', $edited['publicationStatus']);
        $this->assertSame('needs_review', $edited['moderationStatus']);
        $this->transition($edited, 'publish', 'republish')->assertOk();
        $this->assertDatabaseCount('review_moderation_events', 5);
    }

    public function test_replay_precedes_revision_check_and_key_is_bound_to_actor_action_target_payload(): void
    {
        $this->admin();
        $review = $this->createReview();
        $this->assertSame($review['id'], $this->createReview()['id']);
        $this->postJson('/api/v1/admin/reviews', $this->input(['text' => 'Другой текст']), ['Idempotency-Key' => 'create-review'])->assertConflict();
        $updated = $this->patchJson('/api/v1/admin/reviews/'.$review['id'], $this->input(['revision' => $review['revision'], 'displayName' => 'Ирина']), ['Idempotency-Key' => 'edit'])->assertOk()->json('data');
        $this->patchJson('/api/v1/admin/reviews/'.$review['id'], $this->input(['revision' => $review['revision'], 'displayName' => 'Ирина']), ['Idempotency-Key' => 'edit'])->assertOk()->assertJsonPath('data.revision', $updated['revision']);
        $this->patchJson('/api/v1/admin/reviews/'.$review['id'], $this->input(['revision' => $review['revision']]), ['Idempotency-Key' => 'stale'])->assertConflict();
        $this->transition($updated, 'publish', 'edit')->assertConflict();
        $published = $this->transition($updated, 'publish', 'publish')->assertOk()->json('data');
        $this->transition($updated, 'publish', 'publish')->assertOk();
        $this->transition($updated, 'hide', 'stale-hide')->assertConflict();
        $this->assertDatabaseCount('reviews', 1);
        $this->assertDatabaseCount('review_moderation_events', 3);
        $this->assertSame('published', Review::findOrFail($review['id'])->publication_status);
        $this->admin();
        $this->transition($published, 'hide', 'publish')->assertOk();
    }

    public function test_key_cannot_be_reused_on_another_review_and_replay_does_not_revert_later_changes(): void
    {
        $this->admin();
        $first = $this->createReview();
        $second = $this->createReview(['text' => 'Знания структурированы и сразу применимы.'], 'second');
        $published = $this->transition($first, 'publish', 'publish')->assertOk()->json('data');
        $this->transition($second, 'publish', 'publish')->assertConflict();
        $hidden = $this->transition($published, 'hide', 'hide')->assertOk()->json('data');
        $this->transition($first, 'publish', 'publish')->assertOk()
            ->assertJsonPath('data.publicationStatus', 'hidden')->assertJsonPath('data.revision', $hidden['revision']);
        $this->assertDatabaseCount('review_moderation_events', 4);
        $this->getJson('/api/v1/admin/reviews/not-a-number')->assertNotFound()->assertJsonStructure(['error' => ['code', 'message']]);
        $this->getJson('/api/v1/admin/reviews/999999')->assertNotFound();
    }

    public function test_private_error_envelope_for_throttle_and_unavailable_service(): void
    {
        $this->admin();
        for ($i = 0; $i < 30; $i++) {
            $this->createReview();
        }
        $limited = $this->postJson('/api/v1/admin/reviews', $this->input(), ['Idempotency-Key' => 'create-review'])
            ->assertStatus(429)->assertJsonStructure(['error' => ['code', 'message']]);
        $this->assertStringContainsString('no-store', $limited->headers->get('Cache-Control'));
        $this->assertNotNull($limited->headers->get('Retry-After'));

        $this->admin();
        ReviewModerationEvent::creating(static function (): void {
            throw new \RuntimeException('Private synthetic storage detail');
        });
        try {
            $unavailable = $this->postJson('/api/v1/admin/reviews', $this->input(), ['Idempotency-Key' => 'failure'])
                ->assertStatus(503)->assertJsonPath('error.code', 'ADMIN_REVIEW_UNAVAILABLE');
            $this->assertStringContainsString('no-store', $unavailable->headers->get('Cache-Control'));
            $this->assertStringNotContainsString('Private synthetic', $unavailable->getContent());
        } finally {
            ReviewModerationEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_imported_review_can_be_edited_and_hidden_and_legacy_revision_starts_at_zero(): void
    {
        $this->admin();
        $review = Review::query()->create(['text' => 'Архивный отзыв', 'display_name' => 'Автор', 'source' => 'legacy_ocr', 'status' => 'published', 'published_at' => now()]);
        $detail = $this->getJson('/api/v1/admin/reviews/'.$review->id)->assertOk()->assertJsonPath('data.revision', 0)->assertJsonPath('data.canEdit', true)->assertJsonPath('data.canPublish', false)->json('data');
        $this->transition($detail, 'publish', 'publish')->assertConflict();
        $detail = $this->patchJson('/api/v1/admin/reviews/'.$review->id, $this->input(['revision' => 0]), ['Idempotency-Key' => 'edit'])->assertOk()->json('data');
        $this->transition($detail, 'hide', 'hide')->assertOk();
        $this->assertSame($this->input()['text'], $review->fresh()->text);
        $this->assertSame('legacy_ocr', $review->fresh()->source);
    }

    public function test_duplicate_text_candidates_in_either_direction_and_confirmed_duplicate_block_publish(): void
    {
        $this->admin();
        $first = $this->createReview();
        $second = $this->createReview([], 'second');
        $this->transition($second, 'publish', 'duplicate')->assertConflict();
        Review::findOrFail($first['id'])->update(['text' => 'Совершенно иной текст.']);
        ReviewDuplicateCandidate::query()->create(['review_id' => $first['id'], 'candidate_review_id' => $second['id'], 'match_type' => 'manual', 'status' => 'pending']);
        $this->transition($second, 'publish', 'candidate')->assertConflict();
        ReviewDuplicateCandidate::query()->update(['status' => 'rejected']);
        Review::findOrFail($second['id'])->update(['dedupe_status' => 'duplicate']);
        $this->transition($second, 'publish', 'confirmed')->assertConflict();
        $this->assertDatabaseCount('review_moderation_events', 2);
        $this->assertDatabaseCount('reviews', 2);
    }

    public function test_publish_revalidates_persisted_content_and_transaction_rolls_back_when_audit_fails(): void
    {
        $this->admin();
        $review = $this->createReview();
        Review::findOrFail($review['id'])->update(['text' => '']);
        $this->transition($review, 'publish', 'invalid')->assertUnprocessable();
        ReviewModerationEvent::creating(static function (): void {
            throw new \RuntimeException('Synthetic audit write failure');
        });
        try {
            $this->withoutExceptionHandling()->postJson('/api/v1/admin/reviews', $this->input(), ['Idempotency-Key' => 'rollback']);
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit write failure', $exception->getMessage());
        } finally {
            ReviewModerationEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('reviews', 1);
        $this->assertDatabaseCount('review_moderation_events', 1);
    }
}
