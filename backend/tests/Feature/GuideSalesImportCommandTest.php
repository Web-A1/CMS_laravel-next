<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Guide;
use App\Models\Lesson;
use App\Models\LessonMaterial;
use App\Models\Order;
use App\Models\SellableEntity;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Services\GuideSales\GuideSalesImporter;
use App\Services\OrderItemSnapshotService;
use App\Services\ProtectedFiles\ProtectedFileSourceValidator;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class GuideSalesImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private string $manifestPath;

    private array $manifest;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = realpath(sys_get_temp_dir()).'/guide-import-test-'.Str::uuid();
        mkdir($this->directory, 0700);
        $this->manifestPath = $this->directory.'/manifest.json';
        $this->manifest = json_decode(file_get_contents(database_path('data/guide-sales-20261012.json')), true, 32, JSON_THROW_ON_ERROR);
        $pdf = new \FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->Cell(50, 10, 'Isolated guide source fixture');
        $bytes = $pdf->Output('S');
        foreach ($this->manifest['files'] as &$file) {
            $file['sha256'] = hash('sha256', $bytes);
            $file['size_bytes'] = strlen($bytes);
            $path = $this->directory.'/'.$file['legacy_file_id'].'.pdf';
            file_put_contents($path, $bytes);
            chmod($path, 0600);
        }
        unset($file);
        file_put_contents($this->manifestPath, json_encode($this->manifest, JSON_THROW_ON_ERROR));
        config(['protected_files.source_disk' => 'guide-test-source', 'filesystems.disks.guide-test-source' => ['driver' => 'local', 'root' => storage_path('framework/testing/guide-sources'), 'throw' => true]]);
        Storage::fake('guide-test-source');
        $subject = Subject::query()->create(['slug' => 'fixture', 'title' => 'Fixture', 'is_active' => true]);
        $this->course = Course::query()->create(['subject_id' => $subject->id, 'slug' => 'fixture-course', 'title' => 'Fixture course', 'is_active' => true, 'price_cents' => 100]);
        foreach (array_unique(array_merge(...array_column($this->manifest['offers'], 'lesson_page_ids'))) as $page) {
            $this->mapPage($page);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_dry_run_has_no_registry_storage_or_entitlement_writes(): void
    {
        $result = $this->preview();
        $this->assertSame('ready', $result['status']);
        $this->assertSame(27, $result['files']);
        $this->assertSame(33, $result['offers']);
        $this->assertDatabaseCount('guides', 0);
        $this->assertDatabaseCount('sellable_entities', 0);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
        $this->assertSame([], Storage::disk('guide-test-source')->allFiles());
        $this->artisan('guides:import-sales', ['sources' => $this->directory, '--manifest' => $this->manifestPath])->assertSuccessful();
    }

    public function test_apply_is_idempotent_reuses_shared_sources_and_preserves_bundle_prices(): void
    {
        // AC-GUIDE-IMPORT-POSITIVE AC-GUIDE-IMPORT-IDEMPOTENT AC-MIXED-BUNDLE-POSITIVE
        $this->assertSame(27, $this->apply()['source_changes']);
        $this->assertDatabaseCount('guides', 27);
        $this->assertDatabaseCount('lesson_materials', 27);
        $this->assertDatabaseCount('lesson_material_files', 27);
        $this->assertDatabaseCount('sellable_entities', 33);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
        $before = $this->registry();
        $this->assertSame(0, $this->apply()['source_changes']);
        $this->assertSame($before, $this->registry());
        $this->assertCount(27, Storage::disk('guide-test-source')->allFiles());
        foreach ([[183, 283, 7, 229000], [364, 363, 5, 190000]] as [$box, $single, $count, $price]) {
            $a = $this->sellable($box);
            $this->assertCount($count, $a->grantRules);
            $this->assertSame($price, $a->price_cents);
            $this->assertSame($this->sellable($single)->entity_id, $a->grantRules->firstWhere('atom_kind', 'guide')->atom_id);
        }
        foreach ([318 => 119000, 344 => 109000, 363 => 79000, 460 => 99000] as $id => $price) {
            $this->assertSame($price, $this->sellable($id)->price_cents);
        }
        $this->assertSame($this->sellable(211)->entity_id, $this->sellable(213)->entity_id);
        $this->assertSame($this->sellable(211)->grantRules->pluck('atom_id')->all(), $this->sellable(213)->grantRules->pluck('atom_id')->all());
        $this->assertCount(6, $this->sellable(211)->grantRules);
        $this->assertSame('ordinary', $this->sellable(211)->metadata['tariff']['code']);
        $this->assertSame('extended', $this->sellable(213)->metadata['tariff']['code']);
    }

    public function test_missing_ambiguous_mapping_or_duplicate_target_aborts_before_writes(): void
    {
        // AC-GUIDE-IMPORT-NEGATIVE
        DB::table('legacy_import_mappings')->where('source_id', '4')->delete();
        $this->denied('LESSON_MAPPING_MISSING_OR_AMBIGUOUS');
        $this->mapPage(4, 'restored');
        $this->mapPage(4, 'ambiguous');
        $this->denied('LESSON_MAPPING_MISSING_OR_AMBIGUOUS');
        DB::table('legacy_import_mappings')->where('source_scope_id', 'ambiguous')->delete();
        $target = DB::table('legacy_import_mappings')->where('source_id', '50')->value('target_id');
        DB::table('legacy_import_mappings')->where('source_id', '51')->update(['target_id' => $target]);
        $this->denied('DUPLICATE_TARGET_ATOM');
    }

    public function test_corrupt_source_and_wrong_confirmation_fail_before_writes(): void
    {
        // AC-REAL-PDF-INTEGRITY-DENIED AC-GUIDE-IMPORT-NEGATIVE
        $plan = $this->preview();
        try {
            app(GuideSalesImporter::class)->execute($this->manifestPath, $this->directory, true, str_repeat('0', 64), $plan['plan']);
            $this->fail('Wrong manifest digest accepted');
        } catch (DomainException $error) {
            $this->assertSame('CONFIRMATION_MISMATCH', $error->getMessage());
        }
        file_put_contents($this->directory.'/1075.pdf', '%PDF-corrupt');
        clearstatcache();
        $this->denied('SOURCE_MISMATCH');
    }

    public function test_history_is_unchanged_and_fresh_ids_are_above_all_historical_atoms(): void
    {
        // AC-HISTORY-PRESERVED CLAIM-HISTORY-PRESERVED
        $user = User::factory()->create();
        UserEntitlementGrant::query()->create(['user_id' => $user->id, 'atom_kind' => 'guide', 'atom_id' => 900, 'source_type' => 'migration', 'idempotency_key' => 'historical-guide']);
        $old = SellableEntity::query()->create(['sku' => 'historical-guide', 'sellable_kind' => 'guide', 'entity_type' => 'guide', 'entity_id' => 1200, 'title' => 'Historical', 'price_cents' => 100, 'status' => 'active']);
        $old->grantRules()->create(['atom_kind' => 'guide', 'atom_id' => 1200, 'grant_snapshot_version' => 1, 'sort_order' => 0]);
        $order = Order::query()->create(['user_id' => $user->id, 'currency' => 'RUB', 'amount_total' => 100, 'amount_discount' => 0, 'amount_final' => 100]);
        $item = app(OrderItemSnapshotService::class)->createSellableItem($order, $old);
        // A historical order may reference a larger atom than any current rule.
        $snapshot = $item->grant_snapshot;
        $snapshot['atoms'][0]['id'] = 1600;
        $item->update(['grant_snapshot' => $snapshot]);
        $before = $this->history();
        $this->apply();
        $this->assertGreaterThan(1600, Guide::query()->min('id'));
        $this->assertSame($before, $this->history());
        $this->assertSame(1200, $old->fresh()->grantRules->sole()->atom_id);
    }

    public function test_unproven_existing_guide_cannot_capture_historical_atom(): void
    {
        // AC-HISTORY-COLLISION-DENIED
        $file = $this->manifest['files'][0];
        $guide = Guide::query()->create(['legacy_file_id' => $file['legacy_file_id'], 'slug' => 'legacy-file-'.$file['legacy_file_id'], 'title' => $file['title']]);
        LessonMaterial::query()->create(['guide_id' => $guide->id, 'kind' => 'pdf', 'title' => $file['title']]);
        UserEntitlementGrant::query()->create(['user_id' => User::factory()->create()->id, 'atom_kind' => 'guide', 'atom_id' => $guide->id, 'source_type' => 'migration', 'idempotency_key' => 'collision']);
        $this->expectExceptionMessage('HISTORICAL_GUIDE_COLLISION');
        $this->preview();
    }

    public function test_partial_source_validation_failure_keeps_drafts_and_retry_converges(): void
    {
        $real = app(ProtectedFileSourceValidator::class);
        $this->mock(ProtectedFileSourceValidator::class)->shouldReceive('validate')->once()->andThrow(new RuntimeException('fixture validation failure'));
        try {
            $this->apply();
            $this->fail('Validation failure accepted');
        } catch (RuntimeException $error) {
            $this->assertSame('fixture validation failure', $error->getMessage());
        }
        $this->assertSame(0, Guide::query()->where('is_active', true)->count());
        $this->assertDatabaseCount('sellable_entities', 0);
        $this->assertDatabaseCount('lesson_material_files', 1);
        $this->app->instance(ProtectedFileSourceValidator::class, $real);
        $this->assertSame(26, $this->apply()['source_changes']);
        $this->assertSame(27, Guide::query()->where('is_active', true)->count());
        $this->assertDatabaseCount('sellable_entities', 33);
    }

    public function test_new_composition_appends_rules_and_does_not_rewrite_purchase(): void
    {
        // AC-HISTORY-SNAPSHOT-DRIFT AC-MIXED-BUNDLE-HISTORY
        $this->apply();
        $offer = $this->sellable(211);
        $order = Order::query()->create(['user_id' => User::factory()->create()->id, 'currency' => 'RUB', 'amount_total' => $offer->price_cents, 'amount_discount' => 0, 'amount_final' => $offer->price_cents]);
        $item = app(OrderItemSnapshotService::class)->createSellableItem($order, $offer);
        $before = $item->fresh()->getAttributes();
        $oldRules = $offer->grantRules()->get()->map(fn ($r) => $r->getAttributes())->all();
        DB::table('legacy_import_mappings')->where('source_id', '50')->delete();
        $this->mapPage(50, 'replacement');
        $this->apply();
        $this->assertSame($before, $item->fresh()->getAttributes());
        $this->assertSame($oldRules, $offer->grantRules()->where('grant_snapshot_version', 1)->get()->map(fn ($r) => $r->getAttributes())->all());
        $this->assertSame(2, (int) $offer->grantRules()->max('grant_snapshot_version'));
    }

    private function mapPage(int $page, string $scope = 'fixture'): void
    {
        $lesson = Lesson::query()->create(['course_id' => $this->course->id, 'slug' => 'page-'.$page.'-'.$scope, 'title' => 'Fixture '.$page, 'is_active' => true]);
        $content = DB::table('lesson_learning_contents')->insertGetId(['lesson_id' => $lesson->id, 'status' => 'published', 'body' => '{}']);
        DB::table('legacy_import_mappings')->insert(['batch_key' => 'isolated-fixture', 'source_table' => 'platform_page', 'source_id' => (string) $page, 'source_scope_type' => 'fixture', 'source_scope_id' => $scope, 'target_domain' => 'learning_lesson_body', 'target_type' => 'lesson_learning_content', 'target_id' => $content, 'mapping_status' => 'mapped', 'idempotency_key' => 'fixture-'.$page.'-'.$scope, 'source_fingerprint_hash' => str_repeat('a', 64)]);
    }

    private function preview(): array
    {
        return app(GuideSalesImporter::class)->execute($this->manifestPath, $this->directory);
    }

    private function apply(): array
    {
        $preview = $this->preview();

        return app(GuideSalesImporter::class)->execute($this->manifestPath, $this->directory, true, $preview['manifest_sha256'], $preview['plan']);
    }

    private function denied(string $code): void
    {
        try {
            $this->preview();
            $this->fail('Invalid input accepted');
        } catch (DomainException $error) {
            $this->assertSame($code, $error->getMessage());
        }
        $this->assertDatabaseCount('guides', 0);
        $this->assertDatabaseCount('sellable_entities', 0);
        $this->assertSame([], Storage::disk('guide-test-source')->allFiles());
    }

    private function sellable(int $id): SellableEntity
    {
        return SellableEntity::query()->where('sku', 'legacy-product-'.$id)->sole();
    }

    private function registry(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['guides', 'lesson_materials', 'lesson_material_files', 'sellable_entities', 'sellable_grant_rules']);
    }

    private function history(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['user_entitlement_grants', 'orders', 'order_items', 'legacy_import_mappings']);
    }
}
