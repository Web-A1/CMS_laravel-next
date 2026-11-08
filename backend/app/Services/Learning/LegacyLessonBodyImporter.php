<?php

namespace App\Services\Learning;

use App\Models\LegacyImportMapping;
use App\Models\Lesson;
use App\Models\LessonLearningContent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LegacyLessonBodyImporter
{
    public const BATCH_KEY = 'lesson_body_v1';

    public const SOURCE_TABLE_PLATFORM_PAGE = 'platform_page';

    public const TARGET_DOMAIN = 'learning_lesson_body';

    public const TARGET_TYPE = 'lesson_learning_content';

    private const LEGACY_PROTECTED_ASSET_REFERENCE_BLOCK = 'legacy_protected_asset_reference';

    private const LEGACY_SHORTCODE_PATTERN = '/\{(file|video)::([1-9]\d*)\}/i';

    private const LEGACY_EMPTY_FILE_PLACEHOLDER_PATTERN = '/\{\s*file\s*::\s*-\s*\}/i';

    private const LEGACY_SHORTCODE_REMAINDER_PATTERN = '/\{\s*(?:file|video)\s*::[^}]*\}/i';

    public function __construct(
        private readonly LessonBodyContract $contract,
    ) {}

    /**
     * @param  array{id: int|string, content?: string|null, product_id?: int|string|null}  $source
     */
    public function importPlatformPage(array $source, bool $includeInactive = false): LegacyImportMapping
    {
        return DB::transaction(function () use ($source, $includeInactive): LegacyImportMapping {
            $preview = $this->previewPlatformPage($source, $includeInactive);

            $sourceId = $preview['source_id'];
            $sourceHash = $preview['source_fingerprint_hash'];

            if ($preview['action'] === 'manual_review') {
                return $this->recordManualReview(
                    sourceId: $sourceId,
                    sourceScopeType: $preview['source_scope_type'],
                    sourceScopeId: $preview['source_scope_id'],
                    sourceHash: $sourceHash,
                    idempotencyKey: $preview['idempotency_key'],
                    reason: $preview['manual_review_reason'],
                    targetId: $preview['target_content_id'],
                    metadata: [
                        'matched_lesson_ids' => $preview['matched_lesson_ids'],
                        'source_product_id' => $preview['source_product_id'],
                        'stale_imported_draft' => $preview['stale_imported_draft'],
                        'stale_imported_draft_content_ids' => $preview['stale_imported_draft_content_ids'],
                        'stale_imported_draft_lesson_ids' => $preview['stale_imported_draft_lesson_ids'],
                    ],
                );
            }

            $content = LessonLearningContent::query()->updateOrCreate(
                ['lesson_id' => $preview['target_lesson_id']],
                [
                    'status' => $preview['existing_content_status'] ?? LessonLearningContent::STATUS_DRAFT,
                    'format' => LessonLearningContent::FORMAT_LESSON_BLOCKS_V1,
                    'version' => $preview['existing_content_version'] ?? 1,
                    'body' => $preview['body'],
                    'published_at' => $preview['existing_published_at'],
                    'source_table' => self::SOURCE_TABLE_PLATFORM_PAGE,
                    'source_id' => $sourceId,
                    'source_fingerprint_hash' => $sourceHash,
                    'import_status' => LessonLearningContent::IMPORT_STATUS_IMPORTED,
                    'manual_review_reason' => null,
                    'metadata' => [
                        'legacy_page_id' => $sourceId,
                        ...$this->contentSourceMetadata($preview['source_product_id'], $preview['source_scope_type'], $preview['source_scope_id']),
                    ],
                ],
            );

            return $this->recordMapped(
                sourceId: $sourceId,
                sourceScopeType: $preview['source_scope_type'],
                sourceScopeId: $preview['source_scope_id'],
                sourceHash: $sourceHash,
                idempotencyKey: $preview['idempotency_key'],
                targetId: (int) $content->id,
                metadata: [
                    'matched_lesson_ids' => $preview['matched_lesson_ids'],
                    'source_product_id' => $preview['source_product_id'],
                ],
            );
        });
    }

    /**
     * @param  array{id: int|string, content?: string|null, product_id?: int|string|null}  $source
     * @return array{
     *     source_table: string,
     *     source_id: string,
     *     source_product_id: int|null,
     *     source_scope_type: string,
     *     source_scope_id: string,
     *     source_fingerprint_hash: string,
     *     idempotency_key: string,
     *     action: 'import_draft'|'manual_review',
     *     mapping_status: string,
     *     validation_status: string,
     *     manual_review_reason: string|null,
     *     matched_lesson_ids: array<int, int>,
     *     target_lesson_id: int|null,
     *     target_content_id: int|null,
     *     existing_content_status: string|null,
     *     existing_content_version: int|null,
     *     existing_published_at: mixed,
     *     body: array<string, mixed>|null,
     *     body_blocks_count: int,
     *     stale_imported_draft: bool,
     *     stale_imported_draft_content_ids: array<int, int>,
     *     stale_imported_draft_lesson_ids: array<int, int>
     * }
     */
    public function previewPlatformPage(array $source, bool $includeInactive = false): array
    {
        $sourceId = trim((string) $source['id']);
        $sourceProductId = $this->sourceProductId($source);
        $sourceScopeType = $this->sourceScopeType($sourceProductId);
        $sourceScopeId = $this->sourceScopeId($sourceProductId);
        $sourceContent = (string) ($source['content'] ?? '');
        $sourceHash = $this->fingerprint($source);
        $lessons = $this->lessonsForSource($sourceId, $sourceProductId, $includeInactive);
        $matchedLessonIds = $lessons->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $staleImportedDrafts = $this->staleImportedDraftsForSource($sourceId, $sourceProductId);

        if ($lessons->count() !== 1) {
            return $this->previewManualReview(
                sourceId: $sourceId,
                sourceProductId: $sourceProductId,
                sourceScopeType: $sourceScopeType,
                sourceScopeId: $sourceScopeId,
                sourceHash: $sourceHash,
                idempotencyKey: $this->idempotencyKey($sourceId, $sourceProductId),
                reason: $this->matchFailureReason($sourceProductId, $lessons->count()),
                matchedLessonIds: $matchedLessonIds,
                staleImportedDrafts: $staleImportedDrafts,
            );
        }

        $lesson = $lessons->first();
        $existingContent = $lesson->learningContent()->first();

        if ($existingContent instanceof LessonLearningContent && $existingContent->status === LessonLearningContent::STATUS_PUBLISHED) {
            return $this->previewManualReview(
                sourceId: $sourceId,
                sourceProductId: $sourceProductId,
                sourceScopeType: $sourceScopeType,
                sourceScopeId: $sourceScopeId,
                sourceHash: $sourceHash,
                idempotencyKey: $this->idempotencyKey($sourceId, $sourceProductId),
                reason: 'published_content_exists',
                matchedLessonIds: $matchedLessonIds,
                staleImportedDrafts: $staleImportedDrafts,
                targetLessonId: (int) $lesson->id,
                targetContentId: (int) $existingContent->id,
                existingContent: $existingContent,
            );
        }

        if ($existingContent instanceof LessonLearningContent && $this->isManualContent($existingContent, $sourceId)) {
            return $this->previewManualReview(
                sourceId: $sourceId,
                sourceProductId: $sourceProductId,
                sourceScopeType: $sourceScopeType,
                sourceScopeId: $sourceScopeId,
                sourceHash: $sourceHash,
                idempotencyKey: $this->idempotencyKey($sourceId, $sourceProductId),
                reason: 'manual_content_exists',
                matchedLessonIds: $matchedLessonIds,
                staleImportedDrafts: $staleImportedDrafts,
                targetLessonId: (int) $lesson->id,
                targetContentId: (int) $existingContent->id,
                existingContent: $existingContent,
            );
        }

        $body = $this->legacyContentToBlocks($sourceContent);

        if ($body === null) {
            return $this->previewManualReview(
                sourceId: $sourceId,
                sourceProductId: $sourceProductId,
                sourceScopeType: $sourceScopeType,
                sourceScopeId: $sourceScopeId,
                sourceHash: $sourceHash,
                idempotencyKey: $this->idempotencyKey($sourceId, $sourceProductId),
                reason: $this->containsUnsupportedLegacyShortcode($sourceContent) ? 'unsupported_legacy_shortcode' : 'empty_legacy_body',
                matchedLessonIds: $matchedLessonIds,
                staleImportedDrafts: $staleImportedDrafts,
                targetLessonId: (int) $lesson->id,
                targetContentId: $existingContent?->id === null ? null : (int) $existingContent->id,
                existingContent: $existingContent,
            );
        }

        return [
            'source_table' => self::SOURCE_TABLE_PLATFORM_PAGE,
            'source_id' => $sourceId,
            'source_product_id' => $sourceProductId,
            'source_scope_type' => $sourceScopeType,
            'source_scope_id' => $sourceScopeId,
            'source_fingerprint_hash' => $sourceHash,
            'idempotency_key' => $this->idempotencyKey($sourceId, $sourceProductId),
            'action' => 'import_draft',
            'mapping_status' => LegacyImportMapping::MAPPING_MAPPED,
            'validation_status' => LegacyImportMapping::VALIDATION_PASSED,
            'manual_review_reason' => null,
            'matched_lesson_ids' => $matchedLessonIds,
            'target_lesson_id' => (int) $lesson->id,
            'target_content_id' => $existingContent?->id === null ? null : (int) $existingContent->id,
            'existing_content_status' => $existingContent?->status,
            'existing_content_version' => $existingContent?->version,
            'existing_published_at' => $existingContent?->published_at,
            'body' => $body,
            'body_blocks_count' => count($body['blocks']),
            'stale_imported_draft' => false,
            'stale_imported_draft_content_ids' => [],
            'stale_imported_draft_lesson_ids' => [],
        ];
    }

    /**
     * @return Collection<int, Lesson>
     */
    private function lessonsForSource(string $sourceId, ?int $sourceProductId, bool $includeInactive): Collection
    {
        if ($sourceProductId !== null) {
            return $this->lessonsQuery($includeInactive)
                ->where('legacy_product_id', $sourceProductId)
                ->orderBy('id')
                ->get()
                ->values();
        }

        return $this->lessonsQuery(false)
            ->get()
            ->filter(fn (Lesson $lesson): bool => (string) data_get($lesson->metadata, 'legacy_page_id') === $sourceId)
            ->values();
    }

    private function lessonsQuery(bool $includeInactive)
    {
        $query = Lesson::query()->with('learningContent');

        if ($includeInactive) {
            return $query;
        }

        return $query
            ->where('is_active', true)
            ->whereHas('course', fn ($query) => $query
                ->where('is_active', true)
                ->whereHas('subject', fn ($subjectQuery) => $subjectQuery->where('is_active', true)));
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function sourceProductId(array $source): ?int
    {
        $productId = $source['product_id'] ?? $source['productId'] ?? null;
        if ($productId === null) {
            return null;
        }

        if (is_int($productId) && $productId > 0) {
            return $productId;
        }

        if (is_string($productId) && ctype_digit(trim($productId)) && (int) trim($productId) > 0) {
            return (int) trim($productId);
        }

        throw new RuntimeException('Lesson body source product_id must be a positive integer.');
    }

    private function matchFailureReason(?int $sourceProductId, int $matchedLessonCount): string
    {
        if ($sourceProductId !== null) {
            return $matchedLessonCount === 0 ? 'missing_legacy_product_id' : 'ambiguous_legacy_product_id';
        }

        return $matchedLessonCount === 0 ? 'missing_legacy_page_id' : 'ambiguous_legacy_page_id';
    }

    /**
     * @return Collection<int, LessonLearningContent>
     */
    private function staleImportedDraftsForSource(string $sourceId, ?int $sourceProductId): Collection
    {
        $query = LessonLearningContent::query()
            ->where('source_table', self::SOURCE_TABLE_PLATFORM_PAGE)
            ->where('source_id', $sourceId)
            ->where('status', LessonLearningContent::STATUS_DRAFT)
            ->where('import_status', LessonLearningContent::IMPORT_STATUS_IMPORTED);

        if ($sourceProductId !== null) {
            $query->whereHas('lesson', fn ($query) => $query->where('legacy_product_id', $sourceProductId));
        }

        return $query->orderBy('id')->get();
    }

    private function isManualContent(LessonLearningContent $content, string $sourceId): bool
    {
        if ($content->source_table === self::SOURCE_TABLE_PLATFORM_PAGE && $content->source_id === $sourceId) {
            return false;
        }

        return $content->import_status === LessonLearningContent::IMPORT_STATUS_MANUAL
            || $content->source_table === null
            || $content->source_id === null;
    }

    /**
     * @param  array<int, int>  $matchedLessonIds
     * @param  Collection<int, LessonLearningContent>|null  $staleImportedDrafts
     * @return array{
     *     source_table: string,
     *     source_id: string,
     *     source_product_id: int|null,
     *     source_scope_type: string,
     *     source_scope_id: string,
     *     source_fingerprint_hash: string,
     *     idempotency_key: string,
     *     action: 'manual_review',
     *     mapping_status: string,
     *     validation_status: string,
     *     manual_review_reason: string,
     *     matched_lesson_ids: array<int, int>,
     *     target_lesson_id: int|null,
     *     target_content_id: int|null,
     *     existing_content_status: string|null,
     *     existing_content_version: int|null,
     *     existing_published_at: mixed,
     *     body: null,
     *     body_blocks_count: 0,
     *     stale_imported_draft: bool,
     *     stale_imported_draft_content_ids: array<int, int>,
     *     stale_imported_draft_lesson_ids: array<int, int>
     * }
     */
    private function previewManualReview(
        string $sourceId,
        ?int $sourceProductId,
        string $sourceScopeType,
        string $sourceScopeId,
        string $sourceHash,
        string $idempotencyKey,
        string $reason,
        array $matchedLessonIds,
        ?Collection $staleImportedDrafts = null,
        ?int $targetLessonId = null,
        ?int $targetContentId = null,
        ?LessonLearningContent $existingContent = null,
    ): array {
        $staleImportedDrafts ??= collect();
        $staleContentIds = $staleImportedDrafts
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
        $staleLessonIds = $staleImportedDrafts
            ->pluck('lesson_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return [
            'source_table' => self::SOURCE_TABLE_PLATFORM_PAGE,
            'source_id' => $sourceId,
            'source_product_id' => $sourceProductId,
            'source_scope_type' => $sourceScopeType,
            'source_scope_id' => $sourceScopeId,
            'source_fingerprint_hash' => $sourceHash,
            'idempotency_key' => $idempotencyKey,
            'action' => 'manual_review',
            'mapping_status' => LegacyImportMapping::MAPPING_BLOCKED,
            'validation_status' => LegacyImportMapping::VALIDATION_MANUAL_REVIEW,
            'manual_review_reason' => $reason,
            'matched_lesson_ids' => $matchedLessonIds,
            'target_lesson_id' => $targetLessonId,
            'target_content_id' => $targetContentId,
            'existing_content_status' => $existingContent?->status,
            'existing_content_version' => $existingContent?->version,
            'existing_published_at' => $existingContent?->published_at,
            'body' => null,
            'body_blocks_count' => 0,
            'stale_imported_draft' => $staleContentIds !== [],
            'stale_imported_draft_content_ids' => $staleContentIds,
            'stale_imported_draft_lesson_ids' => $staleLessonIds,
        ];
    }

    /**
     * @return array{blocks: array<int, array<string, mixed>>, toc: array<int, array{id: string, title: string}>}|null
     */
    private function legacyContentToBlocks(string $content): ?array
    {
        $text = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<(br|br\s*\/|\/p|\/div|\/h[1-6])>/i', "\n\n", $text) ?? $text;
        $text = trim(strip_tags($text));
        $text = mb_scrub($text, 'UTF-8');
        $text = $this->removeEmptyLegacyFilePlaceholders($text);

        if ($text === '' || $this->containsUnsupportedLegacyShortcode($text)) {
            return null;
        }

        $segments = preg_split('/\R{2,}/', $text) ?: [];
        $blocks = [];

        foreach ($segments as $segment) {
            foreach ($this->legacySegmentToBlocks($segment) as $block) {
                $blocks[] = $block;
            }
        }

        if ($blocks === []) {
            return null;
        }

        return [
            'blocks' => $blocks,
            'toc' => [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function legacySegmentToBlocks(string $segment): array
    {
        $blocks = [];
        $offset = 0;
        preg_match_all(self::LEGACY_SHORTCODE_PATTERN, $segment, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => [$shortcode, $position]) {
            $textBeforeShortcode = substr($segment, $offset, $position - $offset);
            $paragraph = $this->normalizeLegacyParagraph($textBeforeShortcode);

            if ($paragraph !== '') {
                $blocks[] = [
                    'type' => 'paragraph',
                    'text' => $paragraph,
                ];
            }

            $blocks[] = [
                'type' => self::LEGACY_PROTECTED_ASSET_REFERENCE_BLOCK,
                'assetKind' => $this->legacyAssetKind((string) $matches[1][$index][0]),
                'legacyAssetId' => (int) $matches[2][$index][0],
                'origin' => 'legacy_shortcode',
            ];

            $offset = $position + strlen($shortcode);
        }

        $tail = $this->normalizeLegacyParagraph(substr($segment, $offset));
        if ($tail !== '') {
            $blocks[] = [
                'type' => 'paragraph',
                'text' => $tail,
            ];
        }

        return $blocks;
    }

    private function normalizeLegacyParagraph(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function legacyAssetKind(string $shortcodeType): string
    {
        return strtolower($shortcodeType) === 'video' ? 'legacy_video' : 'legacy_file';
    }

    private function containsUnsupportedLegacyShortcode(string $value): bool
    {
        $withoutSupportedShortcodes = preg_replace(self::LEGACY_SHORTCODE_PATTERN, '', $value) ?? $value;
        $withoutSupportedShortcodes = $this->removeEmptyLegacyFilePlaceholders($withoutSupportedShortcodes);

        return preg_match(self::LEGACY_SHORTCODE_REMAINDER_PATTERN, $withoutSupportedShortcodes) === 1
            || $this->contract->containsBlockedLegacyReference($withoutSupportedShortcodes);
    }

    private function removeEmptyLegacyFilePlaceholders(string $value): string
    {
        return preg_replace(self::LEGACY_EMPTY_FILE_PLACEHOLDER_PATTERN, '', $value) ?? $value;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordManualReview(
        string $sourceId,
        string $sourceScopeType,
        string $sourceScopeId,
        string $sourceHash,
        string $idempotencyKey,
        string $reason,
        ?int $targetId = null,
        array $metadata = [],
    ): LegacyImportMapping {
        return LegacyImportMapping::query()->updateOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'batch_key' => self::BATCH_KEY,
                'source_table' => self::SOURCE_TABLE_PLATFORM_PAGE,
                'source_id' => $sourceId,
                'source_scope_type' => $sourceScopeType,
                'source_scope_id' => $sourceScopeId,
                'target_domain' => self::TARGET_DOMAIN,
                'target_type' => $targetId === null ? null : self::TARGET_TYPE,
                'target_id' => $targetId,
                'mapping_status' => LegacyImportMapping::MAPPING_BLOCKED,
                'validation_status' => LegacyImportMapping::VALIDATION_MANUAL_REVIEW,
                'source_fingerprint_hash' => $sourceHash,
                'manual_review_reason' => $reason,
                'errors' => null,
                'metadata' => $metadata,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordMapped(
        string $sourceId,
        string $sourceScopeType,
        string $sourceScopeId,
        string $sourceHash,
        string $idempotencyKey,
        int $targetId,
        array $metadata = [],
    ): LegacyImportMapping {
        return LegacyImportMapping::query()->updateOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'batch_key' => self::BATCH_KEY,
                'source_table' => self::SOURCE_TABLE_PLATFORM_PAGE,
                'source_id' => $sourceId,
                'source_scope_type' => $sourceScopeType,
                'source_scope_id' => $sourceScopeId,
                'target_domain' => self::TARGET_DOMAIN,
                'target_type' => self::TARGET_TYPE,
                'target_id' => $targetId,
                'mapping_status' => LegacyImportMapping::MAPPING_MAPPED,
                'validation_status' => LegacyImportMapping::VALIDATION_PASSED,
                'source_fingerprint_hash' => $sourceHash,
                'manual_review_reason' => null,
                'errors' => null,
                'metadata' => $metadata,
            ],
        );
    }

    private function idempotencyKey(string $sourceId, ?int $sourceProductId): string
    {
        $key = self::TARGET_DOMAIN.':'.self::SOURCE_TABLE_PLATFORM_PAGE.':'.$sourceId;

        if ($sourceProductId !== null) {
            $key .= ':product:'.$sourceProductId;
        }

        return $key;
    }

    private function sourceScopeType(?int $sourceProductId): string
    {
        return $sourceProductId === null
            ? LegacyImportMapping::SOURCE_SCOPE_ID_ONLY
            : LegacyImportMapping::SOURCE_SCOPE_LEGACY_PRODUCT;
    }

    private function sourceScopeId(?int $sourceProductId): string
    {
        return $sourceProductId === null
            ? LegacyImportMapping::SOURCE_SCOPE_ID_ONLY
            : (string) $sourceProductId;
    }

    /**
     * @return array<string, string|int>
     */
    private function contentSourceMetadata(?int $sourceProductId, string $sourceScopeType, string $sourceScopeId): array
    {
        $metadata = [
            'legacy_source_scope_type' => $sourceScopeType,
            'legacy_source_scope_id' => $sourceScopeId,
        ];

        if ($sourceProductId !== null) {
            $metadata['legacy_product_id'] = $sourceProductId;
        }

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function fingerprint(array $source): string
    {
        ksort($source);

        return hash('sha256', json_encode($source, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
