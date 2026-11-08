<?php

namespace App\Services\GuideSales;

use App\Models\Guide;
use App\Models\LessonMaterial;
use App\Models\OrderItem;
use App\Models\SellableEntity;
use App\Services\ProtectedFiles\ProtectedFileSourceIngestor;
use App\Services\ProtectedFiles\ProtectedFileSourceValidator;
use App\Support\SellableTariff;
use DomainException;
use Illuminate\Support\Facades\DB;

class GuideSalesImporter
{
    public function __construct(
        private readonly ProtectedFileSourceIngestor $ingestor,
        private readonly ProtectedFileSourceValidator $validator,
    ) {}

    public function execute(string $manifestPath, string $sourceDirectory, bool $apply = false, ?string $manifestConfirmation = null, ?string $planConfirmation = null): array
    {
        $raw = file_get_contents($manifestPath);
        $this->require(is_string($raw) && strlen($raw) <= 1024 * 1024, 'INVALID_MANIFEST');
        $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        $this->validateManifest($manifest);
        $manifestHash = hash('sha256', $raw);
        $directory = realpath($sourceDirectory);
        $this->require($directory !== false && $directory === $sourceDirectory && ! is_link($directory) && ! str_starts_with($directory.'/', base_path().'/'), 'UNSAFE_SOURCE_DIRECTORY');
        $this->verifySources($manifest['files'], $directory);
        $state = $this->preflight($manifest);
        $plan = $this->digest([$manifestHash, $directory, DB::connection()->getDriverName(), DB::connection()->getDatabaseName(), $state]);
        $result = ['status' => 'ready', 'mode' => 'dry-run', 'manifest_sha256' => $manifestHash, 'plan' => $plan, 'files' => count($manifest['files']), 'offers' => count($manifest['offers'])];
        if (! $apply) {
            return $result;
        }
        $this->require(is_string($manifestConfirmation) && hash_equals($manifestHash, $manifestConfirmation) && is_string($planConfirmation) && hash_equals($plan, $planConfirmation), 'CONFIRMATION_MISMATCH');

        // Keep the import serialized across source IO; SQL rollback cannot undo object storage.
        $lock = $this->lock();
        try {
            $this->require($state === $this->preflight($manifest), 'TARGET_CHANGED');
            DB::transaction(function () use ($manifest): void {
                $nextId = $this->maximumGuideAtomId();
                foreach ($manifest['files'] as $file) {
                    $guide = Guide::query()->where('legacy_file_id', $file['legacy_file_id'])->first();
                    if ($guide === null) {
                        $guide = new Guide;
                        $guide->forceFill(['id' => ++$nextId, 'legacy_file_id' => $file['legacy_file_id'], 'slug' => 'legacy-file-'.$file['legacy_file_id'], 'title' => $file['title'], 'is_active' => false])->save();
                        LessonMaterial::query()->create(['guide_id' => $guide->id, 'lesson_id' => null, 'kind' => 'pdf', 'title' => $file['title'], 'is_published' => false]);
                    }
                }
                $this->advanceSequence();
            });

            $sourceChanges = 0;
            foreach ($manifest['files'] as $file) {
                $guide = Guide::query()->where('legacy_file_id', $file['legacy_file_id'])->sole();
                $material = LessonMaterial::query()->where('guide_id', $guide->id)->sole();
                $arguments = [$material->id, $directory.'/'.$file['legacy_file_id'].'.pdf', 'guide-'.$file['legacy_file_id'].'.pdf'];
                $expectation = ['sha256' => $file['sha256'], 'size' => $file['size_bytes'], 'mime' => 'application/pdf'];
                $preview = $this->ingestor->execute(...[...$arguments, false, null, $expectation]);
                $receipt = $this->ingestor->execute(...[...$arguments, true, $preview['plan'], $expectation]);
                $sourceChanges += $receipt['status'] === 'no_change' ? 0 : 1;
                $source = $material->protectedFile()->sole();
                $this->validator->validate($source->source_object_key, $file['sha256'], $file['size_bytes'], 'application/pdf', $source->download_filename);
            }

            DB::transaction(function () use ($manifest, $state): void {
                $current = $this->preflight($manifest);
                $this->require($current['lessons'] === $state['lessons'], 'TARGET_CHANGED');
                foreach ($manifest['files'] as $file) {
                    $guide = Guide::query()->where('legacy_file_id', $file['legacy_file_id'])->sole();
                    if (! $guide->is_active) {
                        $guide->update(['is_active' => true]);
                    }
                    LessonMaterial::query()->where('guide_id', $guide->id)->where('is_published', false)->update(['is_published' => true]);
                }
                foreach ($manifest['offers'] as $offer) {
                    $this->publishOffer($offer, $current['lessons']);
                }
            });

            return [...$result, 'status' => 'applied', 'mode' => 'apply', 'source_changes' => $sourceChanges];
        } finally {
            $this->unlock($lock);
        }
    }

