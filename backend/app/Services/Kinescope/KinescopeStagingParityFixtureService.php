<?php

namespace App\Services\Kinescope;

use App\Models\Course;
use App\Models\KinescopeLegacyVideoAllowlistBatch;
use App\Models\KinescopeLegacyVideoAllowlistEntry;
use App\Models\LegacyImportMapping;
use App\Models\LegacyProtectedAssetMapping;
use App\Models\Lesson;
use App\Models\LessonLearningContent;
use App\Models\LessonMaterial;
use App\Models\LessonMaterialKinescopeVideo;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Services\Learning\LegacyLessonBodyImporter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class KinescopeStagingParityFixtureService
{
    private const FIXTURE_OWNER = 'kinescope_staging_parity_v1';

    private const MAX_MANIFEST_BYTES = 1_048_576;

    public function __construct(
        private readonly LegacyLessonBodyImporter $lessonBodyImporter,
        private readonly KinescopeApiClient $kinescopeApiClient,
    ) {}

    /**
     * @return array{exit_code:int, output:array<string, int|string>}
     */
    public function execute(
        string $manifestPath,
        bool $apply,
        bool $rollback,
        ?string $rollbackOutput,
        ?string $confirmedManifestHash,
        ?string $confirmedPlanHash,
    ): array {
        $initialInput = $this->readProtectedJson($manifestPath);
        if (! $apply) {
            return $rollback
                ? $this->rollback($initialInput, false, null)
                : $this->create($initialInput, false, $rollbackOutput, null);
        }

        $manifestLock = $this->acquireProtectedPathLock($initialInput['path']);
        try {
            $input = $this->readProtectedJson($initialInput['path']);

            if ($apply) {
                $this->assertConfirmation($confirmedManifestHash, $input['sha256']);
                $this->assertHash($confirmedPlanHash);
            }

            return $rollback
                ? $this->rollback($input, $apply, $confirmedPlanHash)
                : $this->create($input, $apply, $rollbackOutput, $confirmedPlanHash);
        } finally {
            $this->releaseProtectedPathLock($manifestLock);
        }
    }

    /**
     * @param  array{data:array<string, mixed>, sha256:string, path:string}  $input
     * @return array{exit_code:int, output:array<string, int|string>}
     */
    private function create(
        array $input,
        bool $apply,
        ?string $rollbackOutput,
        ?string $confirmedPlanHash,
    ): array {
        $manifest = $this->validateCreateManifest($input['data']);
        $plan = $this->createPlan($manifest, $input['sha256'], lock: false, inspectProvider: true);

        if (! $apply) {
            return $this->successOutput('create-dry-run', 'ready', $input['sha256'], $plan['sha256'], 'insert_count', 6);
        }

        $this->assertConfirmation($confirmedPlanHash, $plan['sha256']);
        if ($rollbackOutput === null) {
            throw new RuntimeException('Rollback output is required.');
        }
        $rollbackLock = $this->acquireProtectedPathLock($rollbackOutput);
        try {
            $this->assertWritableProtectedOutput($rollbackOutput);

            $preparedPayloadHash = null;
            try {
                DB::transaction(function () use (
                    $manifest,
                    $input,
                    $plan,
                    $rollbackOutput,
                    &$preparedPayloadHash,
                ): void {
                    $this->lockFixtureTablesForWrite();
                    $lockedPlan = $this->createPlan(
                        $manifest,
                        $input['sha256'],
                        lock: true,
                        inspectProvider: false,
                    );
                    $this->assertConfirmation($plan['sha256'], $lockedPlan['sha256']);

                    $legacyImport = $this->lessonBodyImporter->importPlatformPage($manifest['legacy']['source']);
                    $content = LessonLearningContent::query()->findOrFail($legacyImport->target_id);
                    $this->assertImportedRows($lockedPlan, $legacyImport, $content);
                    $this->checkpoint('after_import');

                    $ownership = $this->ownership(
                        $manifest['fixture_key'],
                        $input['sha256'],
                        $lockedPlan['sha256'],
                    );
                    $this->mergeMetadata($content, $ownership);
                    $this->mergeMetadata($legacyImport, $ownership);

                    $material = LessonMaterial::query()->create([
                        'lesson_id' => $lockedPlan['lesson']->id,
                        'kind' => LessonMaterial::KIND_VIDEO,
                        'title' => $manifest['material']['title'],
                        'sort_order' => $manifest['material']['sort_order'],
                        'is_published' => false,
                    ]);
                    $this->checkpoint('after_material');
                    $video = LessonMaterialKinescopeVideo::query()->create([
                        'lesson_material_id' => $material->id,
                        'embed_locator' => $manifest['kinescope']['embed_locator'],
                        'provider_video_id' => $manifest['kinescope']['provider_video_id'],
                    ]);
                    $this->checkpoint('after_video');
                    $protectedMapping = LegacyProtectedAssetMapping::query()->create([
                        'lesson_learning_content_id' => $content->id,
                        'lesson_id' => $lockedPlan['lesson']->id,
                        'source_table' => LegacyLessonBodyImporter::SOURCE_TABLE_PLATFORM_PAGE,
                        'source_id' => $lockedPlan['preview']['source_id'],
                        'source_scope_type' => $lockedPlan['preview']['source_scope_type'],
                        'source_scope_id' => $lockedPlan['preview']['source_scope_id'],
                        'asset_kind' => LegacyProtectedAssetMapping::ASSET_KIND_VIDEO,
                        'legacy_asset_id' => $manifest['legacy']['expected_legacy_asset_id'],
                        'reference_count' => 1,
                        'first_block_index' => $lockedPlan['protected_block_index'],
                        'readiness_status' => LegacyProtectedAssetMapping::READINESS_MAPPED_INTERNAL,
                        'validation_status' => LegacyProtectedAssetMapping::VALIDATION_PASSED,
                        'internal_asset_type' => 'lesson_material',
                        'internal_asset_id' => $material->id,
                        'metadata' => [
                            ...$ownership,
                            'video_candidate_fingerprint_sha256' => $manifest['legacy']['video_candidate_fingerprint_sha256'],
                        ],
                    ]);
                    $this->checkpoint('after_protected_mapping');
                    $grant = UserEntitlementGrant::query()->create([
                        'user_id' => $lockedPlan['user']->id,
                        'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
                        'atom_id' => $lockedPlan['lesson']->id,
                        'status' => UserEntitlementGrant::STATUS_ACTIVE,
                        'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
                        'source_id' => $ownership['fixture_key_sha256'],
                        'idempotency_key' => 'parity-fixture:'.substr($ownership['fixture_key_sha256'], 0, 48),
                        'granted_at' => now(),
                        'expires_at' => $manifest['staging']['entitlement_expires_at'],
                        'metadata' => $ownership,
                    ]);
                    $this->checkpoint('after_grant');

                    $material->update(['is_published' => true]);
                    $this->checkpoint('after_publish');
                    $rows = compact('content', 'legacyImport', 'material', 'video', 'protectedMapping', 'grant');
                    $rollbackDocument = $this->buildRollbackDocument(
                        $rows,
                        $manifest,
                        $input['sha256'],
                        $lockedPlan['sha256'],
                        $lockedPlan,
                    );
                    $this->writeJsonAtomically($rollbackOutput, $rollbackDocument);
                    $preparedPayloadHash = $rollbackDocument['payload_sha256'];
                    $this->checkpoint('after_rollback_manifest_prepared');
                }, attempts: 3);
                $this->checkpoint('after_create_transaction_commit');
            } catch (Throwable $exception) {
                if (is_string($preparedPayloadHash)) {
                    try {
                        $outcome = $this->preparedRollbackOutcome(
                            $rollbackOutput,
                            $input['sha256'],
                            $plan['sha256'],
                            $preparedPayloadHash,
                        );
                        if ($outcome !== 'already_rolled_back') {
                            return $this->failureOutput(
                                'create-apply',
                                'recovery_required',
                                $input['sha256'],
                                $plan['sha256'],
                            );
                        }
                    } catch (Throwable) {
                        return $this->failureOutput(
                            'create-apply',
                            'recovery_required',
                            $input['sha256'],
                            $plan['sha256'],
                        );
                    }
                }

                throw $exception;
            }

            try {
                $this->updateRollbackState(
                    $rollbackOutput,
                    'applied',
                    $input['sha256'],
                    $plan['sha256'],
                    $preparedPayloadHash,
                );
            } catch (Throwable) {
                return $this->failureOutput('create-apply', 'recovery_required', $input['sha256'], $plan['sha256']);
            }

            return $this->successOutput('create-apply', 'applied', $input['sha256'], $plan['sha256'], 'insert_count', 6);
        } finally {
            $this->releaseProtectedPathLock($rollbackLock);
        }
    }

    /**
     * @param  array{data:array<string, mixed>, sha256:string, path:string}  $input
     * @return array{exit_code:int, output:array<string, int|string>}
     */
    private function rollback(array $input, bool $apply, ?string $confirmedPlanHash): array
    {
        $manifest = $this->validateRollbackManifest($input['data']);
        $plan = $this->rollbackPlan($manifest, $input['sha256'], lock: false);

        if (! $apply) {
            return $this->successOutput(
                'rollback-dry-run',
                $plan['status'],
                $input['sha256'],
                $plan['sha256'],
                'delete_count',
                $plan['status'] === 'ready' ? 6 : 0,
            );
        }

        $this->assertConfirmation($confirmedPlanHash, $plan['sha256']);
        if ($plan['status'] === 'already_rolled_back') {
            return $this->successOutput(
                'rollback-apply',
                'already_rolled_back',
                $input['sha256'],
                $plan['sha256'],
                'delete_count',
                0,
            );
        }

        DB::transaction(function () use ($manifest, $input, $plan): void {
            $this->lockFixtureTablesForWrite();
            $lockedPlan = $this->rollbackPlan($manifest, $input['sha256'], lock: true);
            $this->assertConfirmation($plan['sha256'], $lockedPlan['sha256']);
            if ($lockedPlan['status'] !== 'ready') {
                throw new RuntimeException('Rollback state changed.');
            }

            $lockedPlan['models']['grant']->delete();
            $lockedPlan['models']['protected_mapping']->delete();
            $lockedPlan['models']['video']->delete();
            $lockedPlan['models']['material']->delete();
            $lockedPlan['models']['legacy_import']->delete();
            $lockedPlan['models']['content']->delete();
        }, attempts: 3);

        try {
            $this->updateRollbackStateByDocument($manifest, $input['path'] ?? null, 'rolled_back');
        } catch (Throwable) {
            return $this->failureOutput('rollback-apply', 'recovery_required', $input['sha256'], $plan['sha256']);
        }

        return $this->successOutput('rollback-apply', 'rolled_back', $input['sha256'], $plan['sha256'], 'delete_count', 6);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateCreateManifest(array $data): array
    {
        $this->assertExactKeys($data, [
            'schema_version', 'operation', 'environment', 'fixture_key', 'issued_at', 'expires_at',
            'legacy', 'staging', 'kinescope', 'material',
        ]);
        $this->assertExactKeys($this->arrayValue($data, 'legacy'), [
            'source', 'expected_source_scope_type', 'expected_source_scope_id', 'expected_legacy_asset_id',
            'source_fingerprint_sha256', 'video_candidate_fingerprint_sha256',
        ]);
        $source = $this->arrayValue($data['legacy'], 'source');
        $allowedSourceKeys = array_key_exists('product_id', $source)
            ? ['id', 'product_id', 'content']
            : ['id', 'content'];
        $this->assertExactKeys($source, $allowedSourceKeys);
        $this->assertExactKeys($this->arrayValue($data, 'staging'), [
            'lesson_id', 'lesson_precondition_sha256', 'user_id', 'user_precondition_sha256', 'entitlement_expires_at',
        ]);
        $this->assertExactKeys($this->arrayValue($data, 'kinescope'), [
            'active_batch_sha256', 'embed_locator', 'provider_video_id', 'pair_sha256',
        ]);
        $this->assertExactKeys($this->arrayValue($data, 'material'), ['title', 'sort_order']);

        if ($data['schema_version'] !== 1 || $data['operation'] !== 'create') {
            throw new RuntimeException('Manifest contract rejected.');
        }
        $expectedEnvironment = app()->environment('testing') ? 'testing' : 'staging';
        if (! app()->environment(['testing', 'staging']) || $data['environment'] !== $expectedEnvironment) {
            throw new RuntimeException('Environment rejected.');
        }

        $fixtureKey = $this->stringValue($data, 'fixture_key');
        if (preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $fixtureKey) !== 1) {
            throw new RuntimeException('Fixture key rejected.');
        }
        $issuedAt = $this->utcDate($data, 'issued_at');
        $expiresAt = $this->utcDate($data, 'expires_at');
        $now = CarbonImmutable::now();
        if ($issuedAt->greaterThan($now->addMinute()) || $issuedAt->lessThan($now->subMinutes(15))) {
            throw new RuntimeException('Manifest issue time rejected.');
        }
        if (
            $expiresAt->lessThanOrEqualTo($now)
            || $expiresAt->lessThanOrEqualTo($issuedAt)
            || $expiresAt->greaterThan($issuedAt->addMinutes(20))
        ) {
            throw new RuntimeException('Manifest expiry rejected.');
        }

        $source['id'] = $this->positiveInteger($source['id'] ?? null);
        if (array_key_exists('product_id', $source)) {
            $source['product_id'] = $this->positiveInteger($source['product_id']);
        }
        $source['content'] = $this->stringValue($source, 'content');
        $legacyAssetId = $this->positiveInteger($data['legacy']['expected_legacy_asset_id'] ?? null);

        $scopeType = $this->stringValue($data['legacy'], 'expected_source_scope_type');
        if (! in_array($scopeType, [LegacyImportMapping::SOURCE_SCOPE_ID_ONLY, LegacyImportMapping::SOURCE_SCOPE_LEGACY_PRODUCT], true)) {
            throw new RuntimeException('Legacy source scope rejected.');
        }
        $scopeId = $this->stringValue($data['legacy'], 'expected_source_scope_id');
        if (! ctype_digit($scopeId) || (int) $scopeId < 1) {
            throw new RuntimeException('Legacy source scope rejected.');
        }

        foreach ([
            'source_fingerprint_sha256',
            'video_candidate_fingerprint_sha256',
        ] as $hashField) {
            $this->assertHash($this->stringValue($data['legacy'], $hashField));
        }
        foreach (['lesson_precondition_sha256', 'user_precondition_sha256'] as $hashField) {
            $this->assertHash($this->stringValue($data['staging'], $hashField));
        }
        foreach (['active_batch_sha256', 'pair_sha256'] as $hashField) {
            $this->assertHash($this->stringValue($data['kinescope'], $hashField));
        }

        $lessonId = $this->positiveInteger($data['staging']['lesson_id'] ?? null);
        $userId = $this->positiveInteger($data['staging']['user_id'] ?? null);
        $entitlementExpiresAt = $this->utcDate($data['staging'], 'entitlement_expires_at');
        if ($entitlementExpiresAt->lessThanOrEqualTo($now) || $entitlementExpiresAt->greaterThan($now->addHours(2))) {
            throw new RuntimeException('Entitlement expiry rejected.');
        }

        $embedLocator = $this->stringValue($data['kinescope'], 'embed_locator');
        $providerVideoId = $this->stringValue($data['kinescope'], 'provider_video_id');
        if (
            preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $embedLocator) !== 1
            || preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $providerVideoId) !== 1
            || ! hash_equals(
                hash('sha256', "{$embedLocator}\t{$providerVideoId}\n"),
                $data['kinescope']['pair_sha256'],
            )
        ) {
            throw new RuntimeException('Kinescope pair rejected.');
        }

        $title = $this->stringValue($data['material'], 'title');
        $sortOrder = $data['material']['sort_order'] ?? null;
        if (
            mb_strlen($title) < 1
            || mb_strlen($title) > 120
            || preg_match('/^[\p{L}\p{N} .,:;()\-–—№]+$/uD', $title) !== 1
            || ! is_int($sortOrder)
            || $sortOrder < 0
            || $sortOrder > 65535
        ) {
            throw new RuntimeException('Material attributes rejected.');
        }

        $data['legacy']['source'] = $source;
        $data['legacy']['expected_legacy_asset_id'] = $legacyAssetId;
        $data['issued_at'] = $issuedAt;
        $data['expires_at'] = $expiresAt;
        $data['staging']['lesson_id'] = $lessonId;
        $data['staging']['user_id'] = $userId;
        $data['staging']['entitlement_expires_at'] = $entitlementExpiresAt;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function createPlan(array $manifest, string $manifestHash, bool $lock, bool $inspectProvider): array
    {
        $now = CarbonImmutable::now();
        if (
            $manifest['expires_at']->lessThanOrEqualTo($now)
            || $manifest['staging']['entitlement_expires_at']->lessThanOrEqualTo($now)
            || $manifest['staging']['entitlement_expires_at']->greaterThan($now->addHours(2))
        ) {
            throw new RuntimeException('Fixture timing rejected.');
        }

        $lessonQuery = Lesson::query();
        $userQuery = User::query();
        if ($lock) {
            $lessonQuery->lockForUpdate();
            $userQuery->lockForUpdate();
        }
        $lesson = $lessonQuery->findOrFail($manifest['staging']['lesson_id']);
        $user = $userQuery->findOrFail($manifest['staging']['user_id']);
        if ($lock) {
            $course = Course::query()->lockForUpdate()->findOrFail($lesson->course_id);
            $subject = Subject::query()->lockForUpdate()->findOrFail($course->subject_id);
            $course->setRelation('subject', $subject);
            $lesson->setRelation('course', $course);
        } else {
            $lesson->load('course.subject');
        }

        if (
            ! $lesson->is_active
            || ! $lesson->course?->is_active
            || ! $lesson->course?->subject?->is_active
            || ! in_array($user->role, ['user_no_purchase', 'user_with_purchase'], true)
        ) {
            throw new RuntimeException('Target boundary rejected.');
        }
        $this->assertConfirmation($manifest['staging']['lesson_precondition_sha256'], $this->lessonPreconditionHash($lesson));
        $this->assertConfirmation($manifest['staging']['user_precondition_sha256'], $this->userPreconditionHash($user));

        if (
            $lesson->learningContent()->exists()
            || $lesson->materials()->exists()
            || LegacyProtectedAssetMapping::query()->where('lesson_id', $lesson->id)->exists()
            || $this->effectiveLessonGrants($lesson->id)->exists()
        ) {
            throw new RuntimeException('Target lesson is not isolated.');
        }

        $preview = $this->lessonBodyImporter->previewPlatformPage($manifest['legacy']['source']);
        $protectedBlockIndex = $this->assertPreview($preview, $manifest, $lesson);
        if (
            LegacyImportMapping::query()
                ->where('idempotency_key', $preview['idempotency_key'])
                ->orWhere(function ($query) use ($preview): void {
                    $query
                        ->where('source_table', LegacyLessonBodyImporter::SOURCE_TABLE_PLATFORM_PAGE)
                        ->where('source_id', $preview['source_id'])
                        ->where('target_domain', LegacyLessonBodyImporter::TARGET_DOMAIN)
                        ->where('source_scope_type', $preview['source_scope_type'])
                        ->where('source_scope_id', $preview['source_scope_id']);
                })
                ->exists()
        ) {
            throw new RuntimeException('Legacy import identity already exists.');
        }

        $batchQuery = KinescopeLegacyVideoAllowlistBatch::query()->where('is_active', true);
        if ($lock) {
            $batchQuery->lockForUpdate();
        }
        $batches = $batchQuery->get();
        if ($batches->count() !== 1) {
            throw new RuntimeException('Active allowlist rejected.');
        }
        $batch = $batches->sole();
        $entryQuery = KinescopeLegacyVideoAllowlistEntry::query()->where('batch_id', $batch->id);
        if ($lock) {
            $entryQuery->lockForUpdate();
        }
        $entries = $entryQuery->orderBy('embed_locator')->get();
        $batchHash = $this->allowlistHash($entries->all());
        if (
            $batch->activated_at === null
            || $batch->source_count < 1
            || $entries->count() !== $batch->source_count
            || ! hash_equals($batch->source_sha256, $batchHash)
            || ! hash_equals($manifest['kinescope']['active_batch_sha256'], $batchHash)
        ) {
            throw new RuntimeException('Active allowlist integrity rejected.');
        }
        $entry = $entries->first(fn (KinescopeLegacyVideoAllowlistEntry $candidate): bool => hash_equals((string) $candidate->embed_locator, $manifest['kinescope']['embed_locator'])
            && is_string($candidate->provider_video_id)
            && hash_equals($candidate->provider_video_id, $manifest['kinescope']['provider_video_id'])
        );
        if (! $entry instanceof KinescopeLegacyVideoAllowlistEntry) {
            throw new RuntimeException('Allowlist pair rejected.');
        }
        if (
            LessonMaterialKinescopeVideo::query()
                ->where('embed_locator', $manifest['kinescope']['embed_locator'])
                ->orWhere('provider_video_id', $manifest['kinescope']['provider_video_id'])
                ->exists()
        ) {
            throw new RuntimeException('Kinescope identity already exists.');
        }

        if ($inspectProvider) {
            $inspection = $this->kinescopeApiClient->inspectReady($manifest['kinescope']['embed_locator']);
            if (! hash_equals($manifest['kinescope']['provider_video_id'], $inspection->videoId)) {
                throw new RuntimeException('Provider identity rejected.');
            }
        }

        $previewHash = $this->canonicalHash([
            'source_table' => $preview['source_table'],
            'source_id' => $preview['source_id'],
            'source_scope_type' => $preview['source_scope_type'],
            'source_scope_id' => $preview['source_scope_id'],
            'source_fingerprint_hash' => $preview['source_fingerprint_hash'],
            'idempotency_key' => $preview['idempotency_key'],
            'target_lesson_id' => $preview['target_lesson_id'],
            'body' => $preview['body'],
        ]);
        $fixtureKeyHash = hash('sha256', $manifest['fixture_key']);
        $planPayload = [
            'schema_version' => 1,
            'operation' => 'create',
            'manifest_sha256' => $manifestHash,
            'fixture_key_sha256' => $fixtureKeyHash,
            'lesson_precondition_sha256' => $this->lessonPreconditionHash($lesson),
            'user_precondition_sha256' => $this->userPreconditionHash($user),
            'active_batch_sha256' => $batchHash,
            'pair_sha256' => $manifest['kinescope']['pair_sha256'],
            'preview_sha256' => $previewHash,
            'operations_sha256' => hash('sha256', implode("\n", [
                'lesson_learning_content:create',
                'legacy_import_mapping:create',
                'lesson_material:create',
                'lesson_material_kinescope_video:create',
                'legacy_protected_asset_mapping:create',
                'user_entitlement_grant:create',
                'lesson_material:publish',
            ])."\n"),
            'insert_count' => 6,
            'entitlement_expires_at' => $manifest['staging']['entitlement_expires_at']->toIso8601ZuluString(),
        ];

        return [
            'sha256' => $this->canonicalHash($planPayload),
            'lesson' => $lesson,
            'user' => $user,
            'batch' => $batch,
            'entry' => $entry,
            'preview' => $preview,
            'protected_block_index' => $protectedBlockIndex,
            'preexisting_row_counts' => [
                'lesson_learning_contents' => 0,
                'legacy_import_lineage' => 0,
                'lesson_materials' => 0,
                'kinescope_identity_conflicts' => 0,
                'lesson_protected_mappings' => 0,
                'effective_lesson_grants' => 0,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $manifest
     */
    private function assertPreview(array $preview, array $manifest, Lesson $lesson): int
    {
        if (
            ($preview['action'] ?? null) !== 'import_draft'
            || (int) ($preview['target_lesson_id'] ?? 0) !== (int) $lesson->id
            || ($preview['source_scope_type'] ?? null) !== $manifest['legacy']['expected_source_scope_type']
            || (string) ($preview['source_scope_id'] ?? '') !== $manifest['legacy']['expected_source_scope_id']
            || ($preview['source_fingerprint_hash'] ?? null) !== $manifest['legacy']['source_fingerprint_sha256']
        ) {
            throw new RuntimeException('Legacy preview rejected.');
        }

        $blocks = data_get($preview, 'body.blocks');
        if (! is_array($blocks)) {
            throw new RuntimeException('Legacy body rejected.');
        }
        $matches = [];
        foreach ($blocks as $index => $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'legacy_protected_asset_reference') {
                continue;
            }
            $matches[] = [
                'index' => (int) $index,
                'asset_kind' => $block['assetKind'] ?? null,
                'legacy_asset_id' => $block['legacyAssetId'] ?? null,
            ];
        }
        if (
            count($matches) !== 1
            || $matches[0]['asset_kind'] !== LegacyProtectedAssetMapping::ASSET_KIND_VIDEO
            || (int) $matches[0]['legacy_asset_id'] !== $manifest['legacy']['expected_legacy_asset_id']
        ) {
            throw new RuntimeException('Legacy protected reference rejected.');
        }

        return $matches[0]['index'];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function assertImportedRows(
        array $plan,
        LegacyImportMapping $legacyImport,
        LessonLearningContent $content,
    ): void {
        if (
            $legacyImport->source_table !== $plan['preview']['source_table']
            || (string) $legacyImport->source_id !== (string) $plan['preview']['source_id']
            || $legacyImport->source_scope_type !== $plan['preview']['source_scope_type']
            || (string) $legacyImport->source_scope_id !== (string) $plan['preview']['source_scope_id']
            || $legacyImport->target_domain !== LegacyLessonBodyImporter::TARGET_DOMAIN
            || $legacyImport->target_type !== LegacyLessonBodyImporter::TARGET_TYPE
            || (int) $legacyImport->target_id !== (int) $content->id
            || $legacyImport->mapping_status !== LegacyImportMapping::MAPPING_MAPPED
            || $legacyImport->validation_status !== LegacyImportMapping::VALIDATION_PASSED
            || $legacyImport->idempotency_key !== $plan['preview']['idempotency_key']
            || $legacyImport->source_fingerprint_hash !== $plan['preview']['source_fingerprint_hash']
            || (int) $content->lesson_id !== (int) $plan['lesson']->id
            || $content->status !== LessonLearningContent::STATUS_DRAFT
            || $content->format !== LessonLearningContent::FORMAT_LESSON_BLOCKS_V1
            || $content->body !== $plan['preview']['body']
            || $content->source_table !== LegacyLessonBodyImporter::SOURCE_TABLE_PLATFORM_PAGE
            || (string) $content->source_id !== (string) $plan['preview']['source_id']
            || $content->source_fingerprint_hash !== $plan['preview']['source_fingerprint_hash']
            || $content->import_status !== LessonLearningContent::IMPORT_STATUS_IMPORTED
            || $content->manual_review_reason !== null
        ) {
            throw new RuntimeException('Imported lineage rejected.');
        }
    }

    /**
     * @param  array<string, Model>  $rows
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function buildRollbackDocument(
        array $rows,
        array $manifest,
        string $manifestHash,
        string $planHash,
        array $plan,
    ): array {
        $rowPayload = [
            'content' => $this->rollbackRow($rows['content']),
            'legacy_import' => $this->rollbackRow($rows['legacyImport']),
            'material' => $this->rollbackRow($rows['material']),
            'video' => $this->rollbackRow($rows['video']),
            'protected_mapping' => $this->rollbackRow($rows['protectedMapping']),
            'grant' => $this->rollbackRow($rows['grant']),
        ];
        $payload = [
            'environment' => $manifest['environment'],
            'fixture_key_sha256' => hash('sha256', $manifest['fixture_key']),
            'create_manifest_sha256' => $manifestHash,
            'create_plan_sha256' => $planHash,
            'target_preconditions' => [
                'lesson_sha256' => $this->lessonPreconditionHash($plan['lesson']),
                'course_sha256' => $this->coursePreconditionHash($plan['lesson']->course),
                'subject_sha256' => $this->subjectPreconditionHash($plan['lesson']->course->subject),
                'user_sha256' => $this->userPreconditionHash($plan['user']),
            ],
            'preexisting_row_counts' => $plan['preexisting_row_counts'],
            'ownership_metadata_sha256' => [
                'content' => $this->canonicalHash($rows['content']->metadata),
                'legacy_import' => $this->canonicalHash($rows['legacyImport']->metadata),
                'protected_mapping' => $this->canonicalHash($rows['protectedMapping']->metadata),
                'grant' => $this->canonicalHash($rows['grant']->metadata),
            ],
            'rows' => $rowPayload,
        ];

        return [
            'schema_version' => 1,
            'operation' => 'rollback',
            'environment' => $payload['environment'],
            'state' => 'prepared',
            'prepared_at' => CarbonImmutable::now()->toIso8601ZuluString(),
            'applied_at' => null,
            'rolled_back_at' => null,
            'fixture_key_sha256' => $payload['fixture_key_sha256'],
            'create_manifest_sha256' => $manifestHash,
            'create_plan_sha256' => $planHash,
            'target_preconditions' => $payload['target_preconditions'],
            'preexisting_row_counts' => $payload['preexisting_row_counts'],
            'ownership_metadata_sha256' => $payload['ownership_metadata_sha256'],
            'rows' => $rowPayload,
            'payload_sha256' => $this->canonicalHash($payload),
        ];
    }

    /**
     * @return array{id:int, fingerprint_sha256:string, relations:array<string, int>}
     */
    private function rollbackRow(Model $model): array
    {
        $model->refresh();

        return [
            'id' => (int) $model->getKey(),
            'fingerprint_sha256' => $this->rowFingerprint($model),
            'relations' => $this->rowRelations($model),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateRollbackManifest(array $data): array
    {
        $this->assertExactKeys($data, [
            'schema_version', 'operation', 'environment', 'state', 'prepared_at', 'applied_at', 'rolled_back_at',
            'fixture_key_sha256', 'create_manifest_sha256', 'create_plan_sha256', 'target_preconditions', 'rows',
            'preexisting_row_counts', 'ownership_metadata_sha256', 'payload_sha256',
        ]);
        if (
            $data['schema_version'] !== 1
            || $data['operation'] !== 'rollback'
            || ! in_array($data['state'], ['prepared', 'applied', 'rolled_back'], true)
        ) {
            throw new RuntimeException('Rollback manifest rejected.');
        }
        $preparedAt = $this->utcDate($data, 'prepared_at');
        $appliedAt = $this->nullableUtcDate($data, 'applied_at');
        $rolledBackAt = $this->nullableUtcDate($data, 'rolled_back_at');
        $now = CarbonImmutable::now();
        if ($preparedAt->greaterThan($now->addMinute())) {
            throw new RuntimeException('Rollback prepared timestamp rejected.');
        }
        if (
            ($data['state'] === 'prepared' && ($appliedAt !== null || $rolledBackAt !== null))
            || ($data['state'] === 'applied' && ($appliedAt === null || $rolledBackAt !== null))
            || ($data['state'] === 'rolled_back' && $rolledBackAt === null)
            || ($appliedAt !== null && ($appliedAt->lessThan($preparedAt) || $appliedAt->greaterThan($now->addMinute())))
            || ($rolledBackAt !== null && ($rolledBackAt->lessThan($appliedAt ?? $preparedAt) || $rolledBackAt->greaterThan($now->addMinute())))
        ) {
            throw new RuntimeException('Rollback state envelope rejected.');
        }
        $expectedEnvironment = app()->environment('testing') ? 'testing' : 'staging';
        if (! app()->environment(['testing', 'staging']) || $data['environment'] !== $expectedEnvironment) {
            throw new RuntimeException('Rollback environment rejected.');
        }
        foreach (['fixture_key_sha256', 'create_manifest_sha256', 'create_plan_sha256', 'payload_sha256'] as $field) {
            $this->assertHash($this->stringValue($data, $field));
        }
        $targetPreconditions = $this->arrayValue($data, 'target_preconditions');
        $this->assertExactKeys($targetPreconditions, [
            'lesson_sha256', 'course_sha256', 'subject_sha256', 'user_sha256',
        ]);
        foreach ($targetPreconditions as $hash) {
            $this->assertHash(is_string($hash) ? $hash : null);
        }
        $preexistingRowCounts = $this->arrayValue($data, 'preexisting_row_counts');
        $this->assertExactKeys($preexistingRowCounts, [
            'lesson_learning_contents', 'legacy_import_lineage', 'lesson_materials',
            'kinescope_identity_conflicts', 'lesson_protected_mappings', 'effective_lesson_grants',
        ]);
        foreach ($preexistingRowCounts as $count) {
            if ($count !== 0) {
                throw new RuntimeException('Rollback pre-existing counts rejected.');
            }
        }
        $ownershipMetadataHashes = $this->arrayValue($data, 'ownership_metadata_sha256');
        $this->assertExactKeys($ownershipMetadataHashes, [
            'content', 'legacy_import', 'protected_mapping', 'grant',
        ]);
        foreach ($ownershipMetadataHashes as $hash) {
            $this->assertHash(is_string($hash) ? $hash : null);
        }
        $rows = $this->arrayValue($data, 'rows');
        $this->assertExactKeys($rows, ['content', 'legacy_import', 'material', 'video', 'protected_mapping', 'grant']);
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('Rollback rows rejected.');
            }
            $this->assertExactKeys($row, ['id', 'fingerprint_sha256', 'relations']);
            $this->positiveInteger($row['id'] ?? null);
            $this->assertHash($this->stringValue($row, 'fingerprint_sha256'));
            if (! is_array($row['relations'])) {
                throw new RuntimeException('Rollback relations rejected.');
            }
            foreach ($row['relations'] as $relation) {
                $this->positiveInteger($relation);
            }
        }
        $payload = [
            'environment' => $data['environment'],
            'fixture_key_sha256' => $data['fixture_key_sha256'],
            'create_manifest_sha256' => $data['create_manifest_sha256'],
            'create_plan_sha256' => $data['create_plan_sha256'],
            'target_preconditions' => $targetPreconditions,
            'preexisting_row_counts' => $preexistingRowCounts,
            'ownership_metadata_sha256' => $ownershipMetadataHashes,
            'rows' => $rows,
        ];
        if (! hash_equals($data['payload_sha256'], $this->canonicalHash($payload))) {
            throw new RuntimeException('Rollback payload integrity rejected.');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{sha256:string,status:string,models:array<string, Model>}
     */
    private function rollbackPlan(array $manifest, string $manifestHash, bool $lock): array
    {
        $classes = [
            'content' => LessonLearningContent::class,
            'legacy_import' => LegacyImportMapping::class,
            'material' => LessonMaterial::class,
            'video' => LessonMaterialKinescopeVideo::class,
            'protected_mapping' => LegacyProtectedAssetMapping::class,
            'grant' => UserEntitlementGrant::class,
        ];
        $models = [];
        foreach ($classes as $key => $class) {
            $query = $class::query();
            if ($lock) {
                $query->lockForUpdate();
            }
            $model = $query->find($manifest['rows'][$key]['id']);
            if ($model instanceof Model) {
                $models[$key] = $model;
            }
        }
        if ($models === []) {
            $status = 'already_rolled_back';
        } elseif (count($models) !== 6) {
            throw new RuntimeException('Rollback row set is partial.');
        } else {
            if (! in_array($manifest['state'], ['prepared', 'applied'], true)) {
                throw new RuntimeException('Rollback state rejected.');
            }
            $status = 'ready';
            foreach ($models as $key => $model) {
                if (! hash_equals($manifest['rows'][$key]['fingerprint_sha256'], $this->rowFingerprint($model))) {
                    throw new RuntimeException('Rollback row drift rejected.');
                }
                if ($manifest['rows'][$key]['relations'] !== $this->rowRelations($model)) {
                    throw new RuntimeException('Rollback relation drift rejected.');
                }
            }
            $this->assertRollbackRelationships(
                $models,
                $manifest['fixture_key_sha256'],
                $manifest['ownership_metadata_sha256'],
            );
            $this->assertRollbackTargetPreconditions($models, $manifest['target_preconditions'], $lock);
        }

        $planPayload = [
            'schema_version' => 1,
            'operation' => 'rollback',
            'manifest_sha256' => $manifestHash,
            'fixture_key_sha256' => $manifest['fixture_key_sha256'],
            'payload_sha256' => $manifest['payload_sha256'],
            'status' => $status,
            'delete_count' => $status === 'ready' ? 6 : 0,
        ];

        return [
            'sha256' => $this->canonicalHash($planPayload),
            'status' => $status,
            'models' => $models,
        ];
    }

    /**
     * @param  array<string, Model>  $models
     */
    private function assertRollbackRelationships(
        array $models,
        string $fixtureKeyHash,
        array $ownershipMetadataHashes,
    ): void {
        $content = $models['content'];
        $legacyImport = $models['legacy_import'];
        $material = $models['material'];
        $video = $models['video'];
        $protectedMapping = $models['protected_mapping'];
        $grant = $models['grant'];

        if (
            (int) $legacyImport->target_id !== (int) $content->id
            || (int) $material->lesson_id !== (int) $content->lesson_id
            || (int) $video->lesson_material_id !== (int) $material->id
            || (int) $protectedMapping->lesson_learning_content_id !== (int) $content->id
            || (int) $protectedMapping->internal_asset_id !== (int) $material->id
            || (int) $grant->atom_id !== (int) $content->lesson_id
        ) {
            throw new RuntimeException('Rollback ownership graph rejected.');
        }
        foreach ([$content, $legacyImport, $protectedMapping, $grant] as $ownedModel) {
            if (
                data_get($ownedModel->metadata, 'fixture_owner') !== self::FIXTURE_OWNER
                || data_get($ownedModel->metadata, 'fixture_key_sha256') !== $fixtureKeyHash
            ) {
                throw new RuntimeException('Rollback ownership marker rejected.');
            }
        }
        foreach ([
            'content' => $content,
            'legacy_import' => $legacyImport,
            'protected_mapping' => $protectedMapping,
            'grant' => $grant,
        ] as $key => $ownedModel) {
            if (! hash_equals($ownershipMetadataHashes[$key], $this->canonicalHash($ownedModel->metadata))) {
                throw new RuntimeException('Rollback ownership metadata rejected.');
            }
        }
        if (
            LessonMaterial::query()->where('lesson_id', $content->lesson_id)->count() !== 1
            || LegacyImportMapping::query()->where('target_type', LegacyLessonBodyImporter::TARGET_TYPE)->where('target_id', $content->id)->count() !== 1
            || LegacyProtectedAssetMapping::query()
                ->where(function ($query) use ($content, $material): void {
                    $query
                        ->where('lesson_learning_content_id', $content->id)
                        ->orWhere(function ($query) use ($material): void {
                            $query
                                ->where('internal_asset_type', 'lesson_material')
                                ->where('internal_asset_id', $material->id);
                        });
                })
                ->count() !== 1
            || LessonMaterialKinescopeVideo::query()->where('lesson_material_id', $material->id)->count() !== 1
            || $this->effectiveLessonGrants((int) $content->lesson_id)
                ->where('id', '!=', $grant->id)
                ->exists()
        ) {
            throw new RuntimeException('Rollback dependents rejected.');
        }
    }

    /**
     * @param  array<string, Model>  $models
     * @param  array<string, string>  $preconditions
     */
    private function assertRollbackTargetPreconditions(array $models, array $preconditions, bool $lock): void
    {
        $content = $models['content'];
        $grant = $models['grant'];
        $lessonQuery = Lesson::query();
        $userQuery = User::query();
        if ($lock) {
            $lessonQuery->lockForUpdate();
            $userQuery->lockForUpdate();
        }
        $lesson = $lessonQuery->findOrFail($content->lesson_id);
        $user = $userQuery->findOrFail($grant->user_id);
        if ($lock) {
            $course = Course::query()->lockForUpdate()->findOrFail($lesson->course_id);
            $subject = Subject::query()->lockForUpdate()->findOrFail($course->subject_id);
            $course->setRelation('subject', $subject);
            $lesson->setRelation('course', $course);
        } else {
            $lesson->load('course.subject');
        }

        if (
            ! hash_equals($preconditions['lesson_sha256'], $this->lessonPreconditionHash($lesson))
            || ! hash_equals($preconditions['course_sha256'], $this->coursePreconditionHash($lesson->course))
            || ! hash_equals($preconditions['subject_sha256'], $this->subjectPreconditionHash($lesson->course->subject))
            || ! hash_equals($preconditions['user_sha256'], $this->userPreconditionHash($user))
        ) {
            throw new RuntimeException('Rollback target drift rejected.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rowValues(Model $model): array
    {
        return match (true) {
            $model instanceof LessonLearningContent => $model->only([
                'lesson_id', 'status', 'format', 'version', 'body', 'published_at', 'source_table', 'source_id',
                'source_fingerprint_hash', 'import_status', 'manual_review_reason', 'metadata',
            ]),
            $model instanceof LegacyImportMapping => $model->only([
                'batch_key', 'source_table', 'source_id', 'source_scope_type', 'source_scope_id', 'target_domain',
                'target_type', 'target_id', 'mapping_status', 'validation_status', 'idempotency_key',
                'source_fingerprint_hash', 'manual_review_reason', 'errors', 'metadata',
            ]),
            $model instanceof LessonMaterial => $model->only([
                'lesson_id', 'kind', 'title', 'sort_order', 'is_published',
            ]),
            $model instanceof LessonMaterialKinescopeVideo => $model->only([
                'lesson_material_id', 'embed_locator', 'provider_video_id',
            ]),
            $model instanceof LegacyProtectedAssetMapping => $model->only([
                'lesson_learning_content_id', 'lesson_id', 'source_table', 'source_id', 'source_scope_type',
                'source_scope_id', 'asset_kind', 'legacy_asset_id', 'reference_count', 'first_block_index',
                'readiness_status', 'validation_status', 'internal_asset_type', 'internal_asset_id',
                'manual_review_reason', 'metadata',
            ]),
            $model instanceof UserEntitlementGrant => $model->only([
                'user_id', 'atom_kind', 'atom_id', 'status', 'source_type', 'source_id', 'order_id', 'order_item_id',
                'idempotency_key', 'granted_at', 'revoked_at', 'expires_at', 'revoked_reason', 'metadata',
            ]),
            default => throw new RuntimeException('Unsupported rollback model.'),
        };
    }

    private function rowFingerprint(Model $model): string
    {
        return $this->canonicalHash($this->rowValues($model));
    }

    /**
     * @return array<string, int>
     */
    private function rowRelations(Model $model): array
    {
        return match (true) {
            $model instanceof LessonLearningContent => ['lesson_id' => (int) $model->lesson_id],
            $model instanceof LegacyImportMapping => ['content_id' => (int) $model->target_id],
            $model instanceof LessonMaterial => ['lesson_id' => (int) $model->lesson_id],
            $model instanceof LessonMaterialKinescopeVideo => ['material_id' => (int) $model->lesson_material_id],
            $model instanceof LegacyProtectedAssetMapping => [
                'content_id' => (int) $model->lesson_learning_content_id,
                'material_id' => (int) $model->internal_asset_id,
                'lesson_id' => (int) $model->lesson_id,
            ],
            $model instanceof UserEntitlementGrant => [
                'lesson_id' => (int) $model->atom_id,
                'user_id' => (int) $model->user_id,
            ],
            default => throw new RuntimeException('Unsupported rollback model.'),
        };
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function updateRollbackStateByDocument(array $manifest, ?string $path, string $state): void
    {
        if ($path === null) {
            throw new RuntimeException('Rollback path unavailable.');
        }
        $current = $this->readProtectedJson($path);
        $currentManifest = $this->validateRollbackManifest($current['data']);
        if (
            ! hash_equals($manifest['payload_sha256'], $currentManifest['payload_sha256'])
            || ! hash_equals($manifest['create_manifest_sha256'], $currentManifest['create_manifest_sha256'])
            || ! hash_equals($manifest['create_plan_sha256'], $currentManifest['create_plan_sha256'])
            || ! in_array($currentManifest['state'], ['prepared', 'applied'], true)
        ) {
            throw new RuntimeException('Rollback state compare-and-swap rejected.');
        }

        $currentManifest['state'] = $state;
        if ($state === 'rolled_back') {
            $currentManifest['rolled_back_at'] = CarbonImmutable::now()->toIso8601ZuluString();
        }
        $this->writeJsonAtomically($path, $currentManifest, overwrite: true);
    }

    private function updateRollbackState(
        string $path,
        string $state,
        string $manifestHash,
        string $planHash,
        ?string $payloadHash,
    ): void {
        if ($payloadHash === null) {
            throw new RuntimeException('Rollback payload unavailable.');
        }
        $input = $this->readProtectedJson($path);
        $manifest = $this->validateRollbackManifest($input['data']);
        if (
            $manifest['state'] !== 'prepared'
            || ! hash_equals($manifestHash, $manifest['create_manifest_sha256'])
            || ! hash_equals($planHash, $manifest['create_plan_sha256'])
            || ! hash_equals($payloadHash, $manifest['payload_sha256'])
        ) {
            throw new RuntimeException('Rollback state compare-and-swap rejected.');
        }
        $manifest['state'] = $state;
        if ($state === 'applied') {
            $manifest['applied_at'] = CarbonImmutable::now()->toIso8601ZuluString();
        }
        $this->writeJsonAtomically($path, $manifest, overwrite: true);
    }

    private function removePreparedRollbackIfOwned(
        string $path,
        string $manifestHash,
        string $planHash,
        string $payloadHash,
    ): void {
        try {
            $input = $this->readProtectedJson($path);
            $data = $input['data'];
            if (
                ($data['operation'] ?? null) !== 'rollback'
                || ($data['state'] ?? null) !== 'prepared'
                || ! is_string($data['create_manifest_sha256'] ?? null)
                || ! hash_equals($manifestHash, $data['create_manifest_sha256'])
                || ! is_string($data['create_plan_sha256'] ?? null)
                || ! hash_equals($planHash, $data['create_plan_sha256'])
                || ! is_string($data['payload_sha256'] ?? null)
                || ! hash_equals($payloadHash, $data['payload_sha256'])
            ) {
                return;
            }

            @unlink($path);
        } catch (Throwable) {
            return;
        }
    }

    private function preparedRollbackOutcome(
        string $path,
        string $manifestHash,
        string $planHash,
        string $payloadHash,
    ): string {
        $input = $this->readProtectedJson($path);
        $manifest = $this->validateRollbackManifest($input['data']);
        if (
            $manifest['state'] !== 'prepared'
            || ! hash_equals($manifestHash, $manifest['create_manifest_sha256'])
            || ! hash_equals($planHash, $manifest['create_plan_sha256'])
            || ! hash_equals($payloadHash, $manifest['payload_sha256'])
        ) {
            throw new RuntimeException('Prepared rollback ownership rejected.');
        }

        return DB::transaction(function () use (
            $manifest,
            $input,
            $path,
            $manifestHash,
            $planHash,
            $payloadHash,
        ): string {
            $this->lockFixtureTablesForWrite();
            $status = $this->rollbackPlan($manifest, $input['sha256'], lock: true)['status'];
            if ($status === 'already_rolled_back') {
                $this->removePreparedRollbackIfOwned($path, $manifestHash, $planHash, $payloadHash);
                if (is_file($path)) {
                    throw new RuntimeException('Prepared rollback cleanup rejected.');
                }
            }

            return $status;
        }, attempts: 1);
    }

    /**
     * @return array{data:array<string, mixed>, sha256:string, path:string}
     */
    private function readProtectedJson(string $path): array
    {
        if ($path === '' || ! str_starts_with($path, DIRECTORY_SEPARATOR) || is_link($path)) {
            throw new RuntimeException('Manifest path rejected.');
        }
        $realPath = realpath($path);
        if (
            $realPath === false
            || ! hash_equals($realPath, $path)
            || ! is_file($realPath)
            || $this->hasSymlinkComponent($path)
        ) {
            throw new RuntimeException('Manifest file rejected.');
        }
        $this->assertProtectedLocation($realPath);
        $directoryPermissions = fileperms(dirname($realPath));
        if (! is_int($directoryPermissions) || ($directoryPermissions & 0077) !== 0) {
            throw new RuntimeException('Manifest directory boundary rejected.');
        }

        $handle = fopen($realPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Manifest open rejected.');
        }
        try {
            $stat = fstat($handle);
            if (
                ! is_array($stat)
                || ($stat['mode'] & 0170000) !== 0100000
                || ($stat['mode'] & 0777) !== 0600
                || $stat['size'] < 2
                || $stat['size'] > self::MAX_MANIFEST_BYTES
            ) {
                throw new RuntimeException('Manifest file boundary rejected.');
            }
            $raw = stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
        if (! is_string($raw)) {
            throw new RuntimeException('Manifest read rejected.');
        }
        if (strlen($raw) < 2 || strlen($raw) > self::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('Manifest file boundary rejected.');
        }
        $this->assertNoDuplicateJsonKeys($raw);
        $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Manifest JSON rejected.');
        }

        return [
            'data' => $data,
            'sha256' => hash('sha256', $raw),
            'path' => $realPath,
        ];
    }

    private function assertWritableProtectedOutput(string $path): void
    {
        if (
            $path === ''
            || ! str_starts_with($path, DIRECTORY_SEPARATOR)
            || file_exists($path)
            || is_link($path)
        ) {
            throw new RuntimeException('Rollback output rejected.');
        }
        $directory = realpath(dirname($path));
        if (
            $directory === false
            || ! hash_equals($directory.DIRECTORY_SEPARATOR.basename($path), $path)
            || $this->hasSymlinkComponent($directory)
            || ! is_dir($directory)
            || ! is_writable($directory)
        ) {
            throw new RuntimeException('Rollback directory rejected.');
        }
        $this->assertProtectedLocation($directory.DIRECTORY_SEPARATOR.'output.json');
        $permissions = fileperms($directory);
        if (! is_int($permissions) || ($permissions & 0077) !== 0) {
            throw new RuntimeException('Rollback directory permissions rejected.');
        }
    }

    /**
     * @return resource
     */
    private function acquireProtectedPathLock(string $path)
    {
        if ($path === '' || ! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Protected lock path rejected.');
        }
        $directory = realpath(dirname($path));
        if (
            $directory === false
            || ! hash_equals($directory.DIRECTORY_SEPARATOR.basename($path), $path)
            || $this->hasSymlinkComponent($directory)
        ) {
            throw new RuntimeException('Protected lock directory rejected.');
        }
        $this->assertProtectedLocation($directory.DIRECTORY_SEPARATOR.'lock');
        $directoryPermissions = fileperms($directory);
        if (! is_int($directoryPermissions) || ($directoryPermissions & 0077) !== 0) {
            throw new RuntimeException('Protected lock directory permissions rejected.');
        }

        $lockPath = $directory.'/.'.hash('sha256', basename($path)).'.fixture.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('Protected lock file rejected.');
        }
        $handle = @fopen($lockPath, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Protected lock open rejected.');
        }
        if (! chmod($lockPath, 0600)) {
            fclose($handle);
            throw new RuntimeException('Protected lock permissions rejected.');
        }
        $stat = fstat($handle);
        if (
            ! is_array($stat)
            || ($stat['mode'] & 0170000) !== 0100000
            || ($stat['mode'] & 0777) !== 0600
            || ! flock($handle, LOCK_EX | LOCK_NB)
        ) {
            fclose($handle);
            throw new RuntimeException('Protected lock rejected.');
        }

        return $handle;
    }

    /** @param resource $handle */
    private function releaseProtectedPathLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function assertProtectedLocation(string $path): void
    {
        $repository = realpath(dirname(base_path()));
        $public = realpath(public_path());
        $publicStorage = realpath(storage_path('app/public'));
        foreach ([$repository, $public, $publicStorage] as $forbiddenRoot) {
            if (is_string($forbiddenRoot) && ($path === $forbiddenRoot || str_starts_with($path, $forbiddenRoot.DIRECTORY_SEPARATOR))) {
                throw new RuntimeException('Protected path rejected.');
            }
        }
        if (! app()->environment('testing')) {
            foreach (['/tmp', '/private/tmp', '/var/tmp', rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)] as $temporaryRoot) {
                if ($path === $temporaryRoot || str_starts_with($path, $temporaryRoot.DIRECTORY_SEPARATOR)) {
                    throw new RuntimeException('Temporary path rejected.');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    protected function writeJsonAtomically(string $path, array $document, bool $overwrite = false): void
    {
        if (! $overwrite && file_exists($path)) {
            throw new RuntimeException('Rollback output already exists.');
        }
        $temporaryPath = dirname($path).'/.'.basename($path).'.'.bin2hex(random_bytes(8)).'.tmp';
        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $handle = fopen($temporaryPath, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('Rollback temp file rejected.');
        }
        try {
            if (! chmod($temporaryPath, 0600)) {
                throw new RuntimeException('Rollback temp permissions rejected.');
            }
            if (fwrite($handle, $json) !== strlen($json) || ! fflush($handle)) {
                throw new RuntimeException('Rollback file write rejected.');
            }
            if (function_exists('fsync') && ! fsync($handle)) {
                throw new RuntimeException('Rollback file sync rejected.');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($temporaryPath);

            throw $exception;
        }
        fclose($handle);
        if ($overwrite) {
            if (! @rename($temporaryPath, $path)) {
                @unlink($temporaryPath);
                throw new RuntimeException('Rollback file publish rejected.');
            }
        } else {
            if (! @link($temporaryPath, $path)) {
                @unlink($temporaryPath);
                throw new RuntimeException('Rollback file publish rejected.');
            }
            @unlink($temporaryPath);
        }
        $permissions = fileperms($path);
        if (! is_int($permissions) || ($permissions & 0777) !== 0600) {
            throw new RuntimeException('Rollback file permissions rejected.');
        }
        try {
            $this->syncDirectory(dirname($path));
        } catch (Throwable $exception) {
            if (! $overwrite) {
                @unlink($path);
                try {
                    $this->syncDirectory(dirname($path));
                } catch (Throwable) {
                    // Исходная ошибка синхронизации остаётся основной.
                }
            }

            throw $exception;
        }
    }

    protected function checkpoint(string $name): void
    {
        // Точка внедрения сбоев для проверки атомарности транзакции.
    }

    private function syncDirectory(string $directory): void
    {
        if (! function_exists('fsync')) {
            throw new RuntimeException('Directory sync unavailable.');
        }
        $handle = @fopen($directory, 'r');
        if ($handle === false) {
            throw new RuntimeException('Directory sync open rejected.');
        }
        try {
            if (! fsync($handle)) {
                throw new RuntimeException('Directory sync rejected.');
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, KinescopeLegacyVideoAllowlistEntry>  $entries
     */
    private function allowlistHash(array $entries): string
    {
        $lines = [];
        foreach ($entries as $entry) {
            if (! is_string($entry->embed_locator) || ! is_string($entry->provider_video_id)) {
                throw new RuntimeException('Allowlist entry rejected.');
            }
            $lines[] = $entry->embed_locator."\t".$entry->provider_video_id;
        }

        return hash('sha256', implode("\n", $lines)."\n");
    }

    private function effectiveLessonGrants(int $lessonId)
    {
        return UserEntitlementGrant::query()
            ->where('atom_kind', UserEntitlementGrant::ATOM_LESSON)
            ->where('atom_id', $lessonId)
            ->where('status', UserEntitlementGrant::STATUS_ACTIVE)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    private function lockFixtureTablesForWrite(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite' && app()->environment('testing')) {
            return;
        }
        if ($driver !== 'pgsql') {
            throw new RuntimeException('Fixture database driver rejected.');
        }

        DB::statement("SET LOCAL lock_timeout TO '5s'");
        DB::statement("SET LOCAL statement_timeout TO '25s'");
        DB::statement("SET LOCAL idle_in_transaction_session_timeout TO '30s'");
        DB::statement("SET LOCAL transaction_timeout TO '30s'");
        DB::statement(<<<'SQL'
            LOCK TABLE
                lesson_learning_contents,
                legacy_import_mappings,
                lesson_materials,
                lesson_material_kinescope_videos,
                legacy_protected_asset_mappings,
                user_entitlement_grants
            IN SHARE ROW EXCLUSIVE MODE
            SQL);
    }

    /**
     * @return array<string, string>
     */
    private function ownership(string $fixtureKey, string $manifestHash, string $planHash): array
    {
        return [
            'fixture_owner' => self::FIXTURE_OWNER,
            'fixture_key_sha256' => hash('sha256', $fixtureKey),
            'create_manifest_sha256' => $manifestHash,
            'plan_sha256' => $planHash,
        ];
    }

    /**
     * @param  array<string, string>  $metadata
     */
    private function mergeMetadata(Model $model, array $metadata): void
    {
        $current = is_array($model->metadata) ? $model->metadata : [];
        $model->metadata = [...$current, ...$metadata];
        $model->save();
    }

    private function lessonPreconditionHash(Lesson $lesson): string
    {
        return $this->canonicalHash([
            'id' => (int) $lesson->id,
            'course_id' => (int) $lesson->course_id,
            'slug' => $lesson->slug,
            'title' => $lesson->title,
            'sort_order' => (int) $lesson->sort_order,
            'is_active' => (bool) $lesson->is_active,
            'legacy_product_id' => $lesson->legacy_product_id,
            'legacy_url' => $lesson->legacy_url,
            'legacy_price_cents' => $lesson->legacy_price_cents,
            'metadata' => $lesson->metadata,
        ]);
    }

    private function coursePreconditionHash(Course $course): string
    {
        return $this->canonicalHash([
            'id' => (int) $course->id,
            'subject_id' => (int) $course->subject_id,
            'slug' => $course->slug,
            'title' => $course->title,
            'short_title' => $course->short_title,
            'price_cents' => (int) $course->price_cents,
            'sort_order' => (int) $course->sort_order,
            'is_active' => (bool) $course->is_active,
        ]);
    }

    private function subjectPreconditionHash(Subject $subject): string
    {
        return $this->canonicalHash([
            'id' => (int) $subject->id,
            'slug' => $subject->slug,
            'title' => $subject->title,
            'sort_order' => (int) $subject->sort_order,
            'is_active' => (bool) $subject->is_active,
        ]);
    }

    private function userPreconditionHash(User $user): string
    {
        return $this->canonicalHash([
            'id' => (int) $user->id,
            'role' => $user->role,
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonicalHash(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            if ($value instanceof CarbonImmutable) {
                return $value->toIso8601ZuluString();
            }

            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /**
     * @return array{exit_code:int, output:array<string, int|string>}
     */
    private function successOutput(
        string $mode,
        string $status,
        string $manifestHash,
        string $planHash,
        string $countKey,
        int $count,
    ): array {
        return [
            'exit_code' => 0,
            'output' => [
                'mode' => $mode,
                'status' => $status,
                'manifest_sha256' => $manifestHash,
                'plan_sha256' => $planHash,
                $countKey => $count,
            ],
        ];
    }

    /**
     * @return array{exit_code:int, output:array<string, int|string>}
     */
    private function failureOutput(string $mode, string $status, string $manifestHash, string $planHash): array
    {
        return [
            'exit_code' => 1,
            'output' => [
                'mode' => $mode,
                'status' => $status,
                'manifest_sha256' => $manifestHash,
                'plan_sha256' => $planHash,
            ],
        ];
    }

    private function assertConfirmation(?string $actual, string $expected): void
    {
        if (! is_string($actual) || ! hash_equals($expected, $actual)) {
            throw new RuntimeException('Confirmation rejected.');
        }
    }

    private function assertHash(?string $value): void
    {
        if (! is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            throw new RuntimeException('Hash rejected.');
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<int, string>  $expected
     */
    private function assertExactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('Manifest fields rejected.');
        }
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function arrayValue(array $value, string $key): array
    {
        if (! isset($value[$key]) || ! is_array($value[$key]) || array_is_list($value[$key])) {
            throw new RuntimeException('Manifest object rejected.');
        }

        return $value[$key];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function stringValue(array $value, string $key): string
    {
        if (! isset($value[$key]) || ! is_string($value[$key]) || $value[$key] === '') {
            throw new RuntimeException('Manifest string rejected.');
        }

        return $value[$key];
    }

    private function positiveInteger(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new RuntimeException('Positive integer rejected.');
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function utcDate(array $value, string $key): CarbonImmutable
    {
        $raw = $this->stringValue($value, $key);
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/D', $raw) !== 1) {
            throw new RuntimeException('UTC timestamp rejected.');
        }

        return CarbonImmutable::parse($raw, 'UTC');
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function nullableUtcDate(array $value, string $key): ?CarbonImmutable
    {
        if (! array_key_exists($key, $value) || $value[$key] === null) {
            return null;
        }

        return $this->utcDate($value, $key);
    }

    private function hasSymlinkComponent(string $path): bool
    {
        $current = DIRECTORY_SEPARATOR;
        foreach (explode(DIRECTORY_SEPARATOR, ltrim($path, DIRECTORY_SEPARATOR)) as $component) {
            if ($component === '') {
                continue;
            }
            $current = rtrim($current, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$component;
            if (is_link($current)) {
                return true;
            }
        }

        return false;
    }

    private function assertNoDuplicateJsonKeys(string $json): void
    {
        $offset = 0;
        $this->parseJsonValue($json, $offset);
        $this->skipJsonWhitespace($json, $offset);
        if ($offset !== strlen($json)) {
            throw new RuntimeException('JSON trailing data rejected.');
        }
    }

    private function parseJsonValue(string $json, int &$offset, int $depth = 0): void
    {
        if ($depth > 64) {
            throw new RuntimeException('JSON nesting rejected.');
        }
        $this->skipJsonWhitespace($json, $offset);
        $character = $json[$offset] ?? null;
        if ($character === '{') {
            $this->parseJsonObject($json, $offset, $depth);

            return;
        }
        if ($character === '[') {
            $this->parseJsonArray($json, $offset, $depth);

            return;
        }
        if ($character === '"') {
            $this->parseJsonString($json, $offset);

            return;
        }
        if (preg_match('/\G(?:-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?|true|false|null)/A', $json, $match, 0, $offset) !== 1) {
            throw new RuntimeException('JSON value rejected.');
        }
        $offset += strlen($match[0]);
    }

    private function parseJsonObject(string $json, int &$offset, int $depth): void
    {
        $offset++;
        $keys = [];
        $this->skipJsonWhitespace($json, $offset);
        if (($json[$offset] ?? null) === '}') {
            $offset++;

            return;
        }
        while (true) {
            $rawKey = $this->parseJsonString($json, $offset);
            $key = json_decode($rawKey, true, 2, JSON_THROW_ON_ERROR);
            if (! is_string($key) || isset($keys[$key])) {
                throw new RuntimeException('Duplicate JSON key rejected.');
            }
            $keys[$key] = true;
            $this->skipJsonWhitespace($json, $offset);
            if (($json[$offset] ?? null) !== ':') {
                throw new RuntimeException('JSON object rejected.');
            }
            $offset++;
            $this->parseJsonValue($json, $offset, $depth + 1);
            $this->skipJsonWhitespace($json, $offset);
            $delimiter = $json[$offset] ?? null;
            if ($delimiter === '}') {
                $offset++;

                return;
            }
            if ($delimiter !== ',') {
                throw new RuntimeException('JSON object delimiter rejected.');
            }
            $offset++;
            $this->skipJsonWhitespace($json, $offset);
        }
    }

    private function parseJsonArray(string $json, int &$offset, int $depth): void
    {
        $offset++;
        $this->skipJsonWhitespace($json, $offset);
        if (($json[$offset] ?? null) === ']') {
            $offset++;

            return;
        }
        while (true) {
            $this->parseJsonValue($json, $offset, $depth + 1);
            $this->skipJsonWhitespace($json, $offset);
            $delimiter = $json[$offset] ?? null;
            if ($delimiter === ']') {
                $offset++;

                return;
            }
            if ($delimiter !== ',') {
                throw new RuntimeException('JSON array delimiter rejected.');
            }
            $offset++;
        }
    }

    private function parseJsonString(string $json, int &$offset): string
    {
        if (($json[$offset] ?? null) !== '"') {
            throw new RuntimeException('JSON string rejected.');
        }
        $start = $offset++;
        $length = strlen($json);
        while ($offset < $length) {
            $character = $json[$offset++];
            if ($character === '"') {
                return substr($json, $start, $offset - $start);
            }
            if ($character === '\\') {
                $escape = $json[$offset++] ?? null;
                if ($escape === 'u') {
                    $hex = substr($json, $offset, 4);
                    if (strlen($hex) !== 4 || preg_match('/^[0-9a-fA-F]{4}$/D', $hex) !== 1) {
                        throw new RuntimeException('JSON unicode escape rejected.');
                    }
                    $offset += 4;
                } elseif (! in_array($escape, ['"', '\\', '/', 'b', 'f', 'n', 'r', 't'], true)) {
                    throw new RuntimeException('JSON escape rejected.');
                }
            } elseif (ord($character) < 0x20) {
                throw new RuntimeException('JSON control character rejected.');
            }
        }

        throw new RuntimeException('JSON string termination rejected.');
    }

    private function skipJsonWhitespace(string $json, int &$offset): void
    {
        $length = strlen($json);
        while ($offset < $length && str_contains(" \t\r\n", $json[$offset])) {
            $offset++;
        }
    }
}
