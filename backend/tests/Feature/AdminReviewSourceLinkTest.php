<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\ReviewSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminReviewSourceLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin_full']));
        Http::preventStrayRequests();
    }

    private function input(array $extra = []): array
    {
        return array_replace(['displayName' => 'Мария', 'isAnonymous' => false,
            'text' => 'Подробные объяснения помогли мне разобраться в сложной теме и сдать экзамен.',
            'reviewWrittenAt' => '2026-01-15', 'scope' => 'all', 'subjectId' => null, 'courseId' => null], $extra);
    }

    private function create(array $extra = []): array
    {
        return $this->postJson('/api/v1/admin/reviews', $this->input($extra), ['Idempotency-Key' => 'create'])
            ->assertCreated()->json('data');
    }

    private function update(array $review, array $extra, string $key): array
    {
        return $this->patchJson('/api/v1/admin/reviews/'.$review['id'], $this->input($extra + ['revision' => $review['revision']]),
            ['Idempotency-Key' => $key])->assertOk()->json('data');
    }

    private function publish(array $review): array
    {
        return $this->patchJson('/api/v1/admin/reviews/'.$review['id'].'/publication', [
            'action' => 'publish', 'revision' => $review['revision'],
        ], ['Idempotency-Key' => 'publish'])->assertOk()->json('data');
    }

    public function test_source_is_optional_and_omitted_update_preserves_url_with_revision_and_replay(): void
    {
        $review = $this->create(['sourceUrl' => 'https://example.invalid/demo']);
        $this->assertSame('https://example.invalid/demo', $review['sourceUrl']);
        $published = $this->publish($review);
        $this->getJson('/api/v1/reviews/'.$review['id'])->assertOk()
            ->assertJsonPath('data.sourceLinks.0.type', 'yandex_maps_webarchive')
            ->assertJsonPath('data.sourceLinks.0.url', $review['sourceUrl']);
        $updated = $this->update($published, ['text' => 'Обновлённый текст без изменения ссылки.'], 'edit');
        $this->assertSame($review['sourceUrl'], $updated['sourceUrl']);
        $this->getJson('/api/v1/admin/reviews/'.$review['id'])->assertJsonPath('data.sourceUrl', $review['sourceUrl']);
        $before = ReviewSource::count();
        $this->patchJson('/api/v1/admin/reviews/'.$review['id'], $this->input([
            'text' => 'Обновлённый текст без изменения ссылки.', 'revision' => $published['revision'],
        ]), ['Idempotency-Key' => 'edit'])->assertOk()->assertJsonPath('data.revision', $updated['revision']);
        $this->assertSame($before, ReviewSource::count());
        $this->patchJson('/api/v1/admin/reviews/'.$review['id'], $this->input([
            'sourceUrl' => 'https://example.invalid/demo', 'revision' => $published['revision'],
        ]), ['Idempotency-Key' => 'stale'])->assertConflict();
        $this->assertSame($review['sourceUrl'], $updated['sourceUrl']);
    }

    public function test_imported_sources_and_evidence_survive_override_and_explicit_clear(): void
    {
        $review = $this->create();
        $model = Review::findOrFail($review['id']);
        $model->update(['source' => 'vk', 'origin_type' => 'legacy_import']);
        $source = $model->sources()->create(['source_type' => ReviewSource::TYPE_VK_WEBARCHIVE,
            'source_ref' => 'import:123', 'source_url' => 'https://example.invalid/demo', 'is_public' => true,
            'raw_payload' => ['original' => 'сохранить'], 'raw_text' => 'Исходный текст']);
        $evidence = $source->evidence()->create(['review_id' => $model->id, 'type' => 'screenshot',
            'storage_url' => 'https://images.example.org/review.png', 'metadata' => ['original' => true]]);
        $sourceBefore = $source->fresh()->getAttributes();
        $evidenceBefore = $evidence->fresh()->getAttributes();
        $published = $this->publish($review);
        $this->getJson('/api/v1/admin/reviews/'.$model->id)->assertJsonPath('data.sourceUrl', $source->source_url);
        $same = $this->update($published, ['sourceUrl' => $source->source_url], 'same');
        $this->assertDatabaseCount('review_sources', 1);
        $changed = $this->update($same, ['sourceUrl' => 'https://example.org/reviews/42'], 'replace');
        $this->getJson('/api/v1/reviews/'.$model->id)->assertJsonCount(1, 'data.sourceLinks')
            ->assertJsonPath('data.sourceLinks.0', ['type' => 'manual', 'url' => 'https://example.org/reviews/42', 'label' => 'Источник']);
        $this->assertSame(['before' => $source->source_url, 'after' => 'https://example.org/reviews/42'],
            $model->moderationEvents()->latest('id')->first()->metadata['changes']['sourceUrl']);
        $cleared = $this->update($changed, ['sourceUrl' => null], 'clear');
        $this->assertNull($cleared['sourceUrl']);
        $this->getJson('/api/v1/reviews/'.$model->id)->assertJsonCount(0, 'data.sourceLinks');
        $this->assertSame($sourceBefore, $source->fresh()->getAttributes());
        $this->assertSame($evidenceBefore, $evidence->fresh()->getAttributes());
        $this->assertDatabaseCount('review_sources', 2);
    }

    public function test_blank_source_is_allowed_and_does_not_create_a_source(): void
    {
        $review = $this->create(['sourceUrl' => '']);
        $this->assertNull($review['sourceUrl']);
        $this->assertDatabaseCount('review_sources', 0);
        $this->publish($review);
        $this->getJson('/api/v1/reviews/'.$review['id'])->assertJsonCount(0, 'data.sourceLinks');
    }

    public function test_invalid_and_non_http_links_are_rejected_without_writes(): void
    {
        foreach (['javascript:alert(1)', 'data:text/html,hi', '//vk.com/review', '/relative', 'ftp://example.org/a',
            'https://user:password@example.org/a', "https://example.org/\nfoo", 'not a URL', 'https://example.org/'.str_repeat('x', 255)] as $index => $url) {
            $this->postJson('/api/v1/admin/reviews', $this->input(['sourceUrl' => $url]),
                ['Idempotency-Key' => 'invalid-'.$index])->assertUnprocessable()->assertJsonValidationErrors('sourceUrl');
        }
        $this->assertDatabaseCount('reviews', 0);
        $this->assertDatabaseCount('review_sources', 0);
    }

    public function test_vk_host_is_recognized_but_lookalike_is_generic_and_old_links_remain_safe(): void
    {
        $review = $this->publish($this->create(['sourceUrl' => 'https://example.invalid/demo']));
        $this->getJson('/api/v1/reviews/'.$review['id'])->assertJsonPath('data.sourceLinks.0.type', 'vk_webarchive');
        $review = $this->update($review, ['sourceUrl' => 'https://vk.com.example.org/review'], 'lookalike');
        $this->getJson('/api/v1/reviews/'.$review['id'])->assertJsonPath('data.sourceLinks.0.type', 'manual');
        $review = $this->update($review, ['sourceUrl' => 'http://example.org/review'], 'http');
        $this->getJson('/api/v1/reviews/'.$review['id'])->assertJsonPath('data.sourceLinks.0.url', $review['sourceUrl']);
    }
}