    private function validateManifest(mixed $manifest): void
    {
        $this->require(is_array($manifest) && ($manifest['version'] ?? null) === 1 && is_array($manifest['files'] ?? null) && is_array($manifest['offers'] ?? null), 'INVALID_MANIFEST');
        $this->require(count($manifest['files']) === 27 && count($manifest['offers']) === 33, 'INVALID_SCOPE');
        $files = [];
        foreach ($manifest['files'] as $file) {
            $this->require(is_array($file) && is_int($file['legacy_file_id'] ?? null) && $file['legacy_file_id'] > 0 && ! isset($files[$file['legacy_file_id']]), 'INVALID_FILE_IDENTITY');
            $this->require($this->title($file['title'] ?? null) && is_string($file['sha256'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/', $file['sha256']) === 1 && is_int($file['size_bytes'] ?? null) && $file['size_bytes'] > 0 && $file['size_bytes'] <= 300 * 1024 * 1024, 'INVALID_FILE_METADATA');
            $files[$file['legacy_file_id']] = $file;
        }
        $products = $standalone = [];
        $links = 0;
        foreach ($manifest['offers'] as $offer) {
            $id = $offer['legacy_product_id'] ?? null;
            $this->require(is_int($id) && $id > 0 && ! isset($products[$id]), 'INVALID_PRODUCT_IDENTITY');
            $products[$id] = true;
            $this->require($this->title($offer['title'] ?? null) && in_array($offer['kind'] ?? null, ['guide', 'box', 'lesson', 'course'], true) && is_int($offer['price_cents'] ?? null) && $offer['price_cents'] > 0 && ($offer['currency'] ?? null) === 'RUB', 'INVALID_OFFER');
            $pages = $offer['lesson_page_ids'] ?? null;
            $this->require(is_array($pages) && array_is_list($pages) && count($pages) === count(array_unique($pages)), 'INVALID_LESSON_IDS');
            foreach ($pages as $page) {
                $this->require(is_int($page) && $page > 0, 'INVALID_LESSON_IDS');
            }
            $fileId = $offer['legacy_file_id'] ?? null;
            $this->require($fileId === null || (is_int($fileId) && isset($files[$fileId])), 'INVALID_FILE_LINK');
            $valid = match ($offer['kind']) {
                'guide' => $fileId !== null && $pages === [],
                'box' => $fileId !== null && $pages !== [],
                'lesson' => $fileId === null && count($pages) === 1,
                'course' => $fileId === null && $pages !== [],
            };
            $this->require($valid, 'INVALID_OFFER_COMPOSITION');
            SellableTariff::fromMetadata(['tariff' => $offer['tariff'] ?? null]);
            if ($fileId !== null) {
                $links++;
            }
            if ($offer['kind'] === 'guide') {
                $this->require(! isset($standalone[$fileId]) && $files[$fileId]['title'] === $offer['title'], 'AMBIGUOUS_GUIDE_TITLE');
                $standalone[$fileId] = true;
            }
        }
        $this->require($links === 29 && count($standalone) === 27, 'INVALID_SCOPE');
    }

    private function verifySources(array $files, string $directory): void
    {
        foreach ($files as $file) {
            $path = $directory.'/'.$file['legacy_file_id'].'.pdf';
            $this->require(realpath($path) === $path && ! is_link($path) && is_file($path) && (fileperms($path) & 0077) === 0, 'UNSAFE_SOURCE_FILE');
            $this->require(filesize($path) === $file['size_bytes'] && hash_equals($file['sha256'], hash_file('sha256', $path)), 'SOURCE_MISMATCH');
            $this->require(file_get_contents($path, false, null, 0, 5) === '%PDF-', 'SOURCE_NOT_PDF');
        }
    }

    private function preflight(array $manifest): array
    {
        $pages = array_values(array_unique(array_merge(...array_column($manifest['offers'], 'lesson_page_ids'))));
        sort($pages);
        $lessons = [];
        foreach ($pages as $page) {
            $candidates = DB::table('legacy_import_mappings as m')->join('lesson_learning_contents as c', 'c.id', '=', 'm.target_id')
                ->join('lessons as l', 'l.id', '=', 'c.lesson_id')->join('courses as co', 'co.id', '=', 'l.course_id')->join('subjects as s', 's.id', '=', 'co.subject_id')
                ->where('m.source_table', 'platform_page')->where('m.source_id', (string) $page)->where('m.mapping_status', 'mapped')
                ->where('m.target_domain', 'learning_lesson_body')->where('m.target_type', 'lesson_learning_content')
                ->where('l.is_active', true)->where('co.is_active', true)->where('s.is_active', true)
                ->select(['l.id', 'l.course_id'])->distinct()->get();
            $this->require($candidates->count() === 1, 'LESSON_MAPPING_MISSING_OR_AMBIGUOUS');
            $lessons[$page] = ['id' => (int) $candidates[0]->id, 'course_id' => (int) $candidates[0]->course_id];
        }
        $guides = [];
        foreach ($manifest['files'] as $file) {
            $guide = Guide::query()->where('legacy_file_id', $file['legacy_file_id'])->first();
            $slug = 'legacy-file-'.$file['legacy_file_id'];
            $this->require(! Guide::query()->where('slug', $slug)->when($guide, fn ($q) => $q->where('id', '!=', $guide->id))->exists(), 'GUIDE_IDENTITY_CONFLICT');
            if ($guide === null) {
                $guides[] = null;

                continue;
            }
            $materials = LessonMaterial::query()->with('protectedFile')->where('guide_id', $guide->id)->get();
            $this->require($guide->slug === $slug && $guide->title === $file['title'] && $materials->count() === 1, 'GUIDE_IDENTITY_CONFLICT');
            $material = $materials[0];
            $this->require($material->lesson_id === null && $material->kind === 'pdf' && $material->title === $file['title'], 'GUIDE_OWNER_CONFLICT');
            $source = $material->protectedFile;
            $this->require($source !== null || ! $this->hasHistoricalAtom((int) $guide->id), 'HISTORICAL_GUIDE_COLLISION');
            $this->require($source === null || ($source->source_sha256 === $file['sha256'] && $source->source_size_bytes === $file['size_bytes']), 'SOURCE_IDENTITY_CONFLICT');
            $this->require(! $guide->is_active || ($source !== null && $material->is_published), 'ACTIVE_GUIDE_INCOMPLETE');
            $guides[] = [$guide->getAttributes(), $material->getAttributes(), $source?->getAttributes()];
        }
        $sellables = [];
        foreach ($manifest['offers'] as $offer) {
            $targetIds = array_map(fn ($page) => $lessons[$page]['id'], $offer['lesson_page_ids']);
            $this->require(count($targetIds) === count(array_unique($targetIds)), 'DUPLICATE_TARGET_ATOM');
            if ($offer['kind'] === 'course') {
                $this->require(count(array_unique(array_map(fn ($page) => $lessons[$page]['course_id'], $offer['lesson_page_ids']))) === 1, 'COURSE_MAPPING_CONFLICT');
            }
            $sellable = SellableEntity::query()->where('sku', 'legacy-product-'.$offer['legacy_product_id'])->first();
            if ($sellable !== null) {
                $this->require(($sellable->metadata['guide_sales'] ?? null) === $this->ownership($offer), 'SELLABLE_IDENTITY_CONFLICT');
            }
            $sellables[] = $sellable === null ? null : [$sellable->getAttributes(), $sellable->grantRules()->orderBy('id')->get()->map(fn ($rule) => $rule->getAttributes())->all()];
        }

        return ['lessons' => $lessons, 'guides' => $guides, 'sellables' => $sellables, 'maximum_guide_id' => $this->maximumGuideAtomId()];
    }

    private function publishOffer(array $offer, array $lessons): void
    {
        $guideId = $offer['legacy_file_id'] === null ? null : (int) Guide::query()->where('legacy_file_id', $offer['legacy_file_id'])->sole()->id;
        $atoms = $guideId === null ? [] : [['kind' => 'guide', 'id' => $guideId]];
        foreach ($offer['lesson_page_ids'] as $page) {
            $atoms[] = ['kind' => 'lesson', 'id' => $lessons[$page]['id']];
        }
        $this->require(count($atoms) === count(array_unique(array_map(fn ($atom) => $atom['kind'].':'.$atom['id'], $atoms))), 'DUPLICATE_TARGET_ATOM');
        $entityId = match ($offer['kind']) {
            'guide' => $guideId,
            'lesson' => $lessons[$offer['lesson_page_ids'][0]]['id'],
            'course' => $lessons[$offer['lesson_page_ids'][0]]['course_id'],
            'box' => null,
        };
        $sellable = SellableEntity::query()->firstOrNew(['sku' => 'legacy-product-'.$offer['legacy_product_id']]);
        $attributes = ['sellable_kind' => $offer['kind'], 'entity_type' => $offer['kind'], 'entity_id' => $entityId, 'title' => $offer['title'], 'currency' => $offer['currency'], 'price_cents' => $offer['price_cents'], 'status' => 'active', 'metadata' => ['guide_sales' => $this->ownership($offer), 'tariff' => $offer['tariff']]];
        $sellable->fill($attributes);
        if (! $sellable->exists || $sellable->isDirty()) {
            $sellable->price_version = $sellable->exists ? $sellable->price_version + 1 : 1;
            $sellable->save();
        }
        $version = (int) $sellable->grantRules()->max('grant_snapshot_version');
        $existing = $sellable->grantRules()->where('grant_snapshot_version', $version)->orderBy('sort_order')->orderBy('id')->get()->map(fn ($rule) => ['kind' => $rule->atom_kind, 'id' => $rule->atom_id])->all();
        if ($existing !== $atoms) {
            foreach ($atoms as $index => $atom) {
                $sellable->grantRules()->create(['atom_kind' => $atom['kind'], 'atom_id' => $atom['id'], 'grant_snapshot_version' => $version + 1, 'sort_order' => $index]);
            }
        }
    }

    private function maximumGuideAtomId(): int
    {
        $maximum = (int) Guide::query()->max('id');
        foreach (['user_entitlement_grants', 'sellable_grant_rules'] as $table) {
            $maximum = max($maximum, (int) DB::table($table)->where('atom_kind', 'guide')->max('atom_id'));
        }
        OrderItem::query()->select(['id', 'grant_snapshot'])->chunkById(200, function ($items) use (&$maximum): void {
            foreach ($items as $item) {
                foreach ($item->grant_snapshot['atoms'] ?? [] as $atom) {
                    if (($atom['kind'] ?? null) === 'guide') {
                        $id = $atom['id'] ?? null;
                        $this->require(is_int($id) && $id > 0 && $id < PHP_INT_MAX - 27, 'INVALID_HISTORICAL_GUIDE_ID');
                        $maximum = max($maximum, $id);
                    }
                }
            }
        });
        $this->require($maximum < PHP_INT_MAX - 27, 'GUIDE_ID_EXHAUSTED');

        return $maximum;
    }

    private function advanceSequence(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('guides', 'id'), GREATEST((SELECT COALESCE(MAX(id), 1) FROM guides), (SELECT last_value FROM guides_id_seq)), true)");
        }
    }

    private function hasHistoricalAtom(int $id): bool
    {
        foreach (['user_entitlement_grants', 'sellable_grant_rules'] as $table) {
            if (DB::table($table)->where('atom_kind', 'guide')->where('atom_id', $id)->exists()) {
                return true;
            }
        }
        $found = false;
        OrderItem::query()->select(['id', 'grant_snapshot'])->chunkById(200, function ($items) use ($id, &$found): void {
            foreach ($items as $item) {
                foreach ($item->grant_snapshot['atoms'] ?? [] as $atom) {
                    $found = $found || (($atom['kind'] ?? null) === 'guide' && ($atom['id'] ?? null) === $id);
                }
            }
        });

        return $found;
    }

    private function lock(): mixed
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_lock(20261012, 270033)');

            return null;
        }
        $lock = fopen(storage_path('framework/guide-sales-import.lock'), 'c');
        $this->require(is_resource($lock) && flock($lock, LOCK_EX), 'IMPORT_LOCK_UNAVAILABLE');

        return $lock;
    }

    private function unlock(mixed $lock): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_unlock(20261012, 270033)');
        } elseif (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function ownership(array $offer): array
    {
        return ['version' => 1, 'legacy_product_id' => $offer['legacy_product_id'], 'legacy_file_id' => $offer['legacy_file_id']];
    }

    private function title(mixed $value): bool
    {
        return is_string($value) && $value !== '' && trim($value) === $value && mb_strlen($value) <= 255 && ! preg_match('/[\x00-\x1F\x7F<>]/u', $value);
    }

    private function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new DomainException($code);
        }
    }
}
