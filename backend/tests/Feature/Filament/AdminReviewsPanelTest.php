<?php

namespace Tests\Feature\Filament;

use App\Exceptions\AdminReviewException;
use App\Filament\Pages\Reviews\CreateReview;
use App\Filament\Pages\Reviews\EditReview;
use App\Filament\Pages\Reviews\Reviews;
use App\Models\Course;
use App\Models\Review;
use App\Models\ReviewEvidence;
use App\Models\ReviewImageAsset;
use App\Models\ReviewMedia;
use App\Models\Subject;
use App\Models\User;
use App\Services\Reviews\AdminReviewService;
use App\Services\Reviews\ReviewImageAssets;
use App\Support\Admin\ReviewsPanel;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Finder\Finder;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\TestCase;

class AdminReviewsPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => 'admin_full']), 'web');
    }

    private function input(array $overrides = []): array
    {
        return array_replace(['displayName' => 'Мария', 'isAnonymous' => false,
            'text' => 'Полезный курс с понятными объяснениями и практическими примерами.',
            'reviewWrittenAt' => '2026-01-15', 'scope' => 'all', 'subjectId' => null, 'courseId' => null], $overrides);
    }

    private function createReview(array $overrides = [], string $key = 'create'): array
    {
        return app(ReviewsPanel::class)->execute('create', null, $this->input($overrides), $key);
    }

    public function test_panel_waits_for_queued_images_and_rejects_unattached_images_of_another_admin(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Queue::fake();
        $page = Livewire::test(CreateReview::class)
            ->fillForm($this->input(['authorPhotoPath' => UploadedFile::fake()->image('photo.jpg', 200, 300)]));
        $page->assertSet('savingPreparation.pending', true);
        $asset = ReviewImageAsset::sole();
        $page->assertSee('Изображение готовится')->call('save')->assertHasFormErrors(['authorPhotoPath']);
        $this->assertDatabaseCount('reviews', 0);
        app(ReviewImageAssets::class)->process($asset->id);
        $page->call('refreshImagePreparation')->assertSet('savingPreparation.pending', false);
        $other = User::factory()->create(['role' => 'admin_full']);
        $this->actingAs($other, 'web');
        Livewire::test(CreateReview::class)->fillForm($this->input(['authorPhotoPath' => [$asset->primary_path]]))
            ->call('save')->assertHasFormErrors(['authorPhotoPath']);
        $this->assertDatabaseCount('reviews', 0);
        $this->actingAs(User::findOrFail($asset->owner_id), 'web');
        $page->call('save')->assertHasNoErrors();
        $this->assertSame(Review::sole()->id, $asset->fresh()->review_id);
        $this->getJson('/api/v1/reviews')->assertOk();
    }

    public function test_panel_creates_draft_and_navigates_to_detail(): void
    {
        $page = Livewire::test(CreateReview::class)->set('data', $this->input());
        $submitted = $page->get('data');
        $key = $page->get('saveKey');
        $this->assertDatabaseCount('reviews', 0);
        $page->call('save')->assertHasNoErrors();
        $review = Review::query()->firstOrFail();
        $redirect = '/admin/reviews/'.$review->id;
        $ack = ['ok' => true, 'revision' => app(ReviewsPanel::class)->detail($review->id)['revision'], 'fields' => $submitted, 'redirect' => $redirect];
        $page->assertRedirect($redirect)->assertReturned($ack)->assertSet('saveKey', $key);
        $this->assertSame($redirect, $page->effects['redirect']);
        $this->assertSame($ack, $page->effects['returns'][0]);
        $this->assertDatabaseCount('reviews', 1);
        $this->assertSame('draft', $review->publication_status);
        $this->assertDatabaseCount('review_moderation_events', 1);
    }

    public function test_create_validation_keeps_the_draft_without_a_redirect_ack(): void
    {
        $page = Livewire::test(CreateReview::class)->set('data', $this->input(['text' => ' ']));
        $key = $page->get('saveKey');
        $page->call('save')->assertHasErrors('data.text')->assertSet('data.text', ' ')->assertSet('saveKey', $key);
        $this->assertArrayNotHasKey('redirect', $page->effects);
        $this->assertNull($page->effects['returns'][0]);
        $this->assertDatabaseCount('reviews', 0);
        $this->assertDatabaseCount('review_moderation_events', 0);
    }

    public function test_all_manual_hints_render_alongside_the_original_field_help(): void
    {
        $review = $this->createReview();
        foreach ([CreateReview::class => [], EditReview::class => ['reviewId' => (string) $review['id']]] as $pageClass => $parameters) {
            $page = Livewire::test($pageClass, $parameters)
                ->assertDontSee('setUpUnsavedDataChangesAlert', false)
                ->assertSee('Сохраняйте слова автора и разделение на абзацы.')
                ->assertSee('Необязательно. Ссылка на оригинал отзыва, например ВКонтакте или на Яндекс.Картах. Для личных сообщений оставьте пустым.');
            $html = html_entity_decode($page->html(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertSame(3, substr_count($html, 'data-ui="field-saving.hint"'));
            foreach (['displayName', 'text', 'sourceUrl'] as $field) {
                $this->assertStringContainsString("changed('{$field}')", $html);
            }
            foreach (['review-text-help', 'review-source-help'] as $id) {
                $this->assertStringContainsString('id="'.$id.'"', $html);
                $this->assertStringContainsString('aria-describedby="'.$id.'"', $html);
            }
        }
    }

    public function test_standalone_owner_attributes_are_preserved_while_validation_and_children_render(): void
    {
        $review = $this->createReview();
        $page = Livewire::test(EditReview::class, ['reviewId' => (string) $review['id']])
            ->assertSeeHtml('wire:ignore.self');
        $opening = $page->get('savingOpening');
        $page->set('data.text', ' ')->call('save')->assertHasFormErrors(['text'])
            ->assertSet('savingOpening', $opening)->assertSet('data.text', ' ');
        $page->set('data.text', 'Исправленный текст для standalone ACK.')->call('save')->assertHasNoErrors()
            ->assertSet('savingOpening', $opening)->assertSee('Исправленный текст для standalone ACK.')
            ->assertSeeHtml('wire:key="review-saving-'.$opening.'"');
        $page->call('reloadReview')->assertHasNoErrors();
        $this->assertNotSame($opening, $page->get('savingOpening'));
        $page->assertSeeHtml('wire:key="review-saving-'.$page->get('savingOpening').'"');
    }

    public function test_parameter_ack_keeps_invalid_manual_draft_and_binding_validation_is_recoverable(): void
    {
        $review = $this->createReview();
        $page = Livewire::test(EditReview::class, ['reviewId' => (string) $review['id']]);
        $opening = $page->get('savingOpening');
        $page->set('data.displayName', 'Ручное имя')->set('data.text', ' ')
            ->set('data.showOnHome', true)
            ->call('saveReviewParameters', ['showOnHome' => true], $review['revision'], $opening)
            ->assertHasNoErrors()->assertSet('data.text', ' ')->assertSet('data.displayName', 'Ручное имя')
            ->assertSet('savingBaseline.text', $review['text'])->assertSet('savingBaseline.showOnHome', true)
            ->assertSet('savingOpening', $opening);
        $this->assertSame($review['text'], Review::findOrFail($review['id'])->text);
        $this->assertSame($review['displayName'], Review::findOrFail($review['id'])->display_name);
        $revision = $page->get('revision');
        $key = $page->get('saveKey');
        $page->set('data.scope', 'subject')->call('saveReviewParameters', ['scope' => 'subject', 'subjectId' => null, 'courseId' => null], $revision, $opening)
            ->assertHasErrors('data.subjectId')->assertSet('saveKey', $key)->assertSet('revision', $revision)->assertSet('data.text', ' ');
        $subject = Subject::query()->create(['title' => 'Анатомия', 'slug' => 'parameter-anatomy', 'is_active' => true]);
        $page->set('data.subjectId', $subject->id)
            ->call('saveReviewParameters', ['scope' => 'subject', 'subjectId' => $subject->id, 'courseId' => null], $revision, $opening)
            ->assertHasNoErrors()->assertSet('data.text', ' ')->assertSet('data.displayName', 'Ручное имя');
        $this->assertSame($subject->id, Review::findOrFail($review['id'])->subject_id);
    }

    public function test_standalone_full_ack_keeps_opening_and_returns_only_its_submitted_baseline(): void
    {
        $review = $this->createReview();
        $page = Livewire::test(EditReview::class, ['reviewId' => (string) $review['id']]);
        $opening = $page->get('savingOpening');
        $page->set('data.text', 'Подтверждённый новый текст.')->call('save')->assertHasNoErrors()
            ->assertSet('savingOpening', $opening)->assertSet('data.text', 'Подтверждённый новый текст.')
            ->assertSet('savingBaseline.text', 'Подтверждённый новый текст.');
        $revision = $page->get('revision');
        $page->call('save')->assertHasNoErrors()->assertSet('revision', $revision);
        $this->assertDatabaseCount('review_moderation_events', 2);
    }

    public function test_existing_imported_published_parameters_keep_origin_publication_and_stale_draft(): void
    {
        $review = Review::create(['source' => 'legacy_ocr', 'origin_type' => 'legacy_import', 'display_name' => 'Автор',
            'text' => 'Исходный импортированный текст.', 'status' => 'published', 'publication_status' => 'published', 'published_at' => now()]);
        $page = Livewire::test(EditReview::class, ['reviewId' => (string) $review->id]);
        $opening = $page->get('savingOpening');
        $page->set('data.text', 'Локальный draft')->call('saveReviewParameters', ['showOnHome' => true], 0, $opening)->assertHasNoErrors();
        $review->refresh();
        $this->assertSame(['legacy_ocr', 'legacy_import', 'published', 'Исходный импортированный текст.'],
            [$review->source, $review->origin_type, $review->publication_status, $review->text]);
        $key = $page->get('saveKey');
        $page->call('saveReviewParameters', ['showOnHome' => false], 0, $opening)
            ->assertReturned(['ok' => false, 'terminal' => true, 'message' => 'Отзыв изменился. Обновите данные и повторите действие.'])
            ->assertSet('saveKey', $key)->assertSet('data.text', 'Локальный draft');
    }

    public function test_create_parameter_choices_are_local_until_explicit_full_submit(): void
    {
        $page = Livewire::test(CreateReview::class)->set('data', $this->input(['isAnonymous' => true, 'showOnHome' => true]));
        $this->assertFalse($page->instance()->savingConfig()['existing']);
        $this->assertDatabaseCount('reviews', 0);
        $page->call('save')->assertHasNoErrors();
        $this->assertDatabaseCount('reviews', 1);
        $this->assertTrue(Review::sole()->show_on_home);
    }

    public function test_panel_uploads_author_photo_and_keeps_it_when_editing_text(): void
    {
        Storage::fake('public');
        Livewire::test(CreateReview::class)
            ->fillForm($this->input(['authorPhotoPath' => UploadedFile::fake()->image('portrait.jpg', 1800, 1800)]))
            ->call('save')->assertHasNoErrors();
        $review = Review::query()->firstOrFail();
        $this->assertStringStartsWith('review-authors/', $review->author_photo_path);
        Storage::disk('public')->assertExists($review->author_photo_path);
        $optimized = getimagesizefromstring(Storage::disk('public')->get($review->author_photo_path));
        $this->assertSame([192, 192, 'image/webp'], [$optimized[0], $optimized[1], $optimized['mime']]);
        $filesBeforeEdit = Storage::disk('public')->allFiles('review-authors');
        Livewire::test(EditReview::class, ['reviewId' => (string) $review->id])
            ->fillForm(['text' => 'Обновлённый текст отзыва с фотографией автора.'])
            ->call('save')->assertHasNoErrors();
        $this->assertSame($review->author_photo_path, $review->fresh()->author_photo_path);
        $this->assertSame($filesBeforeEdit, Storage::disk('public')->allFiles('review-authors'));
        Livewire::test(EditReview::class, ['reviewId' => (string) $review->id])
            ->fillForm(['authorPhotoPath' => null])->call('save')->assertHasNoErrors();
        $this->assertNull($review->fresh()->author_photo_path);
        Storage::disk('public')->assertMissing($review->author_photo_path);
    }

    public function test_panel_reopens_assets_from_another_disk_and_keeps_them_when_editing_text(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        Storage::fake('review-test', ['url' => 'https://media.example.test']);
        config(['review-media.disk' => 'review-test']);
        Livewire::test(CreateReview::class)->fillForm($this->input([
            'authorPhotoPath' => UploadedFile::fake()->image('photo.png', 80, 120),
            'screenshotPaths' => [UploadedFile::fake()->image('screen.png', 100, 200)],
        ]))->call('save')->assertHasNoErrors();
        $review = Review::query()->firstOrFail();
        $photo = $review->author_photo_path;
        $media = $review->media()->sole();
        $screen = substr($media->url, strlen('/storage/'));
        Storage::disk('public')->assertMissing($photo);
        Storage::disk('public')->assertMissing($screen);
        config(['review-media.disk' => 'public']);
        $page = Livewire::test(EditReview::class, ['reviewId' => (string) $review->id]);
        $this->assertContains($photo, array_values($page->get('data.authorPhotoPath')));
        $this->assertContains($screen, array_values($page->get('data.screenshotPaths')));
        $page->fillForm(['text' => 'Обновлённый текст с сохранёнными изображениями из другого хранилища.'])
            ->call('save')->assertHasNoErrors()->assertSet('operationError', null);
        $this->assertSame($photo, $review->fresh()->author_photo_path);
        $this->assertSame($media->id, $review->media()->sole()->id);
        Storage::disk('review-test')->assertExists($photo);
        Storage::disk('review-test')->assertExists($screen);
    }

    public function test_imported_published_editor_saves_exact_media_list_and_deletes_removed_files_after_save(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $review = Review::create(['source' => 'legacy_ocr', 'origin_type' => 'legacy_import', 'display_name' => 'Автор',
            'text' => 'Первоначальный отзыв.', 'status' => 'published', 'published_at' => now()->subDay(),
            'review_written_at' => '2026-01-10', 'dedupe_status' => 'unique']);
        $asset = app(ReviewImageAssets::class)->stage(UploadedFile::fake()->image('review.png', 100, 200), 'screenshot', auth('web')->user());
        app(ReviewImageAssets::class)->attach(auth('web')->user(), $review, [$asset->primary_path]);
        $review->media()->create(['type' => 'legacy_screenshot', 'url' => '/storage/'.$asset->primary_path]);
        $files = Storage::disk('public')->allFiles();
        $page = Livewire::test(EditReview::class, ['reviewId' => (string) $review->id])->assertSet('editableOnLoad', true);
        $this->assertContains($asset->primary_path, array_values($page->get('data.screenshotPaths')));
        $page->fillForm(['text' => 'Изменённый опубликованный отзыв.'])->call('save')->assertHasNoErrors()->assertSet('operationError', null);
        $this->assertSame('published', $review->fresh()->publication_status);
        $this->assertSame($files, Storage::disk('public')->allFiles());
        $this->getJson('/api/v1/reviews/'.$review->id)->assertOk()->assertJsonPath('data.text', 'Изменённый опубликованный отзыв.')->assertJsonCount(1, 'data.media');
        $page = Livewire::test(EditReview::class, ['reviewId' => (string) $review->id]);
        $page->fillForm(['screenshotPaths' => []]);
        $this->assertSame($files, Storage::disk('public')->allFiles());
        $page->call('save')->assertHasNoErrors()->assertSet('operationError', null);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->getJson('/api/v1/reviews/'.$review->id)->assertOk()->assertJsonCount(0, 'data.media');
        Livewire::test(EditReview::class, ['reviewId' => (string) $review->id])->assertSee('Что изменено');
    }

    public function test_panel_saves_screenshots_date_course_and_home_selection(): void
    {
        Storage::fake('public');
        $subject = Subject::query()->create(['title' => 'Анатомия', 'slug' => 'anatomy']);
        $course = Course::query()->create(['title' => 'Остеология', 'slug' => 'osteology', 'subject_id' => $subject->id]);
        Livewire::test(CreateReview::class)
            ->fillForm($this->input(['scope' => 'course', 'courseId' => $course->id,
                'showOnHome' => true,
                'screenshotPaths' => [UploadedFile::fake()->image('review.png', 640, 900)]]))
            ->call('save')->assertHasNoErrors();
        $review = Review::query()->firstOrFail();
        $this->assertTrue($review->show_on_home);
        $this->assertSame($course->id, $review->course_id);
        $this->assertSame('2026-01-15', $review->review_written_at->toDateString());
        $media = $review->media()->sole();
        $this->assertStringStartsWith('/storage/review-screenshots/', $media->url);
        $this->assertSame(640, $media->width);
        $this->assertSame(900, $media->height);
        $this->assertStringEndsWith('.webp', $media->url);
        Livewire::test(EditReview::class, ['reviewId' => (string) $review->id])
            ->fillForm(['text' => 'Текст после повторного открытия с сохранённым скриншотом.'])
            ->call('save')->assertHasNoErrors();
        $this->assertSame($media->id, $review->media()->sole()->id);
        $page = Livewire::test(EditReview::class, ['reviewId' => (string) $review->id])
            ->callAction('publish')->assertSet('operationError', null);
        $this->getJson('/api/v1/reviews?placement=home')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.media.0.url', $media->url)
            ->assertJsonPath('data.0.course.id', $course->id);
        $page->callAction('hide')->assertSet('operationError', null);
        Livewire::test(EditReview::class, ['reviewId' => (string) $review->id])
            ->fillForm(['screenshotPaths' => []])->call('save')->assertHasNoErrors();
        $this->assertSame(0, $review->media()->count());
        Storage::disk('public')->assertMissing(substr($media->url, strlen('/storage/')));
    }

    public function test_panel_changes_home_selection_on_published_review_without_hiding_it(): void
    {
        $review = Review::query()->create(['status' => 'published', 'source' => 'legacy_ocr',
            'text' => 'Отзыв с внешней площадки.', 'display_name' => 'Анна']);
        Livewire::test(EditReview::class, ['reviewId' => (string) $review->id])
            ->set('data.showOnHome', true)->call('saveHomePlacement')->assertHasNoErrors()
            ->assertSet('operationError', null);
        $this->assertTrue($review->fresh()->show_on_home);
        $this->assertSame('published', $review->fresh()->publication_status);
    }

    public function test_panel_rejects_missing_date_and_foreign_or_invalid_screenshots(): void
    {
        Storage::fake('public');
        Livewire::test(CreateReview::class)->fillForm($this->input(['reviewWrittenAt' => null]))
            ->call('save')->assertHasFormErrors(['reviewWrittenAt']);
        $review = $this->createReview();
        $foreign = UploadedFile::fake()->image('other.png')->store('review-screenshots', 'public');
        foreach ([$foreign, UploadedFile::fake()->createWithContent('review.svg', '<svg/>')] as $file) {
            Livewire::test(EditReview::class, ['reviewId' => (string) $review['id']])
                ->fillForm(['screenshotPaths' => [$file]])->call('save')->assertHasFormErrors(['screenshotPaths']);
        }
        $this->assertDatabaseCount('review_media', 0);
    }

    public function test_panel_rejects_another_records_photo_path_svg_and_oversized_uploads(): void
    {
        Storage::fake('public');
        $review = $this->createReview();
        $path = UploadedFile::fake()->image('other.jpg')->store('review-authors', 'public');
        foreach ([$path, UploadedFile::fake()->createWithContent('person.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')] as $photo) {
            Livewire::test(EditReview::class, ['reviewId' => (string) $review['id']])
                ->fillForm(['authorPhotoPath' => is_string($photo) ? [$photo] : $photo])->call('save')->assertHasFormErrors(['authorPhotoPath']);
        }
        $this->assertNull(Review::findOrFail($review['id'])->author_photo_path);
    }

    public function test_panel_edits_then_publishes_and_hides_using_current_revision(): void
    {
        $review = $this->createReview();
        $page = Livewire::test(EditReview::class, ['reviewId' => (string) $review['id']])
            ->set('data.text', 'Новый подробный отзыв о занятиях и учебных материалах.')
            ->call('save')->assertHasNoErrors()->assertSet('operationError', null)
            ->callAction('publish')->assertSet('operationError', null);
        $this->assertSame('published', Review::findOrFail($review['id'])->publication_status);
        $page->assertSee('Черновик → Опубликован');
        $page->callAction('hide')->assertSet('operationError', null);
        $this->assertSame('hidden', Review::findOrFail($review['id'])->publication_status);
        $this->assertDatabaseCount('review_moderation_events', 4);
    }

    public function test_stale_editor_does_not_refresh_revision_before_save(): void
    {
        $review = $this->createReview();
        $page = Livewire::test(EditReview::class, ['reviewId' => (string) $review['id']]);
        app(ReviewsPanel::class)->execute('update', $review['id'], $this->input(['text' => 'Изменено другим редактором.', 'revision' => $review['revision']]), 'other');
        $page->set('data.text', 'Мой устаревший текст.')->call('save')
            ->assertSet('operationError', 'Отзыв изменился. Обновите данные и повторите действие.')
            ->assertSet('revision', $review['revision']);
        $this->assertSame('Изменено другим редактором.', Review::findOrFail($review['id'])->text);
        $this->assertDatabaseCount('review_moderation_events', 2);
    }

    public function test_unsaved_editor_cannot_publish_persisted_old_text(): void
    {
        $review = $this->createReview();
        Livewire::test(EditReview::class, ['reviewId' => (string) $review['id']])
            ->set('data.text', 'Ещё не сохранённый текст.')->callAction('publish')
            ->assertSet('operationError', 'Сначала сохраните изменения или загрузите отзыв заново.');
        $this->assertSame('draft', Review::findOrFail($review['id'])->publication_status);
    }

    public function test_concurrent_publication_keeps_unsaved_form_until_explicit_reload(): void
    {
        $review = $this->createReview();
        $page = Livewire::test(EditReview::class, ['reviewId' => (string) $review['id']]);
        app(ReviewsPanel::class)->execute('publication', $review['id'], ['action' => 'publish', 'revision' => $review['revision']], 'other-publish');
        $page->set('data.text', 'Мой несохранённый текст.')->call('save')
            ->assertSet('data.text', 'Мой несохранённый текст.')
            ->assertSeeHtml('data-ui="admin.reviews.form"')->assertActionVisible('reload');
        $this->assertSame($this->input()['text'], Review::findOrFail($review['id'])->text);
        $page->callAction('reload')->assertSeeHtml('data-ui="admin.reviews.form"');
    }

    public function test_duplicate_text_cannot_be_published(): void
    {
        $this->createReview();
        $second = $this->createReview([], 'second');
        Livewire::test(EditReview::class, ['reviewId' => (string) $second['id']])->callAction('publish')
            ->assertSet('operationError', 'Найдено совпадение с другим отзывом. Публикация недоступна до проверки дублей.');
        $this->assertSame('draft', Review::findOrFail($second['id'])->publication_status);
    }

    public function test_repeated_identical_key_replays_without_duplicate_audit(): void
    {
        $first = $this->createReview();
        $replay = $this->createReview();
        $this->assertSame($first['id'], $replay['id']);
        $this->assertDatabaseCount('review_moderation_events', 1);
        try {
            $this->createReview(['text' => 'Другой текст с тем же ключом.']);
            $this->fail('Expected idempotency conflict.');
        } catch (AdminReviewException $exception) {
            $this->assertSame('ADMIN_REVIEW_IDEMPOTENCY_CONFLICT', $exception->errorCode);
        }
    }

    public function test_unknown_fields_and_inconsistent_scope_are_rejected(): void
    {
        Livewire::test(CreateReview::class)->set('data', $this->input(['source' => 'legacy_ocr']))
            ->call('save')->assertHasErrors(['request']);
        Livewire::test(CreateReview::class)->set('data', $this->input(['scope' => 'course']))
            ->call('save')->assertHasErrors();
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_permission_revocation_after_mount_prevents_save_and_rerender(): void
    {
        $page = Livewire::test(CreateReview::class)->set('data', $this->input());
        User::query()->whereKey(auth('web')->id())->update(['role' => 'admin_limited']);
        $page->call('save')->assertForbidden();
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_review_routes_deny_staff_without_review_permission(): void
    {
        $review = $this->createReview();
        foreach (['admin_limited', 'manager', 'teacher', 'user_no_purchase'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]), 'web');
            $this->get('/admin/reviews')->assertForbidden();
            $this->get('/admin/reviews/'.$review['id'])->assertForbidden();
        }
    }

    public function test_lost_web_session_cannot_submit_an_open_review(): void
    {
        $review = $this->createReview();
        $html = $this->get('/admin/reviews/'.$review['id'])->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);
        [, $name] = app(Finder::class)->parseNamespaceAndName(EditReview::class);
        $snapshot = collect($matches[1])->map(fn (string $value) => html_entity_decode($value, ENT_QUOTES))
            ->first(fn (string $value) => json_decode($value, true)['memo']['name'] === $name);
        $this->assertNotNull($snapshot);
        auth('web')->logout();
        session()->invalidate();
        $this->withHeader('X-Livewire', 'true')->postJson(EndpointResolver::updatePath(), [
            'components' => [['snapshot' => $snapshot, 'updates' => ['data.text' => 'Запись без сессии'],
                'calls' => [['path' => '', 'method' => 'save', 'params' => []]]]],
        ])->assertRedirect();
        $this->assertSame($this->input()['text'], Review::findOrFail($review['id'])->text);
        $this->assertDatabaseCount('review_moderation_events', 1);
    }

    public function test_read_only_import_can_be_hidden_but_has_no_edit_or_publish_action(): void
    {
        $review = Review::query()->create(['text' => 'Архивный отзыв', 'display_name' => 'Автор',
            'source' => 'legacy_ocr', 'status' => 'published', 'published_at' => now()]);
        Livewire::test(EditReview::class, ['reviewId' => (string) $review->id])
            ->assertActionHidden('publish')->assertActionVisible('hide')->callAction('hide');
        $this->assertSame('hidden', $review->fresh()->publication_status);
        $this->assertSame('Архивный отзыв', $review->fresh()->text);
        $this->assertSame('legacy_ocr', $review->fresh()->source);
    }

    public function test_photo_filters_and_detail_never_expose_private_media_or_audit_metadata(): void
    {
        $review = $this->createReview();
        ReviewMedia::query()->create(['review_id' => $review['id'], 'type' => 'attachment', 'url' => '/private/review.jpg']);
        ReviewEvidence::query()->create(['review_id' => $review['id'], 'type' => 'image', 'is_public' => false]);
        $page = Livewire::test(Reviews::class)->filterTable('screen', 'yes');
        $this->assertCount(1, $page->instance()->getTableRecords());
        Livewire::test(EditReview::class, ['reviewId' => (string) $review['id']])
            ->assertSee('Фото в материалах')->assertSee('Создан черновик')
            ->assertDontSee('/private/review.jpg')->assertDontSee('idempotency_key_hash')->assertDontSee('request_hash');
    }

    public function test_create_throttle_is_preserved(): void
    {
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit('filament:reviews:create:'.auth('web')->id(), 60);
        }
        Livewire::test(CreateReview::class)->set('data', $this->input())->call('save')
            ->assertSet('data.text', $this->input()['text'])
            ->assertSee('Слишком много попыток');
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_course_only_review_displays_its_subject_and_course(): void
    {
        $subject = Subject::query()->create(['title' => 'Анатомия', 'slug' => 'anatomy', 'is_active' => true]);
        $course = Course::query()->create(['title' => 'Базовый курс', 'slug' => 'base', 'subject_id' => $subject->id, 'price_cents' => 10000, 'is_active' => true]);
        $review = $this->createReview(['scope' => 'course', 'courseId' => $course->id]);
        $this->assertNull(Review::findOrFail($review['id'])->subject_id);
        Livewire::test(Reviews::class)->assertSee('Анатомия · Базовый курс')
            ->assertTableColumnStateSet('id', $review['id'], $review['id']);
    }

    public function test_list_links_and_editor_return_preserve_list_context_without_external_redirect(): void
    {
        $review = $this->createReview();
        $page = Livewire::withQueryParams(['page' => 2, 'perPage' => 50,
            'filters' => ['author' => ['value' => 'Мария'], 'status' => ['value' => 'draft']]])->test(Reviews::class);
        $url = $page->instance()->listUrl();
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('search', $query);
        $this->assertSame('Мария', $query['filters']['author']['value']);
        $this->assertSame('draft', $query['filters']['status']['value']);
        $this->assertSame('2', $query['page']);
        $this->assertSame('50', $query['perPage']);
        $editor = Livewire::withQueryParams(['returnTo' => $url])->test(EditReview::class, ['reviewId' => (string) $review['id']]);
        $this->assertSame($url, $editor->instance()->backUrl());
        foreach (['https://example.com/admin/reviews', '//example.com/admin/reviews', 'javascript:/admin/reviews', '/admin/users'] as $unsafe) {
            $editor->set('returnTo', $unsafe);
            $this->assertSame('/admin/reviews', $editor->instance()->backUrl());
        }
    }

    public function test_empty_list_and_empty_search_have_distinct_states(): void
    {
        Livewire::test(Reviews::class)->assertSee('Отзывов пока нет')
            ->filterTable('text', ['value' => 'нет совпадений'])->assertSee('Отзывы не найдены')
            ->assertSee('Сбросить поиск и фильтры');
    }

    public function test_server_validation_errors_use_form_field_paths_and_keep_input(): void
    {
        Livewire::test(CreateReview::class)->set('data', $this->input(['text' => '   ']))->call('save')
            ->assertHasErrors(['data.text'])->assertSet('data.displayName', 'Мария');
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_unchanged_second_save_does_not_duplicate_audit(): void
    {
        $review = $this->createReview();
        Livewire::test(EditReview::class, ['reviewId' => (string) $review['id']])
            ->set('data.text', 'Изменённый текст для проверки повторного сохранения.')
            ->call('save')->call('save')->assertHasNoErrors();
        $this->assertDatabaseCount('review_moderation_events', 2);
    }

    public function test_lost_response_retries_same_command_without_second_update(): void
    {
        $review = $this->createReview();
        $service = app(AdminReviewService::class);
        $attempt = 0;
        $mock = \Mockery::mock($service);
        $mock->shouldReceive('query')->andReturnUsing(fn () => $service->query());
        $mock->shouldReceive('detail')->andReturnUsing(fn (int $id) => $service->detail($id));
        $mock->shouldReceive('execute')->andReturnUsing(function (...$arguments) use ($service, &$attempt) {
            $result = $service->execute(...$arguments);
            if (++$attempt === 1) {
                throw new QueryException('sqlite', 'simulated transport failure after commit', [], new \PDOException('Synthetic failure'));
            }

            return $result;
        });
        app()->instance(AdminReviewService::class, $mock);
        $page = Livewire::test(EditReview::class, ['reviewId' => (string) $review['id']])
            ->set('data.text', 'Сохранено до потери ответа сервера.')->call('save')
            ->assertSee('Не удалось подтвердить сохранение')->assertSet('data.text', 'Сохранено до потери ответа сервера.');
        $key = $page->get('saveKey');
        $page->call('save')->assertSet('operationError', null);
        $this->assertNotSame($key, $page->get('saveKey'));
        $this->assertDatabaseCount('review_moderation_events', 2);
    }

    public function test_imported_review_is_editable_and_can_be_republished_after_hiding(): void
    {
        $review = Review::query()->create(['text' => 'Импортированный отзыв.', 'display_name' => 'Автор', 'source' => 'legacy_manual',
            'origin_type' => 'manual', 'status' => 'published', 'published_at' => now()]);
        $page = Livewire::test(EditReview::class, ['reviewId' => (string) $review->id])
            ->assertSeeHtml('data-ui="admin.reviews.form"')->assertSee('Сохранить изменения')
            ->assertActionVisible('public')->mountAction('hide');
        $this->assertStringContainsString('Его можно опубликовать снова.', (string) $page->instance()->getMountedAction()->getModalDescription());
        $page->callMountedAction()->assertActionVisible('publish')->assertActionHidden('public');
    }

    public function test_anonymous_review_saved_without_public_name_and_dirty_baseline_resets(): void
    {
        $review = $this->createReview();
        $page = Livewire::test(EditReview::class, ['reviewId' => (string) $review['id']]);
        $baseline = $page->get('savingBaseline');
        $page->set('data.isAnonymous', true)->set('data.displayName', '')->call('save')->assertHasNoErrors();
        $this->assertSame('Анонимный автор', Review::findOrFail($review['id'])->display_name);
        $this->assertNotSame($baseline, $page->get('savingBaseline'));
        $page->assertSet('savingBaseline.displayName', 'Анонимный автор')->assertSet('savingBaseline.isAnonymous', true);
    }

    public function test_hard_delete_requires_confirmation_and_returns_to_filtered_list(): void
    {
        $review = $this->createReview();
        $return = '/admin/reviews?filters%5Bhome%5D%5Bvalue%5D=yes';
        Livewire::test(CreateReview::class)->assertActionHidden('delete');
        $page = Livewire::test(EditReview::class, ['reviewId' => (string) $review['id']])->set('returnTo', $return)
            ->assertActionVisible('delete')->mountAction('delete');
        $this->assertStringContainsString('без возможности восстановления', (string) $page->instance()->getMountedAction()->getModalDescription());
        $page->setActionData(['confirmation' => 'wrong'])->callMountedAction()->assertHasActionErrors(['confirmation']);
        $this->assertDatabaseHas('reviews', ['id' => $review['id']]);
        $page->setActionData(['confirmation' => (string) $review['id']])->callMountedAction()->assertHasNoActionErrors()->assertRedirect($return);
        $this->assertDatabaseMissing('reviews', ['id' => $review['id']]);
        $this->assertDatabaseCount('admin_operation_events', 1);
    }

    public function test_header_choices_apply_immediately_combine_and_reset(): void
    {
        $a = $this->createReview(['showOnHome' => true], 'first');
        $b = $this->createReview(['showOnHome' => false], 'second');
        $page = Livewire::test(Reviews::class)->assertSee('Дата отзыва')->assertSee('Главная')
            ->call('chooseColumnFilter', 'show_on_home', 'yes')
            ->assertCanSeeTableRecords([Review::findOrFail($a['id'])])->assertCanNotSeeTableRecords([Review::findOrFail($b['id'])]);
        $this->assertStringContainsString('filters%5Bhome%5D%5Bvalue%5D=yes', $page->instance()->listUrl());
        $page->call('chooseColumnFilter', 'publication_status', 'published')->assertCountTableRecords(0)
            ->call('chooseColumnFilter', 'publication_status', 'draft')->assertCountTableRecords(1)
            ->call('chooseColumnFilter', 'show_on_home', 'no')
            ->assertCanSeeTableRecords([Review::findOrFail($b['id'])])->assertCanNotSeeTableRecords([Review::findOrFail($a['id'])]);
        $page->call('chooseColumnFilter', 'show_on_home', 'invalid')->assertSet('tableFilters.home.value', 'no')
            ->call('chooseColumnFilter', 'text', 'arbitrary')->assertSet('tableFilters.text.value', null)
            ->call('chooseColumnFilter', 'show_on_home', null)->assertCountTableRecords(2)
            ->call('chooseColumnFilter', 'media_count', 'yes')->assertCountTableRecords(0)
            ->call('chooseColumnFilter', 'media_count', 'no')->assertCountTableRecords(2)
            ->resetTableFilters()->assertSet('tableFilters.photo.value', null)->assertCountTableRecords(2);
    }

    public function test_column_sort_uses_all_pages_and_keeps_missing_dates_last_in_both_directions(): void
    {
        $ids = [];
        for ($i = 1; $i <= 22; $i++) {
            $ids[] = $this->createReview(['reviewWrittenAt' => sprintf('2026-01-%02d', $i)], 'sort-'.$i)['id'];
        }
        $undated = $this->createReview([], 'undated')['id'];
        Review::whereKey($undated)->update(['review_written_at' => null]);
        $page = Livewire::test(Reviews::class)->set('tableRecordsPerPage', 20);
        $this->assertSame(array_reverse(array_slice($ids, 2)), $page->instance()->getTableRecords()->pluck('id')->all());
        $page->call('gotoPage', 2);
        $this->assertSame([$ids[1], $ids[0], $undated], $page->instance()->getTableRecords()->pluck('id')->all());
        $page->call('sortTable', 'review_written_at');
        $this->assertSame(1, $page->instance()->getTablePage());
        $this->assertSame(array_slice($ids, 0, 20), $page->instance()->getTableRecords()->pluck('id')->all());
        $page->call('gotoPage', 2);
        $this->assertSame([$ids[20], $ids[21], $undated], $page->instance()->getTableRecords()->pluck('id')->all());
    }

    public function test_column_searches_are_independent_literal_and_combined_with_other_filters(): void
    {
        $match = $this->createReview(['displayName' => 'Alice', 'text' => 'Result is 100%_clear!', 'showOnHome' => true], 'match')['id'];
        $this->createReview(['displayName' => 'Result', 'text' => 'Alice 100 percent clear'], 'other');
        $page = Livewire::test(Reviews::class)->filterTable('author', ['value' => 'alice'])
            ->filterTable('text', ['value' => '%_'])->filterTable('home', 'yes')->filterTable('status', 'draft');
        $this->assertSame([$match], $page->instance()->getTableRecords()->pluck('id')->all());
        $page->filterTable('text', ['value' => 'Alice']);
        $this->assertSame(0, $page->instance()->getTableRecords()->total());
        $page->call('clearColumnFilters', 'text');
        $this->assertSame([$match], $page->instance()->getTableRecords()->pluck('id')->all());
        $page->call('removeTableFilter', 'author')->resetTableFilters();
        $this->assertSame(2, $page->instance()->getTableRecords()->total());
    }

    public function test_id_filter_is_exact_combines_with_other_filters_and_can_be_cleared(): void
    {
        $match = $this->createReview(['showOnHome' => true], 'id-match')['id'];
        $other = $this->createReview(['showOnHome' => true], 'id-other')['id'];
        $prefixMatch = Review::findOrFail($match)->replicate();
        $prefixMatch->id = (int) ($match.'0');
        $prefixMatch->show_on_home = false;
        $prefixMatch->save();
        $page = Livewire::test(Reviews::class)->filterTable('id', ['value' => (string) $match]);
        $page->assertSeeHtml('data-admin-column-min-width="104"');
        $this->assertSame([$match], $page->instance()->getTableRecords()->pluck('id')->all());
        $page->filterTable('home', 'yes')->filterTable('status', 'hidden');
        $this->assertSame(0, $page->instance()->getTableRecords()->total());
        $page->call('clearColumnFilters', 'publication_status');
        $this->assertSame([$match], $page->instance()->getTableRecords()->pluck('id')->all());
        $page->filterTable('id', ['value' => '999999']);
        $this->assertSame(0, $page->instance()->getTableRecords()->total());
        $page->call('clearColumnFilters', 'id');
        $this->assertEqualsCanonicalizing([$match, $other], $page->instance()->getTableRecords()->pluck('id')->all());
        $page->filterTable('id', ['value' => '']);
        $this->assertSame(2, $page->instance()->getTableRecords()->total());
    }

    public function test_id_filter_roundtrips_in_url_and_rejects_invalid_input_without_query_failure(): void
    {
        $match = $this->createReview([], 'id-url')['id'];
        $page = Livewire::withQueryParams(['filters' => ['id' => ['value' => (string) $match]], 'sort' => 'id:asc'])->test(Reviews::class);
        $this->assertSame([$match], $page->instance()->getTableRecords()->pluck('id')->all());
        parse_str(parse_url($page->instance()->listUrl(), PHP_URL_QUERY), $query);
        $this->assertSame((string) $match, $query['filters']['id']['value']);
        $this->assertSame('id:asc', $query['sort']);
        $url = $page->instance()->getTable()->getRecordUrl(Review::findOrFail($match));
        parse_str(parse_url($url, PHP_URL_QUERY), $editorQuery);
        $this->assertSame($page->instance()->listUrl(), $editorQuery['returnTo']);
        foreach (['abc', '0', '-1', '1.5', str_repeat('9', 30)] as $invalid) {
            $page->filterTable('id', ['value' => $invalid])->assertHasErrors(['tableFilters.id.value']);
            $this->assertSame(0, $page->instance()->getTableRecords()->total());
        }
        $page->call('clearColumnFilters', 'id')->assertHasNoErrors();
        $this->assertSame(1, $page->instance()->getTableRecords()->total());
    }

    public function test_date_range_is_inclusive_and_undated_filter_can_be_cleared(): void
    {
        $a = $this->createReview(['reviewWrittenAt' => '2026-02-01'], 'a')['id'];
        $b = $this->createReview(['reviewWrittenAt' => '2026-02-02'], 'b')['id'];
        $c = $this->createReview(['reviewWrittenAt' => '2026-02-03'], 'c')['id'];
        Review::whereKey($c)->update(['review_written_at' => null]);
        $page = Livewire::test(Reviews::class)->filterTable('date', ['from' => '2026-02-01', 'to' => '2026-02-02']);
        $this->assertSame([$b, $a], $page->instance()->getTableRecords()->pluck('id')->all());
        $page->call('clearColumnFilters', 'review_written_at')->filterTable('date', ['value' => 'undated']);
        $this->assertSame([$c], $page->instance()->getTableRecords()->pluck('id')->all());
        $page->call('removeTableFilter', 'date');
        $this->assertSame(3, $page->instance()->getTableRecords()->total());
    }

    public function test_author_sort_and_search_use_displayed_anonymous_name_and_stable_ties(): void
    {
        $a = $this->createReview(['displayName' => 'Alice'], 'alice')['id'];
        $b = $this->createReview(['displayName' => 'Bob'], 'bob')['id'];
        $c = $this->createReview(['displayName' => 'Bob'], 'bob2')['id'];
        $anonymous = $this->createReview(['isAnonymous' => true, 'displayName' => null], 'anonymous')['id'];
        Review::whereKey($anonymous)->update(['display_name' => 'SecretName']);
        $page = Livewire::test(Reviews::class)->sortTable('display_name', 'asc');
        $this->assertSame([$a, $c, $b, $anonymous], $page->instance()->getTableRecords()->pluck('id')->all());
        $page->filterTable('author', ['value' => 'SecretName']);
        $this->assertSame(0, $page->instance()->getTableRecords()->total());
        $page->filterTable('author', ['value' => 'анонимный']);
        $this->assertSame([$anonymous], $page->instance()->getTableRecords()->pluck('id')->all());
    }

    public function test_date_range_error_is_shown_at_field_and_recovers_after_correction(): void
    {
        $this->createReview(['reviewWrittenAt' => '2026-02-02']);
        $page = Livewire::test(Reviews::class)->filterTable('date', ['from' => '2026-02-02', 'to' => '2026-02-01'])
            ->assertHasErrors('tableFilters.date.to');
        $this->assertSame(0, $page->instance()->getTableRecords()->total());
        $page->set('tableFilters.date.to', '2026-02-02')->assertHasNoErrors();
        $this->assertSame(1, $page->instance()->getTableRecords()->total());
        $page->set('tableFilters.date.value', 'undated')->assertSet('tableFilters.date.from', null)->assertSet('tableFilters.date.to', null);
        $page->call('clearColumnFilters', 'review_written_at');
        $this->assertSame(1, $page->instance()->getTableRecords()->total());
    }

    public function test_scope_sort_and_filters_use_subject_and_course_titles(): void
    {
        $subject = Subject::query()->create(['title' => 'Anatomy', 'slug' => 'anatomy', 'is_active' => true]);
        $course = Course::query()->create(['title' => 'Base', 'slug' => 'base', 'subject_id' => $subject->id, 'price_cents' => 10000, 'is_active' => true]);
        $general = $this->createReview([], 'general')['id'];
        $subjectReview = $this->createReview(['scope' => 'subject', 'subjectId' => $subject->id], 'subject')['id'];
        $courseReview = $this->createReview(['scope' => 'course', 'courseId' => $course->id], 'course')['id'];
        $page = Livewire::test(Reviews::class)->sortTable('scope', 'asc');
        $this->assertSame([$subjectReview, $courseReview, $general], $page->instance()->getTableRecords()->pluck('id')->all());
        $page->filterTable('subjectId', $subject->id)->filterTable('courseId', $course->id);
        $this->assertSame([$courseReview], $page->instance()->getTableRecords()->pluck('id')->all());
        $page->call('clearColumnFilters', 'scope')->filterTable('scope', 'general');
        $this->assertSame([$general], $page->instance()->getTableRecords()->pluck('id')->all());
    }

    public function test_column_context_roundtrips_and_unsupported_sort_is_ignored(): void
    {
        $this->createReview();
        $page = Livewire::withQueryParams(['sort' => 'display_name:asc', 'filters' => ['text' => ['value' => 'курс']]])->test(Reviews::class);
        $this->assertSame('display_name', $page->instance()->getTableSortColumn());
        parse_str(parse_url($page->instance()->listUrl(), PHP_URL_QUERY), $query);
        $this->assertSame('display_name:asc', $query['sort']);
        $this->assertSame('курс', $query['filters']['text']['value']);
        $page->call('sortTable', 'review_written_at');
        $this->assertSame('desc', $page->instance()->getTableSortDirection());
        $page->call('sortTable', 'text');
        $this->assertSame('review_written_at', $page->instance()->getTableSortColumn());
    }

    public function test_reviews_have_only_column_controls_and_ignore_removed_global_filters(): void
    {
        $this->createReview();
        $page = Livewire::withQueryParams(['search' => 'нет совпадений', 'filters' => [
            'added' => ['from' => '2099-01-01'], 'addedOrder' => ['value' => 'oldest'],
        ]])->test(Reviews::class)->assertCountTableRecords(1);
        $this->assertFalse($page->instance()->getTable()->isSearchable());
        $this->assertNull($page->instance()->getTable()->getFilter('added'));
        $this->assertNull($page->instance()->getTable()->getFilter('addedOrder'));
        $this->assertSame('review_written_at', $page->instance()->getTableSortColumn());
        $this->assertSame('desc', $page->instance()->getTableSortDirection());
        $this->get('/admin/reviews')->assertOk()->assertSee('data-ui="reviews.search.text"', false)
            ->assertDontSee('data-ui="reviews.filter.text"', false)
            ->assertDontSee('Дополнительно')->assertDontSee('Поиск отзывов');
    }
}
