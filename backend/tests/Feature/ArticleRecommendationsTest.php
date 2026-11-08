<?php

namespace Tests\Feature;

use App\Filament\Pages\Articles;
use App\Models\ContentAuthor;
use App\Models\ContentCategory;
use App\Models\ContentEntry;
use App\Models\ContentPlacement;
use App\Models\Subject;
use App\Models\User;
use App\Services\Articles\ArticleEditor;
use App\Services\Articles\ArticleRecommendations;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ArticleRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    private Subject $subject;

    private Subject $otherSubject;

    private ContentAuthor $author;

    private ContentAuthor $otherAuthor;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('public');
        $this->subject = Subject::create(['slug' => 'anatomy', 'title' => 'Anatomy', 'is_active' => true]);
        $this->otherSubject = Subject::create(['slug' => 'biology', 'title' => 'Biology', 'is_active' => true]);
        $this->author = ContentAuthor::create(['slug' => 'first', 'name' => 'First']);
        $this->otherAuthor = ContentAuthor::create(['slug' => 'second', 'name' => 'Second']);
    }

    private function entry(string $type = 'article', array $overrides = [], int $order = 10, bool $enabled = true): ContentEntry
    {
        $entry = ContentEntry::create(array_replace([
            'content_type' => $type, 'title' => 'Entry '.++$this->sequence, 'slug' => 'entry-'.$this->sequence,
            'body' => '<p>Public body</p>', 'body_format' => 'html', 'status' => 'published', 'published_at' => now()->subDay(),
            'subject_id' => $this->subject->id, 'content_author_id' => $this->author->id, 'revision' => 1,
        ], $overrides));
        ContentPlacement::create(['content_entry_id' => $entry->id, 'placement' => $type === 'article' ? 'articles.index' : 'faq.index', 'sort_order' => $order, 'is_enabled' => $enabled]);

        return $entry;
    }

    private function input(ContentEntry $entry, array $overrides = []): array
    {
        return array_replace($entry->only(['title', 'slug', 'body', 'excerpt', 'subject_id', 'content_author_id', 'status', 'published_at']), $overrides);
    }

    private function recommendations(ContentEntry $entry): array
    {
        return app(ArticleRecommendations::class)->resolve($entry->fresh());
    }

    private function editor(): ArticleEditor
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');

        return app(ArticleEditor::class);
    }

    public function test_manual_order_then_same_author_date_id_and_faq_placement_fill_without_payload_write(): void
    {
        $source = $this->entry();
        $firstPin = $this->entry(overrides: ['content_author_id' => $this->otherAuthor->id, 'published_at' => now()->subDays(20)]);
        $secondPin = $this->entry(overrides: ['content_author_id' => $this->otherAuthor->id, 'published_at' => now()->subDays(10)]);
        $this->entry(overrides: ['content_author_id' => $this->otherAuthor->id, 'published_at' => now()->subMinute()]);
        $this->entry(overrides: ['published_at' => now()->subDays(2)]);
        $sameAuthor = $this->entry(overrides: ['published_at' => now()->subDays(2)]);
        $firstFaq = $this->entry('faq', order: 1);
        $this->entry('faq', order: 2);
        $pinnedFaq = $this->entry('faq', order: 30);
        $source->update(['payload' => ['legacy' => ['id' => 42], 'recommendations' => ['article_ids' => [$secondPin->id, $firstPin->id], 'faq_ids' => [$pinnedFaq->id]]]]);
        $before = $source->fresh()->getAttributes();
        $result = $this->recommendations($source);
        $this->assertSame([$secondPin->id, $firstPin->id, $sameAuthor->id], $result['articles']->modelKeys());
        $this->assertSame([$pinnedFaq->id, $firstFaq->id], $result['faqs']->modelKeys());
        $this->assertSame($before, $source->fresh()->getAttributes());
        $detail = $this->getJson('/api/v1/articles/'.$source->slug)->assertOk();
        $this->assertSame(['id', 'subject', 'slug', 'question', 'answer', 'category', 'sortOrder', 'publishedAt'], array_keys($detail->json('data.relatedFaqs.0')));
        $detail->assertJsonMissingPath('data.payload')->assertJsonMissingPath('data.recommendations')->assertJsonMissingPath('data.related.0.body')->assertJsonMissingPath('data.related.0.relatedFaqs');
        $this->getJson('/api/v1/articles')->assertOk()->assertJsonMissingPath('data.0.relatedFaqs');
        $saved = $this->editor()->save($source->id, $this->input($source, ['recommendation_article_ids' => [], 'recommendation_faq_ids' => []]), 1);
        $this->assertSame(['article_ids' => [], 'faq_ids' => []], $saved->payload['recommendations']);
        $automatic = $this->recommendations($saved);
        $this->assertSame($sameAuthor->id, $automatic['articles']->first()->id);
        $this->assertCount(3, $automatic['articles']);
        $this->assertSame($firstFaq->id, $automatic['faqs']->first()->id);
    }

    public function test_each_read_filters_publication_placement_category_type_subject_self_and_deleted_pins(): void
    {
        $source = $this->entry();
        $hidden = $this->entry(overrides: ['status' => 'hidden']);
        $future = $this->entry(overrides: ['published_at' => now()->addDay()]);
        $this->entry(overrides: ['status' => 'draft']);
        $this->entry(overrides: ['status' => 'archived']);
        $disabled = $this->entry(enabled: false);
        $unplaced = $this->entry();
        $unplaced->placements()->delete();
        $this->entry(overrides: ['subject_id' => $this->otherSubject->id]);
        $deleted = $this->entry();
        $deletedId = $deleted->id;
        $deleted->delete();
        $inactive = ContentCategory::create(['content_type' => 'faq', 'slug' => 'inactive', 'title' => 'Inactive', 'is_active' => false]);
        $hiddenFaq = $this->entry('faq', ['content_category_id' => $inactive->id], order: 0);
        $wrongCategory = ContentCategory::create(['content_type' => 'article', 'slug' => 'wrong-category', 'title' => 'Wrong category', 'is_active' => true]);
        $this->entry('faq', ['content_category_id' => $wrongCategory->id], order: 0);
        $this->entry('faq', ['published_at' => now()->addDay()], order: 0);
        $this->entry('faq', ['status' => 'hidden'], order: 0);
        $this->entry('faq', order: 0, enabled: false);
        $this->entry('faq', ['subject_id' => $this->otherSubject->id], order: 0);
        $visible = $this->entry();
        $faq = $this->entry('faq', order: 1);
        foreach ([[$hidden->id, $future->id, $disabled->id], [$deletedId, $source->id, $faq->id]] as $pins) {
            $source->update(['payload' => ['recommendations' => ['article_ids' => $pins, 'faq_ids' => [$hiddenFaq->id, $visible->id]]]]);
            $before = $source->payload;
            $result = $this->recommendations($source);
            $this->assertSame([$visible->id], $result['articles']->modelKeys());
            $this->assertSame([$faq->id], $result['faqs']->modelKeys());
            $this->assertSame($before, $source->fresh()->payload);
        }
    }

    public function test_null_subject_requires_same_nonnull_author_and_inactive_subject_is_not_null(): void
    {
        $source = $this->entry(overrides: ['subject_id' => null]);
        $same = $this->entry(overrides: ['subject_id' => null]);
        $this->entry(overrides: ['subject_id' => null, 'content_author_id' => $this->otherAuthor->id]);
        $this->entry(overrides: ['subject_id' => null, 'content_author_id' => null]);
        $this->entry();
        $this->entry('faq', ['subject_id' => null]);
        $result = $this->recommendations($source);
        $this->assertSame([$same->id], $result['articles']->modelKeys());
        $this->assertCount(0, $result['faqs']);
        $source->update(['content_author_id' => null]);
        $this->assertCount(0, $this->recommendations($source)['articles']);
        $source->update(['subject_id' => $this->subject->id, 'content_author_id' => $this->author->id]);
        $this->subject->update(['is_active' => false]);
        $result = $this->recommendations($source);
        $this->assertCount(0, $result['articles']);
        $this->assertCount(0, $result['faqs']);
    }

    public function test_save_rejects_structure_caps_duplicates_self_and_new_ineligible_ids_atomically(): void
    {
        $editor = $this->editor();
        $source = $this->entry();
        $valid = $this->entry();
        $faq = $this->entry('faq');
        $cross = $this->entry(overrides: ['subject_id' => $this->otherSubject->id]);
        $hidden = $this->entry(overrides: ['status' => 'hidden']);
        $hiddenFaq = $this->entry('faq', ['status' => 'hidden']);
        $futureFaq = $this->entry('faq', ['published_at' => now()->addDay()]);
        $disabledFaq = $this->entry('faq', enabled: false);
        $before = $source->fresh()->getAttributes();
        foreach ([null, 'bad', ['key' => $valid->id], [0], [-1], [true], [1.5], [$source->id], [$valid->id, (string) $valid->id], [$valid->id, 999, 998, 997], [$faq->id], [$cross->id], [$hidden->id], [999999]] as $ids) {
            try {
                $editor->save($source->id, $this->input($source, ['recommendation_article_ids' => $ids]), 1);
                $this->fail('Invalid article pins accepted: '.json_encode($ids));
            } catch (ValidationException) {
                $this->assertSame($before, $source->fresh()->getAttributes());
                $this->assertDatabaseCount('article_audit_events', 0);
            }
        }
        foreach ([[$valid->id], [$faq->id, $faq->id], [$faq->id, 999, 998], [$hiddenFaq->id], [$futureFaq->id], [$disabledFaq->id]] as $ids) {
            try {
                $editor->save($source->id, $this->input($source, ['recommendation_faq_ids' => $ids]), 1);
                $this->fail('Invalid FAQ pins accepted');
            } catch (ValidationException) {
                $this->assertSame($before, $source->fresh()->getAttributes());
            }
        }
        try {
            $editor->save($source->id, $this->input($source, ['payload' => ['legacy' => 'attack']]), 1);
            $this->fail('Arbitrary payload accepted');
        } catch (ValidationException) {
            $this->assertSame($before, $source->fresh()->getAttributes());
        }
    }

    public function test_stale_pins_do_not_block_body_save_and_payload_neighbors_revision_and_actor_are_preserved(): void
    {
        $editor = $this->editor();
        $attachments = [['url' => '/storage/article-media/'.str_repeat('a', 64).'.pdf', 'name' => 'Preserved attachment']];
        $source = $this->entry(overrides: ['payload' => ['attachments' => $attachments, 'legacy' => ['id' => 45], 'custom' => ['preserved' => true]]]);
        $article = $this->entry();
        $faq = $this->entry('faq');
        $saved = $editor->save($source->id, $this->input($source, ['recommendation_article_ids' => [(string) $article->id], 'recommendation_faq_ids' => [(string) $faq->id]]), 1);
        $this->assertSame(['article_ids' => [$article->id], 'faq_ids' => [$faq->id]], $saved->payload['recommendations']);
        $this->assertSame(['id' => 45], $saved->payload['legacy']);
        $this->assertSame($attachments, $saved->payload['attachments']);
        $this->assertSame(['preserved' => true], $saved->payload['custom']);
        $article->update(['status' => 'hidden']);
        $faq->delete();
        $payload = $saved->payload;
        $saved = $editor->save($source->id, $this->input($saved, ['body' => '<p>Updated</p>']), 2);
        $this->assertSame($payload, $saved->payload);
        $saved = $editor->save($source->id, $this->input($saved, ['body' => '<p>Updated again</p>', 'recommendation_article_ids' => [$article->id], 'recommendation_faq_ids' => [$faq->id]]), 3);
        $this->assertSame($payload, $saved->payload);
        $saved = $editor->saveParameters($source->id, ['subject_id' => $this->otherSubject->id], 4);
        $this->assertSame($payload, $saved->payload);
        $this->assertCount(0, $this->recommendations($saved)['articles']);
        try {
            $editor->save($source->id, $this->input($saved), 4);
            $this->fail('Stale revision accepted');
        } catch (ValidationException) {
            $this->assertSame(5, $source->fresh()->revision);
        }
        $this->actingAs(User::factory()->create(['role' => 'student']), 'web');
        try {
            $editor->save($source->id, $this->input($saved), 5);
            $this->fail('Unauthorized actor accepted');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('article_audit_events', 4);
    }

    public function test_new_pins_use_final_validated_subject_and_author_and_null_subject_restrictions(): void
    {
        $editor = $this->editor();
        $source = $this->entry();
        $other = $this->entry(overrides: ['subject_id' => $this->otherSubject->id]);
        $saved = $editor->save($source->id, $this->input($source, ['subject_id' => $this->otherSubject->id, 'recommendation_article_ids' => [$other->id]]), 1);
        $this->assertSame([$other->id], $this->recommendations($saved)['articles']->modelKeys());
        $noSubject = $this->entry(overrides: ['subject_id' => null, 'content_author_id' => $this->otherAuthor->id]);
        $saved = $editor->save($source->id, $this->input($saved, ['subject_id' => null, 'content_author_id' => $this->otherAuthor->id, 'recommendation_article_ids' => [$noSubject->id]]), 2);
        $this->assertSame([$noSubject->id], $this->recommendations($saved)['articles']->modelKeys());
        $saved = $editor->saveParameters($source->id, ['content_author_id' => $this->author->id], 3);
        $this->assertSame([$noSubject->id], $saved->payload['recommendations']['article_ids']);
        $this->assertCount(0, $this->recommendations($saved)['articles']);
    }

    public function test_query_count_is_bounded_when_corpus_grows_and_selected_stale_labels_are_visible(): void
    {
        $source = $this->entry();
        $this->entry();
        $this->entry('faq');
        DB::enableQueryLog();
        $this->getJson('/api/v1/articles/'.$source->slug)->assertOk();
        $smallCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 60; $i++) {
            $this->entry();
            $this->entry('faq');
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/articles/'.$source->slug)->assertOk();
        $largeCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($smallCount, $largeCount);
        $this->assertLessThanOrEqual(14, $largeCount);
        $this->editor();
        $hidden = $this->entry(overrides: ['status' => 'hidden']);
        $service = app(ArticleRecommendations::class);
        $labels = $service->labels($source->fresh(), 'article', [$hidden->id, 999999]);
        $this->assertSame('[Недоступно] '.$hidden->title, $labels[$hidden->id]);
        $this->assertSame('[Недоступно] #999999', $labels[999999]);
        $this->assertArrayNotHasKey($hidden->id, $service->options($source->fresh(), 'article', ''));
    }

    public function test_native_selects_are_explicit_save_and_stale_selections_do_not_block_form_body_save(): void
    {
        $this->editor();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $source = $this->entry();
        $hidden = $this->entry(overrides: ['status' => 'hidden']);
        $source->update(['payload' => ['legacy' => ['id' => 10], 'recommendations' => ['article_ids' => [$hidden->id], 'faq_ids' => []]]]);
        $page = Livewire::test(Articles::class, ['articleId' => (string) $source->id]);
        $articleSelect = $page->instance()->form->getComponentByStatePath('recommendation_article_ids');
        $faqSelect = $page->instance()->form->getComponentByStatePath('recommendation_faq_ids');
        $this->assertTrue($articleSelect->isMultiple());
        $this->assertTrue($articleSelect->isReorderable());
        $this->assertSame(3, $articleSelect->getMaxItems());
        $this->assertSame(2, $faqSelect->getMaxItems());
        $this->assertSame('[Недоступно] '.$hidden->title, $articleSelect->getOptionLabels()[$hidden->id]);
        $page->set('data.recommendation_article_ids', []);
        $this->assertSame([$hidden->id], $source->fresh()->payload['recommendations']['article_ids']);
        $page->set('data.recommendation_article_ids', [$hidden->id])->set('data.body', '<p>Saved through form</p>')->call('save')->assertHasNoFormErrors();
        $this->assertSame([$hidden->id], $source->fresh()->payload['recommendations']['article_ids']);
        $this->assertSame(['id' => 10], $source->fresh()->payload['legacy']);
        $this->assertSame(2, $source->fresh()->revision);
        $this->assertNotContains('recommendation_article_ids', ArticleEditor::PARAMETERS);
        $this->assertNotContains('recommendation_faq_ids', ArticleEditor::PARAMETERS);
    }
}
