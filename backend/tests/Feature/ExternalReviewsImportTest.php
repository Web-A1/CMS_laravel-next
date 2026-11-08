<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\ReviewEvidence;
use App\Models\ReviewImportBatch;
use App\Models\ReviewSource;
use App\Services\Reviews\ExternalReviewImportService;
use App\Services\Reviews\ReviewDuplicateMatcher;
use App\Services\Reviews\ReviewParagraphNormalizer;
use App\Services\Reviews\ReviewTextNormalizer;
use Database\Seeders\ExternalReviewsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ExternalReviewsImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_text_normalizer_collapses_whitespace_and_punctuation(): void
    {
        $normalizer = new ReviewTextNormalizer;

        $this->assertSame('привет мир', $normalizer->normalize('  Привет,   мир!  '));
        $this->assertSame(
            $normalizer->hash('Привет, мир!'),
            $normalizer->hash("привет\nмир"),
        );
    }

    public function test_review_paragraph_normalizer_splits_long_reviews_without_breaking_abbreviations(): void
    {
        $normalizer = new ReviewParagraphNormalizer;
        $text = 'Сам курс сделан очень качественно: красивые, структурированные конспекты, в которых отражена не только основная тема, но и прикладные дисциплины: нормальная физиология, фармакология и т. д., что помогает лучше усвоить материал. У Иры спокойная, хорошо поставленная речь, и говорит она только по делу. Очень люблю делать ваши домашки, это помогает запомнить материал ещё лучше, ведь, как известно, в долгосрочную память переводится только то, что используется на практике. Вывод: курс по патану бомбический и, конечно, я его рекомендую.';

        $normalized = $normalizer->normalize($text);

        $this->assertStringContainsString("\n\nОчень люблю", $normalized);
        $this->assertStringContainsString('т. д., что помогает', $normalized);
        $this->assertStringNotContainsString("т.\n\nд.", $normalized);
    }

    public function test_review_paragraph_normalizer_splits_long_sentences_on_new_thought_markers(): void
    {
        $normalizer = new ReviewParagraphNormalizer;
        $text = 'Я покупала темы по курсу общей гистологии, решила купить потому что нашей группе достался достаточно тяжелый преподаватель, а кафедральный учебник был очень плохо структурирован, в нем много лишней информации и не хватает нужной, а разобраться в теме сильно хотелось, поэтому решила взять ваши курсы Больше всего понравилось что объясняются моменты физиологии, из-за чего значительно понятнее функции ткани, морфология. Много удобных и понятных схем, конспекты классно структурированы. А результат, за два коллоквиума по общей гистологии у меня пятерки';

        $normalized = $normalizer->normalize($text);

        $this->assertStringContainsString("\n\nБольше всего понравилось", $normalized);
        $this->assertStringContainsString("\n\nА результат", $normalized);
    }

    public function test_review_paragraph_normalizer_splits_two_sentence_reviews_on_summary_markers(): void
    {
        $normalizer = new ReviewParagraphNormalizer;
        $text = 'Курс помог спокойно разобрать сложную тему перед экзаменом: материал был структурирован, конспекты удобно повторять, а объяснения закрыли пробелы, которые оставались после университетских занятий и самостоятельного чтения учебника. В итоге я пришла на зачёт без паники и смогла ответить на дополнительные вопросы.';

        $normalized = $normalizer->normalize($text);

        $this->assertStringContainsString("\n\nВ итоге", $normalized);
    }

    public function test_normalize_review_paragraphs_command_supports_dry_run_and_apply(): void
    {
        $text = 'Здравствуйте, курс просмотрен ещё не полностью, но уже то, что я прошёл, помогло мне сдать зачёты на максимальный балл. Вся информация в курсе предоставлена подробно и детально, а самое главное всё в одном месте. За конспекты отдельный респект, не раз выручали в быстром повторе информации. Платформа тоже очень удобная в использовании, никаких нареканий нет. В общем, хочу сказать спасибо за прекрасный курс, который действительно заставляет понять информацию, а не зазубривать её.';
        $review = Review::query()->create([
            'text' => $text,
            'display_name' => 'Анна',
            'status' => Review::STATUS_PUBLISHED,
            'moderation_status' => Review::MODERATION_APPROVED,
            'publication_status' => Review::PUBLICATION_PUBLISHED,
            'dedupe_status' => Review::DEDUPE_UNIQUE,
            'origin_type' => Review::ORIGIN_LEGACY_IMPORT,
            'source' => 'legacy_ocr',
            'source_ref' => 'test:paragraphs:001',
            'published_at' => now()->subDay(),
        ]);

        Artisan::call('reviews:normalize-paragraphs', [
            '--source-ref' => 'test:paragraphs:001',
            '--json' => true,
        ]);

        $this->assertSame($text, $review->fresh()->text);

        Artisan::call('reviews:normalize-paragraphs', [
            '--source-ref' => 'test:paragraphs:001',
            '--apply' => true,
            '--json' => true,
        ]);

        $freshReview = $review->fresh();

        $this->assertStringContainsString("\n\nПлатформа", $freshReview->text);
        $this->assertSame(Review::MODERATION_APPROVED, $freshReview->moderation_status);
        $this->assertSame(Review::PUBLICATION_PUBLISHED, $freshReview->publication_status);
        $this->assertSame(Review::DEDUPE_UNIQUE, $freshReview->dedupe_status);
    }

    public function test_normalize_review_paragraphs_command_can_rebuild_display_text_from_raw_source(): void
    {
        $rawText = 'Курс помог спокойно разобрать сложную тему перед экзаменом: материал был структурирован, конспекты удобно повторять, а объяснения закрыли пробелы, которые оставались после университетских занятий и самостоятельного чтения учебника. В итоге я пришла на зачёт без паники и смогла ответить на дополнительные вопросы.';
        $review = Review::query()->create([
            'text' => "Курс помог спокойно разобрать сложную тему.\n\nВ итоге я пришла на зачёт без паники.",
            'display_name' => 'Анна',
            'status' => Review::STATUS_PUBLISHED,
            'moderation_status' => Review::MODERATION_APPROVED,
            'publication_status' => Review::PUBLICATION_PUBLISHED,
            'dedupe_status' => Review::DEDUPE_UNIQUE,
            'origin_type' => Review::ORIGIN_LEGACY_IMPORT,
            'source' => 'legacy_ocr',
            'source_ref' => 'test:paragraphs:source',
            'published_at' => now()->subDay(),
        ]);
        $review->sources()->create([
            'source_type' => ReviewSource::TYPE_TELEGRAM,
            'source_ref' => 'test:paragraphs:source',
            'raw_text' => $rawText,
            'is_public' => false,
        ]);

        Artisan::call('reviews:normalize-paragraphs', [
            '--source-ref' => 'test:paragraphs:source',
            '--from-source' => true,
            '--apply' => true,
            '--json' => true,
        ]);

        $freshReview = $review->fresh();
        $source = $freshReview->sources()->firstOrFail();

        $this->assertStringContainsString("\n\nВ итоге", $freshReview->text);
        $this->assertStringContainsString('самостоятельного чтения учебника', $freshReview->text);
        $this->assertSame($rawText, $source->raw_text);
        $this->assertSame(Review::MODERATION_APPROVED, $freshReview->moderation_status);
        $this->assertSame(Review::PUBLICATION_PUBLISHED, $freshReview->publication_status);
        $this->assertSame(Review::DEDUPE_UNIQUE, $freshReview->dedupe_status);
    }

    public function test_review_duplicate_matcher_finds_similar_text_reviews(): void
    {
        $review = Review::query()->create([
            'text' => 'Материал понятный и структурированный для экзамена.',
            'display_name' => 'Анна',
            'status' => Review::STATUS_PUBLISHED,
            'moderation_status' => Review::MODERATION_APPROVED,
            'publication_status' => Review::PUBLICATION_PUBLISHED,
            'dedupe_status' => Review::DEDUPE_UNIQUE,
            'origin_type' => Review::ORIGIN_LEGACY_IMPORT,
            'source' => 'legacy_ocr',
            'published_at' => now()->subDay(),
        ]);

        $matcher = new ReviewDuplicateMatcher(new ReviewTextNormalizer);
        $match = $matcher->findMatch([
            'text' => 'Материал очень понятный и структурированный для экзамена.',
        ]);

        $this->assertNotNull($match);
        $this->assertTrue($match['review']->is($review));
        $this->assertGreaterThanOrEqual(0.92, $match['score']);
        $this->assertSame('similar_text', $match['match_type']);
    }

    public function test_external_reviews_import_publishes_high_confidence_new_records(): void
    {
        $batch = ReviewImportBatch::query()->create([
            'source_type' => ReviewSource::TYPE_VK_WEBARCHIVE,
            'batch_ref' => 'vk_webarchive:test-batch',
            'source_label' => 'VK webarchive test',
            'source_path' => 'reviews/sources/vk/test.webarchive',
            'status' => ReviewImportBatch::STATUS_PENDING,
            'records_total' => 1,
            'records_created' => 0,
            'records_updated' => 0,
        ]);

        $service = app(ExternalReviewImportService::class);
        $result = $service->importRecord($batch, [
            'confidence' => 'high',
            'display_name' => 'Дарья',
            'evidence' => [
                [
                    'type' => 'webarchive',
                    'file_path' => 'reviews/sources/vk/test.webarchive',
                    'provenance' => 'vk_webarchive',
                    'sha256' => hash('sha256', 'vk-test-1'),
                    'metadata' => ['local_source_name' => 'test.webarchive'],
                ],
            ],
            'external_author_name' => 'Дарья',
            'external_id' => null,
            'external_written_at' => '2026-03-12T09:36:00+03:00',
            'is_public' => false,
            'raw_payload' => ['source_file' => 'test.webarchive'],
            'source_label' => 'VK webarchive test',
            'source_ref' => 'vk_webarchive:test-batch:001',
            'source_url' => null,
            'text' => 'Брала курс «препараты для лечения сердечно-сосудистых заболеваний».',
        ]);

        $this->assertSame('created_review', $result['action']);
        $this->assertDatabaseCount('reviews', 1);

        $review = Review::query()->firstOrFail();
        $this->assertSame(Review::STATUS_PUBLISHED, $review->status);
        $this->assertSame(Review::MODERATION_APPROVED, $review->moderation_status);
        $this->assertSame(Review::PUBLICATION_PUBLISHED, $review->publication_status);
        $this->assertSame(Review::DEDUPE_UNIQUE, $review->dedupe_status);
        $this->assertSame('legacy_ocr', $review->source);
        $this->assertNotNull($review->published_at);

        $source = $review->sources()->firstOrFail();
        $this->assertSame(ReviewSource::TYPE_VK_WEBARCHIVE, $source->source_type);
        $this->assertSame('Брала курс «препараты для лечения сердечно-сосудистых заболеваний».', $source->raw_text);

        $this->assertDatabaseHas('review_evidence', [
            'review_id' => $review->id,
            'review_source_id' => $source->id,
            'type' => 'webarchive',
        ]);
    }

    public function test_external_reviews_import_keeps_raw_text_and_normalizes_display_text(): void
    {
        $batch = ReviewImportBatch::query()->create([
            'source_type' => ReviewSource::TYPE_VK_WEBARCHIVE,
            'batch_ref' => 'vk_webarchive:paragraph-test',
            'source_label' => 'VK webarchive paragraph test',
            'source_path' => 'reviews/sources/vk/test.webarchive',
            'status' => ReviewImportBatch::STATUS_PENDING,
            'records_total' => 1,
            'records_created' => 0,
            'records_updated' => 0,
        ]);
        $text = 'Я очень рада, что нашла эту онлайн-школу. Перед экзаменами всегда наступает паника, нужно повторить весь материал за год по четырём предметам. Здесь я нашла структурированный и наглядный материал, очень нравится то, что объяснение сразу на препаратах. Преподаватель прекрасный, действительно интересно слушать. Боялась, что будет неполный материал или с ошибками, но нет, я как будто слушаю своего преподавателя в аудитории. Также было очень удобно оплачивать частями. Отдельное спасибо за методички.';

        $service = app(ExternalReviewImportService::class);
        $service->importRecord($batch, [
            'confidence' => 'high',
            'display_name' => 'Мария',
            'evidence' => [],
            'external_written_at' => '2026-03-12T09:36:00+03:00',
            'is_public' => false,
            'raw_payload' => ['source_file' => 'test.webarchive'],
            'source_label' => 'VK webarchive paragraph test',
            'source_ref' => 'vk_webarchive:paragraph-test:001',
            'source_url' => null,
            'text' => $text,
        ]);

        $review = Review::query()->firstOrFail();
        $source = $review->sources()->firstOrFail();

        $this->assertStringContainsString("\n\nБоялась", $review->text);
        $this->assertSame($text, $source->raw_text);
    }

    public function test_external_reviews_import_reuses_evidence_rows_by_sha_type_and_source(): void
    {
        $batch = ReviewImportBatch::query()->create([
            'source_type' => ReviewSource::TYPE_VK_WEBARCHIVE,
            'batch_ref' => 'vk_webarchive:dedupe-evidence-test',
            'source_label' => 'VK webarchive dedupe',
            'source_path' => 'reviews/sources/vk/test.webarchive',
            'status' => ReviewImportBatch::STATUS_PENDING,
            'records_total' => 1,
            'records_created' => 0,
            'records_updated' => 0,
        ]);

        $service = app(ExternalReviewImportService::class);
        $record = [
            'confidence' => 'high',
            'display_name' => 'Наталья',
            'evidence' => [
                [
                    'type' => 'image',
                    'file_path' => 'reviews/sources/vk/test-image.webp',
                    'provenance' => 'vk_webarchive',
                    'sha256' => hash('sha256', 'shared-media'),
                    'is_public' => true,
                    'metadata' => ['local_source_name' => 'test.webarchive'],
                ],
            ],
            'external_author_name' => 'Наталья',
            'external_id' => null,
            'external_written_at' => '2026-03-12T09:36:00+03:00',
            'is_public' => false,
            'raw_payload' => ['source_file' => 'test.webarchive'],
            'source_label' => 'VK webarchive test',
            'source_ref' => 'vk_webarchive:dedupe-evidence-test:001',
            'source_url' => null,
            'text' => 'Отзыв для проверки дедупликации вложений.',
        ];

        $service->importRecord($batch, $record);
        $service->importRecord(
            $batch,
            $record,
        );

        $this->assertDatabaseCount('review_evidence', 1);

        $imageRows = ReviewEvidence::query()->where('sha256', hash('sha256', 'shared-media'))->where('type', 'image')->get();
        $this->assertCount(1, $imageRows);

        foreach ($imageRows as $row) {
            $this->assertSame('image', $row->type);
            $this->assertSame('vk_webarchive', $row->provenance);
            $this->assertTrue($row->is_public);
        }
    }

    public function test_external_reviews_import_updates_existing_high_confidence_source_review_text(): void
    {
        $batch = ReviewImportBatch::query()->create([
            'source_type' => ReviewSource::TYPE_LEGACY_SITE,
            'batch_ref' => 'legacy_site:refresh-existing-source',
            'source_label' => 'Legacy refresh existing source',
            'status' => ReviewImportBatch::STATUS_PENDING,
            'records_total' => 1,
            'records_created' => 0,
            'records_updated' => 0,
        ]);

        $review = Review::query()->create([
            'text' => 'Старый OCR-мусор. Ваше мнение о приобретенном курсе.',
            'display_name' => 'Анонимный автор',
            'status' => Review::STATUS_PUBLISHED,
            'moderation_status' => Review::MODERATION_APPROVED,
            'publication_status' => Review::PUBLICATION_PUBLISHED,
            'dedupe_status' => Review::DEDUPE_UNIQUE,
            'origin_type' => Review::ORIGIN_LEGACY_IMPORT,
            'source' => 'legacy_ocr',
            'published_at' => now()->subDay(),
        ]);

        $review->sources()->create([
            'source_type' => ReviewSource::TYPE_LEGACY_SITE,
            'source_ref' => 'legacy_site:refresh-existing-source:001',
            'raw_text' => 'Старый OCR-мусор. Ваше мнение о приобретенном курсе.',
            'is_public' => false,
        ]);

        $result = app(ExternalReviewImportService::class)->importRecord($batch, [
            'confidence' => 'high',
            'display_name' => 'Елена',
            'is_public' => false,
            'raw_payload' => ['manual_text_reviewed' => true],
            'source_label' => 'Legacy refresh existing source',
            'source_ref' => 'legacy_site:refresh-existing-source:001',
            'source_url' => null,
            'text' => 'Чистый текст отзыва после ручной вычитки.',
        ]);

        $this->assertSame('updated_source', $result['action']);

        $review->refresh();
        $this->assertSame('Чистый текст отзыва после ручной вычитки.', $review->text);
        $this->assertSame('Елена', $review->display_name);
        $this->assertSame(Review::STATUS_PUBLISHED, $review->status);
        $this->assertSame(Review::PUBLICATION_PUBLISHED, $review->publication_status);
        $this->assertNotNull($review->published_at);
        $this->assertSame('Чистый текст отзыва после ручной вычитки.', $review->sources()->firstOrFail()->raw_text);
    }

    public function test_external_reviews_import_does_not_replace_clean_text_with_ocr_artifacts(): void
    {
        $batch = ReviewImportBatch::query()->create([
            'source_type' => ReviewSource::TYPE_VK_WEBARCHIVE,
            'batch_ref' => 'vk_webarchive:keep-clean-text',
            'source_label' => 'VK keep clean text',
            'status' => ReviewImportBatch::STATUS_PENDING,
            'records_total' => 1,
            'records_created' => 0,
            'records_updated' => 0,
        ]);

        $cleanText = 'Брала несколько курсов по гистологии. Конспекты были удобными, понятными и помогли закрыть пробелы перед экзаменом.';
        $dirtyText = '1. Брала несколько по гистологии. Потому что очень давно на слышала. Картинки заболеваний которыетсоазу запоминаются. Приходиться выбрать предмет, зимней сессий, это был так интересно.';

        $review = Review::query()->create([
            'text' => $cleanText,
            'display_name' => 'Татьяна Марфенко',
            'status' => Review::STATUS_PUBLISHED,
            'moderation_status' => Review::MODERATION_APPROVED,
            'publication_status' => Review::PUBLICATION_PUBLISHED,
            'dedupe_status' => Review::DEDUPE_UNIQUE,
            'origin_type' => Review::ORIGIN_LEGACY_IMPORT,
            'source' => 'legacy_ocr',
            'published_at' => now()->subDay(),
        ]);

        $review->sources()->create([
            'source_type' => ReviewSource::TYPE_VK_WEBARCHIVE,
            'source_ref' => 'vk_webarchive:keep-clean-text:001',
            'raw_text' => $cleanText,
            'is_public' => true,
        ]);

        $result = app(ExternalReviewImportService::class)->importRecord($batch, [
            'confidence' => 'high',
            'display_name' => 'Татьяна Марфенко',
            'is_public' => true,
            'raw_payload' => ['source_file' => 'reviews.webarchive'],
            'source_label' => 'VK keep clean text',
            'source_ref' => 'vk_webarchive:keep-clean-text:001',
            'source_url' => 'https://example.invalid/demo',
            'text' => $dirtyText,
        ]);

        $this->assertSame('updated_source', $result['action']);

        $review->refresh();
        $this->assertSame($cleanText, $review->text);
        $this->assertSame($dirtyText, $review->sources()->firstOrFail()->raw_text);
    }

    public function test_external_reviews_import_keeps_clean_text_for_near_duplicate_yandex_review(): void
    {
        $batch = ReviewImportBatch::query()->create([
            'source_type' => ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE,
            'batch_ref' => 'yandex_maps_webarchive:keep-clean-text',
            'source_label' => 'Yandex keep clean text',
            'status' => ReviewImportBatch::STATUS_PENDING,
            'records_total' => 1,
            'records_created' => 0,
            'records_updated' => 0,
        ]);

        $cleanText = 'Курс по гормональной регуляции метаболизма превзошёл мои ожидания! Он идеально подошёл для углубления понимания эндокринных механизмов, регулирующих метаболизм. В курсе происходит комплексный охват темы: от базовых принципов до сложных аспектов, а также качественные материалы — лекции в формате видео и PDF-конспекты.';
        $dirtyText = 'Курс по гормональной регуляции метаболизма превзошёл мои ожидания! Он идеально подошёл для углубление понимания эндокринных механизмов, регулирующих метаболизм. В курсе происходит комплексный охват темы - от базовых принципов до сложных аспектов, а также качественные материалы - лекции в формате видео + Pdf-конспекты.';

        $review = Review::query()->create([
            'text' => $cleanText,
            'display_name' => 'Егор Агров',
            'status' => Review::STATUS_PUBLISHED,
            'moderation_status' => Review::MODERATION_APPROVED,
            'publication_status' => Review::PUBLICATION_PUBLISHED,
            'dedupe_status' => Review::DEDUPE_UNIQUE,
            'origin_type' => Review::ORIGIN_LEGACY_IMPORT,
            'source' => 'legacy_ocr',
            'published_at' => now()->subDay(),
        ]);

        $review->sources()->create([
            'source_type' => ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE,
            'source_ref' => 'yandex_maps_webarchive:keep-clean-text:001',
            'raw_text' => $cleanText,
            'is_public' => true,
        ]);

        app(ExternalReviewImportService::class)->importRecord($batch, [
            'confidence' => 'high',
            'display_name' => 'Егор Агров',
            'is_public' => true,
            'raw_payload' => ['source_file' => 'yandex.webarchive'],
            'source_label' => 'Yandex keep clean text',
            'source_ref' => 'yandex_maps_webarchive:keep-clean-text:001',
            'source_url' => 'https://example.invalid/demo',
            'text' => $dirtyText,
        ]);

        $review->refresh();
        $this->assertSame($cleanText, $review->text);
        $this->assertSame($dirtyText, $review->sources()->firstOrFail()->raw_text);
    }

    public function test_external_reviews_import_saves_evidence_image_and_screenshot_with_different_types(): void
    {
        $batch = ReviewImportBatch::query()->create([
            'source_type' => ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE,
            'batch_ref' => 'yandex_maps_webarchive:image-screenshot-dedupe',
            'source_label' => 'Yandex image + screenshot test',
            'source_path' => 'reviews/sources/yandex/test.webarchive',
            'status' => ReviewImportBatch::STATUS_PENDING,
            'records_total' => 1,
            'records_created' => 0,
            'records_updated' => 0,
        ]);

        $service = app(ExternalReviewImportService::class);
        $sharedSha = hash('sha256', 'shared-evidence-type');

        $record = [
            'confidence' => 'high',
            'display_name' => 'Ирина',
            'evidence' => [
                [
                    'type' => 'image',
                    'file_path' => 'reviews/sources/yandex/test-image.webp',
                    'provenance' => 'yandex_maps_webarchive',
                    'sha256' => $sharedSha,
                    'is_public' => true,
                    'metadata' => ['local_source_name' => 'test.webarchive'],
                ],
                [
                    'type' => 'screenshot',
                    'file_path' => 'reviews/sources/yandex/test-shot.webp',
                    'provenance' => 'yandex_maps_webarchive',
                    'sha256' => $sharedSha,
                    'is_public' => true,
                    'metadata' => ['local_source_name' => 'test.webarchive'],
                ],
            ],
            'external_author_name' => 'Ирина',
            'external_id' => 'yandex-screenshot-1',
            'external_written_at' => '2026-02-14T15:15:00+03:00',
            'is_public' => false,
            'raw_payload' => ['source_file' => 'test.webarchive'],
            'source_label' => 'Yandex test',
            'source_ref' => 'yandex_maps_webarchive:image-screenshot-test:001',
            'source_url' => null,
            'text' => 'Отзыв с двумя типами вложений.',
        ];

        $service->importRecord($batch, $record);

        $rows = ReviewEvidence::query()
            ->where('sha256', $sharedSha)
            ->orderBy('type')
            ->get()
            ->pluck('type', 'type')
            ->keys();

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->contains('image'));
        $this->assertTrue($rows->contains('screenshot'));
    }

    public function test_external_reviews_import_links_confident_duplicates_without_creating_review(): void
    {
        $review = Review::query()->create([
            'text' => 'Материал понятный и структурированный для экзамена.',
            'display_name' => 'Катя',
            'status' => Review::STATUS_PUBLISHED,
            'moderation_status' => Review::MODERATION_APPROVED,
            'publication_status' => Review::PUBLICATION_PUBLISHED,
            'dedupe_status' => Review::DEDUPE_UNIQUE,
            'origin_type' => Review::ORIGIN_LEGACY_IMPORT,
            'source' => 'legacy_ocr',
            'published_at' => now()->subDay(),
        ]);

        $batch = ReviewImportBatch::query()->create([
            'source_type' => ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE,
            'batch_ref' => 'yandex_maps_webarchive:test-batch',
            'source_label' => 'Yandex test',
            'source_path' => 'reviews/sources/yandex/test.webarchive',
            'status' => ReviewImportBatch::STATUS_PENDING,
            'records_total' => 1,
            'records_created' => 0,
            'records_updated' => 0,
        ]);

        $service = app(ExternalReviewImportService::class);
        $result = $service->importRecord($batch, [
            'confidence' => 'high',
            'display_name' => 'Катя',
            'evidence' => [
                [
                    'type' => 'webarchive',
                    'file_path' => 'reviews/sources/yandex/test.webarchive',
                    'provenance' => 'yandex_maps_webarchive',
                    'sha256' => hash('sha256', 'duplicate-source'),
                    'metadata' => ['local_source_name' => 'test.webarchive'],
                ],
            ],
            'external_author_name' => 'Катя',
            'external_id' => 'yandex-duplicate-1',
            'external_written_at' => '2026-05-01T09:00:00+00:00',
            'is_public' => false,
            'raw_payload' => ['source_file' => 'test.webarchive'],
            'source_label' => 'Yandex test',
            'source_ref' => 'yandex_maps_webarchive:test-batch:001',
            'source_url' => null,
            'text' => 'Материал понятный и структурированный для экзамена.',
        ]);

        $this->assertSame('linked_duplicate', $result['action']);
        $this->assertDatabaseCount('reviews', 1);
        $this->assertSame(1, $review->fresh()->sources()->count());
        $source = $review->fresh()->sources()->firstOrFail();
        $this->assertSame('Материал понятный и структурированный для экзамена.', $source->raw_text);
        $this->assertDatabaseHas('review_evidence', [
            'review_id' => $review->id,
            'review_source_id' => $source->id,
            'type' => 'webarchive',
        ]);
        $this->assertSame(1, ReviewEvidence::query()->count());
    }

    public function test_external_reviews_import_keeps_low_confidence_records_pending(): void
    {
        $batch = ReviewImportBatch::query()->create([
            'source_type' => ReviewSource::TYPE_VK_WEBARCHIVE,
            'batch_ref' => 'vk_webarchive:pending-batch',
            'source_label' => 'VK pending',
            'source_path' => 'reviews/sources/vk/pending.webarchive',
            'status' => ReviewImportBatch::STATUS_PENDING,
            'records_total' => 1,
            'records_created' => 0,
            'records_updated' => 0,
        ]);

        $service = app(ExternalReviewImportService::class);
        $result = $service->importRecord($batch, [
            'confidence' => 'low',
            'display_name' => 'Алина',
            'evidence' => [
                [
                    'type' => 'webarchive',
                    'file_path' => 'reviews/sources/vk/pending.webarchive',
                    'provenance' => 'vk_webarchive',
                    'sha256' => hash('sha256', 'pending-source'),
                    'metadata' => ['local_source_name' => 'pending.webarchive'],
                ],
            ],
            'external_author_name' => 'Алина',
            'external_id' => null,
            'external_written_at' => '2026-04-01T11:00:00+03:00',
            'is_public' => false,
            'raw_payload' => ['source_file' => 'pending.webarchive'],
            'source_label' => 'VK pending',
            'source_ref' => 'vk_webarchive:pending-batch:001',
            'source_url' => null,
            'text' => 'Пока не готова рекомендовать курс без ручной проверки.',
        ]);

        $this->assertSame('pending_review', $result['action']);

        $review = Review::query()->firstOrFail();
        $this->assertSame(Review::STATUS_DRAFT, $review->status);
        $this->assertSame(Review::MODERATION_NEEDS_REVIEW, $review->moderation_status);
        $this->assertSame(Review::PUBLICATION_DRAFT, $review->publication_status);
        $this->assertSame(Review::DEDUPE_UNCHECKED, $review->dedupe_status);
        $this->assertNull($review->published_at);

        $this->getJson('/api/v1/reviews')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_external_reviews_seeder_unpublishes_excluded_sources_and_reviews(): void
    {
        $review = Review::query()->create([
            'text' => 'Отзыв, который должен быть снят с публикации через excluded source_ref.',
            'display_name' => 'Мария',
            'status' => Review::STATUS_PUBLISHED,
            'moderation_status' => Review::MODERATION_APPROVED,
            'publication_status' => Review::PUBLICATION_PUBLISHED,
            'dedupe_status' => Review::DEDUPE_UNIQUE,
            'origin_type' => Review::ORIGIN_LEGACY_IMPORT,
            'source' => 'legacy_ocr',
            'published_at' => now()->subDay(),
            'reviewed_at' => now()->subDay(),
        ]);

        $source = $review->sources()->create([
            'source_type' => ReviewSource::TYPE_VK_WEBARCHIVE,
            'source_ref' => 'vk_webarchive:excluded-source-test:001',
            'is_public' => true,
        ]);

        $method = new \ReflectionMethod(ExternalReviewsSeeder::class, 'applyExcludedRecords');
        $method->setAccessible(true);

        $unpublished = $method->invoke(new ExternalReviewsSeeder, ReviewSource::TYPE_VK_WEBARCHIVE, [
            ['source_ref' => 'vk_webarchive:excluded-source-test:001'],
        ]);

        $this->assertSame(1, $unpublished);
        $this->assertFalse($source->fresh()->is_public);

        $review->refresh();
        $this->assertSame(Review::STATUS_DRAFT, $review->status);
        $this->assertSame(Review::MODERATION_NEEDS_REVIEW, $review->moderation_status);
        $this->assertSame(Review::PUBLICATION_DRAFT, $review->publication_status);
        $this->assertSame(Review::DEDUPE_UNCHECKED, $review->dedupe_status);
        $this->assertNull($review->published_at);
        $this->assertNull($review->reviewed_at);
        $this->assertFalse(Review::published()->whereKey($review->id)->exists());
    }

    public function test_external_reviews_seeder_is_idempotent(): void
    {
        $this->seed(ExternalReviewsSeeder::class);

        $firstCounts = [
            'reviews' => Review::query()->count(),
            'sources' => ReviewSource::query()->count(),
            'evidence' => ReviewEvidence::query()->count(),
            'batches' => ReviewImportBatch::query()->count(),
        ];

        $this->seed(ExternalReviewsSeeder::class);

        $this->assertSame($firstCounts['reviews'], Review::query()->count());
        $this->assertSame($firstCounts['sources'], ReviewSource::query()->count());
        $this->assertSame($firstCounts['evidence'], ReviewEvidence::query()->count());
        $this->assertSame($firstCounts['batches'], ReviewImportBatch::query()->count());
    }
}
