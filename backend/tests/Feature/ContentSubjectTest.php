<?php

namespace Tests\Feature;

use App\Filament\Pages\Articles;
use App\Models\ContentCategory;
use App\Models\ContentEntry;
use App\Models\Course;
use App\Models\Review;
use App\Models\Subject;
use App\Models\User;
use App\Services\Articles\ArticleEditor;
use App\Services\Faq\AdminFaqService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ContentSubjectTest extends TestCase
{
    use RefreshDatabase;

    private function subject(string $slug, bool $active = true): Subject
    {
        return Subject::create(['slug' => $slug, 'title' => ucfirst($slug), 'is_active' => $active]);
    }

    private function entry(string $slug, ?Subject $subject, string $type = 'article', array $extra = []): ContentEntry
    {
        $entry = ContentEntry::create([...['slug' => $slug, 'title' => $slug, 'body' => 'Preserved body', 'content_type' => $type, 'status' => 'published', 'published_at' => now()->subDay(), 'subject_id' => $subject?->id], ...$extra]);
        $entry->placements()->create(['placement' => $type === 'article' ? 'articles.index' : 'faq.index', 'is_enabled' => true]);

        return $entry;
    }

    public function test_subject_counts_namespaces_visibility_and_related(): void
    {
        $subject = $this->subject('science');
        $empty = $this->subject('empty');
        $inactive = $this->subject('inactive', false);
        $category = ContentCategory::create(['content_type' => 'article', 'slug' => 'science', 'title' => 'Historical', 'is_active' => true]);
        $hiddenCategory = ContentCategory::create(['content_type' => 'article', 'slug' => 'hidden-cat', 'title' => 'Hidden', 'is_active' => false]);
        $a = $this->entry('a', $subject, extra: ['content_category_id' => $hiddenCategory->id]);
        $b = $this->entry('b', $subject);
        $this->entry('archival', null, extra: ['content_category_id' => $category->id]);
        $this->entry('general', null);
        $this->entry('inactive-subject', $inactive);
        $this->entry('future', $subject, extra: ['published_at' => now()->addDay()]);
        $this->entry('draft', $subject, extra: ['status' => 'draft']);
        $disabled = $this->entry('disabled', $subject);
        $disabled->placements()->update(['is_enabled' => false]);
        $this->getJson('/api/v1/articles?subject=science&limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/article-subjects')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.count', 2)->assertJsonPath('data.1.count', 0);
        $this->getJson('/api/v1/articles?category=science')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', 'archival');
        $this->getJson('/api/v1/articles?subject=science&category=science')->assertUnprocessable();
        foreach (['subject=unknown', 'subject=inactive', 'category=unknown', 'category=hidden-cat'] as $filter) {
            $this->getJson('/api/v1/articles?'.$filter)->assertNotFound();
        }
        $this->getJson('/api/v1/articles?subject=empty')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/articles?subject=&category=&ignored=value')->assertOk()->assertJsonPath('meta.total', 5);
        $this->getJson('/api/v1/articles/a')->assertOk()->assertJsonPath('data.related.0.id', $b->id)->assertJsonPath('data.subject.id', $subject->id);
        $this->getJson('/api/v1/articles/inactive-subject')->assertOk()->assertJsonPath('data.subject', null)->assertJsonCount(0, 'data.related');
        foreach (['subject[]=science', 'subject=bad%20slug', 'page=0', 'limit=51'] as $filter) {
            $this->getJson('/api/v1/articles?'.$filter)->assertUnprocessable();
        }
        $this->assertSame(5, $subject->articles()->count());
    }

    public function test_article_and_faq_save_omit_keep_clear_validate_and_preserve_inactive(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        $this->actingAs($actor, 'web');
        $active = $this->subject('active');
        $inactive = $this->subject('inactive', false);
        $editor = app(ArticleEditor::class);
        $articleInput = ['title' => 'Article', 'slug' => 'article', 'body' => '<p>Body</p>', 'status' => 'draft'];
        $faqInput = ['title' => 'Question', 'body' => 'Answer', 'sort_order' => 0, 'show_on_home' => false];
        foreach (['article', 'faq'] as $type) {
            $save = function (?ContentEntry $entry, array $input) use ($type, $actor, $editor): ContentEntry {
                return $type === 'article' ? $editor->save($entry?->id, $input, $entry?->revision ?? 0)
                    : app(AdminFaqService::class)->execute($actor, $entry ? 'update' : 'create', $input, bin2hex(random_bytes(12)), $entry?->id, $entry?->revision);
            };
            $input = $type === 'article' ? $articleInput : $faqInput;
            $entry = $save(null, [...$input, 'subject_id' => (string) $active->id]);
            $this->assertSame($active->id, $entry->subject_id);
            $entry = $save($entry, $input);
            $this->assertSame($active->id, $entry->subject_id);
            foreach ([$inactive->id, 99999, [], '1.5'] as $invalid) {
                try {
                    $save($entry, [...$input, 'subject_id' => $invalid]);
                    $this->fail('Invalid subject accepted');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey('subject_id', $exception->errors());
                    $this->assertSame($entry->revision, $entry->fresh()->revision);
                }
            }
            $active->update(['is_active' => false]);
            $entry = $save($entry, [...$input, 'subject_id' => (string) $active->id]);
            $this->assertSame($active->id, $entry->subject_id);
            $entry = $save($entry, [...$input, 'subject_id' => '']);
            $this->assertNull($entry->subject_id);
            $active->update(['is_active' => true]);
        }
    }

    public function test_single_canonical_subject_connects_articles_faq_direct_and_course_reviews(): void
    {
        $subject = $this->subject('anatomy');
        $other = $this->subject('histology');
        $article = $this->entry('anatomy-article', $subject);
        $faq = $this->entry('anatomy-faq', $subject, 'faq');
        foreach (['article', 'faq'] as $type) {
            $this->entry('other-'.$type, $other, $type);
            $this->entry('general-'.$type, null, $type);
            $this->entry('draft-'.$type, $subject, $type, ['status' => 'draft']);
        }
        $course = Course::create(['subject_id' => $subject->id, 'slug' => 'anatomy-course', 'title' => 'Course', 'status' => 'published']);
        $direct = Review::create(['subject_id' => $subject->id, 'text' => 'Direct', 'display_name' => 'Anna', 'status' => 'published', 'published_at' => now()->subDay()]);
        $viaCourse = Review::create(['course_id' => $course->id, 'text' => 'Course', 'display_name' => 'Boris', 'status' => 'published', 'published_at' => now()->subDay()]);
        Review::create(['subject_id' => $other->id, 'text' => 'Other', 'display_name' => 'Other', 'status' => 'published', 'published_at' => now()->subDay()]);
        Review::create(['subject_id' => $subject->id, 'text' => 'Draft', 'display_name' => 'Draft', 'status' => 'draft']);
        foreach (['Anatomy', 'Canonical updated title'] as $title) {
            $subject->update(['title' => $title]);
            $expected = ['id' => $subject->id, 'slug' => $subject->slug, 'title' => $title];
            $this->getJson('/api/v1/articles?subject=anatomy')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $article->id)->assertJsonPath('data.0.subject', $expected);
            $this->getJson('/api/v1/faqs?subject=anatomy')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $faq->id)->assertJsonPath('data.0.subject', $expected);
            $response = $this->getJson('/api/v1/reviews?subject=anatomy')->assertOk()->assertJsonCount(2, 'data');
            $this->assertEqualsCanonicalizing([$direct->id, $viaCourse->id], $response->json('data.*.id'));
            $this->assertSame([$expected, $expected], $response->json('data.*.subject'));
        }
        $this->assertTrue($subject->faqs()->published()->sole()->is($faq));
        $this->assertTrue($subject->courses()->sole()->is($course));
        $this->assertTrue($subject->reviews()->whereKey($direct->id)->exists());
        $this->getJson('/api/v1/faqs?subject=unknown')->assertNotFound();
        $this->getJson('/api/v1/faqs?subject=histology&category=unrelated')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filament_article_subject_and_no_category_action(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
        $subject = $this->subject('science');
        Livewire::test(Articles::class, ['articleId' => 'create'])->set('data.title', 'Article')->set('data.slug', 'article')
            ->set('data.body', '<p>Body</p>')->set('data.subject_id', (string) $subject->id)->call('save')->assertHasNoErrors();
        $entry = ContentEntry::query()->article()->sole();
        $this->assertSame($subject->id, $entry->subject_id);
        Livewire::test(Articles::class, ['articleId' => (string) $entry->id])->assertSet('data.subject_id', $subject->id)
            ->set('data.subject_id', null)->call('save')->assertHasNoErrors();
        $this->assertNull($entry->fresh()->subject_id);
        try {
            app(ArticleEditor::class)->saveTaxonomy('category', null, ['title' => 'Forbidden', 'slug' => 'forbidden']);
            $this->fail('Historical category editing must be unavailable');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_faq_subject_intersects_category_preserves_total_and_hides_inactive_metadata(): void
    {
        $subject = $this->subject('science');
        $empty = $this->subject('empty');
        $inactive = $this->subject('inactive', false);
        $category = ContentCategory::create(['content_type' => 'faq', 'slug' => 'payment', 'title' => 'Оплата', 'is_active' => true]);
        $this->entry('first-faq', $subject, 'faq', ['content_category_id' => $category->id]);
        $this->entry('second-faq', $subject, 'faq', ['content_category_id' => $category->id]);
        $this->entry('uncategorized-faq', $subject, 'faq');
        $this->entry('inactive-faq', $inactive, 'faq');
        $this->getJson('/api/v1/faqs?subject=science&category=payment&limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/faqs?subject=empty')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/faqs?subject=inactive')->assertNotFound();
        $data = $this->getJson('/api/v1/faqs?subject=')->assertOk()->assertJsonPath('meta.total', 4)->json('data');
        $this->assertNull(collect($data)->firstWhere('slug', 'inactive-faq')['subject']);
    }
}
