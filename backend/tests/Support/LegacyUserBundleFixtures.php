<?php

namespace Tests\Support;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonLearningContent;
use App\Models\Subject;
use App\Services\LegacyUsers\LegacyUserBundle;
use App\Services\LegacyUsers\LegacyUserImporter;
use App\Services\LegacyUsers\LegacyUserRecordMapper;
use App\Services\LegacyUsers\LegacyUserSourcePolicy;
use App\Services\LegacyUsers\Readiness\ReadinessFiles;
use App\Services\LegacyUsers\Readiness\ReadOnlyProjection;
use Illuminate\Support\Facades\DB;
use PDO;

/** Synthetic records use the existing export policy and importer, never real source payloads. */
final class LegacyUserBundleFixtures
{
    public readonly string $root;

    private int $sequence = 0;

    public function __construct()
    {
        $this->root = realpath(sys_get_temp_dir()).'/legacy-readiness-test-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root.'/reports', 0700);
    }

    public static function envelope(int $id, ?int $member, string $state, array $row): array
    {
        return ['source_id' => $id, 'source_member_id' => $member, 'ownership_status' => $state, 'row' => ['id' => $id] + $row];
    }

    public static function records(): array
    {
        return [
            'member' => [self::envelope(13, 13, 'identity', [
                'email' => 'synthetic13@example.test', 'phone' => '+79990000013', 'firstname' => 'Synthetic', 'gender' => '', 'isBanned' => 0, 'status' => 10,
            ])],
            'order' => [self::envelope(5, 13, 'linked', ['memberId' => 13, 'amount' => '0.29', 'status' => 3])],
            'order_item' => [self::envelope(6, 13, 'linked', ['order_id' => 5, 'product_id' => 10, 'name' => 'Synthetic', 'quantity' => 2, 'priceSum' => '0.29', 'cost' => '-0.01'])],
            'platform_access' => [self::envelope(7, 13, 'linked', ['memberId' => 13, 'pageId' => 90, 'subjectId' => 0,
                'grantedBy' => 'order', 'grantedById' => 5, 'dateCreated' => '2026-09-30 12:00:00'])],
            'platform_page' => [self::envelope(90, null, 'reference', ['h1' => 'Synthetic page'])],
        ];
    }

    public function bundle(array $records, string $id = 'baseline', string $dumpHash = ''): string
    {
        $directory = $this->root.'/bundle-'.(++$this->sequence);
        mkdir($directory, 0700);
        $policy = app(LegacyUserSourcePolicy::class);
        $manifest = ['format' => 'legacy_user_records_v1', 'status' => 'complete', 'snapshot_id' => $id,
            'snapshot_sha256' => $dumpHash ?: str_repeat('a', 64),
            'policy_sha256' => hash_file('sha256', resource_path('legacy-users/source-policy-v1.json')),
            'source_database' => 'main_school_db', 'mapping_version' => 1, 'binary_files' => false,
            'messenger_history' => false, 'source_rows' => 0, 'exported_rows' => 0, 'tables' => []];
        foreach ($policy->tables() as $table => $definition) {
            $rows = $records[$table] ?? [];
            foreach ($rows as &$row) {
                $row['row'] += array_fill_keys(array_keys(array_filter($definition['fields'], fn (array $field): bool => $field['mode'] !== 'archive')), null);
            }
            unset($row);
            usort($rows, fn (array $a, array $b): int => $a['source_id'] <=> $b['source_id']);
            $entry = ['source_rows' => count($rows), 'exported_rows' => count($rows),
                'dispositions' => array_count_values(array_column($rows, 'ownership_status')), 'file' => null, 'sha256' => null];
            if ($definition['kind'] !== 'archive') {
                $body = implode('', array_map(fn (array $row): string => LegacyUserRecordMapper::json($row)."\n", $rows));
                $this->write($directory.'/'.$table.'.jsonl', $body);
                $entry['file'] = $table.'.jsonl';
                $entry['sha256'] = hash('sha256', $body);
            }
            $manifest['tables'][$table] = $entry;
            $manifest['source_rows'] += count($rows);
            $manifest['exported_rows'] += count($rows);
        }
        $this->write($directory.'/manifest.json', LegacyUserRecordMapper::json($manifest));

        return $directory;
    }

    public function import(string $directory): void
    {
        $bundle = new LegacyUserBundle($directory, app(LegacyUserSourcePolicy::class));
        $importer = app(LegacyUserImporter::class);
        $preview = $importer->preview($bundle);
        $importer->apply($bundle, $preview, hash('sha256', LegacyUserRecordMapper::json($preview)));
    }

    public function mapping(int $page = 90, bool $active = true): int
    {
        $sequence = ++$this->sequence;
        $subject = Subject::query()->create(['slug' => 'fixture-subject-'.$sequence, 'title' => 'Fixture', 'is_active' => $active]);
        $course = Course::query()->create(['subject_id' => $subject->id, 'slug' => 'fixture-course-'.$sequence, 'title' => 'Fixture', 'price_cents' => 100, 'is_active' => $active]);
        $lesson = Lesson::query()->create(['course_id' => $course->id, 'slug' => 'fixture-lesson-'.$sequence, 'title' => 'Fixture', 'is_active' => $active]);
        $content = LessonLearningContent::query()->create(['lesson_id' => $lesson->id, 'status' => 'published',
            'format' => 'lesson_blocks_v1', 'version' => 1, 'body' => ['blocks' => [], 'toc' => []], 'import_status' => 'manual']);
        DB::table('legacy_import_mappings')->insert(['batch_key' => 'fixture', 'source_table' => 'platform_page',
            'source_id' => (string) $page, 'source_scope_type' => 'fixture', 'source_scope_id' => (string) $sequence,
            'target_domain' => 'learning_lesson_body', 'target_type' => 'lesson_learning_content', 'target_id' => $content->id,
            'mapping_status' => 'mapped', 'validation_status' => 'passed', 'idempotency_key' => 'fixture-'.$sequence, 'source_fingerprint_hash' => str_repeat('a', 64)]);

        return $lesson->id;
    }

    public function projection(array $overrides = []): string
    {
        $path = $this->root.'/projection-'.(++$this->sequence).'.sqlite';
        $pdo = new PDO('sqlite:'.$path);
        chmod($path, 0600);
        $tables = [];
        $text = ['email', 'phone', 'password_state', 'source_fingerprint', 'mapping_status', 'ownership_status',
            'source_table', 'source_scope_type', 'source_scope_id', 'target_domain', 'target_type', 'status'];
        foreach (ReadOnlyProjection::TABLES as $table => $spec) {
            $columns = explode(' ', $spec);
            $definitions = array_map(fn (string $column): string => $column.(in_array($column, $text, true) || ($table === 'legacy_import_mappings' && $column === 'source_id') ? ' TEXT' : ' INTEGER'), $columns);
            $pdo->exec('CREATE TABLE '.$table.' ('.implode(',', $definitions).')');
            $insert = $pdo->prepare('INSERT INTO '.$table.' VALUES ('.implode(',', array_fill(0, count($columns), '?')).')');
            $rows = $overrides[$table] ?? DB::table($table)->orderBy($columns[0])->get($columns)->map(fn (object $row): array => (array) $row)->all();
            $digest = hash_init('sha256');
            foreach ($rows as $row) {
                $insert->execute(array_map(fn (string $column): mixed => $row[$column], $columns));
                foreach (['migration_hold', 'is_active'] as $boolean) {
                    if (array_key_exists($boolean, $row)) {
                        $row[$boolean] = (bool) $row[$boolean];
                    }
                }
                hash_update($digest, ReadinessFiles::json($row)."\n");
            }
            $tables[$table] = ['columns' => $columns, 'rows' => count($rows), 'rows_sha256' => hash_final($digest)];
        }
        $manifest = ['format' => 'legacy_readiness_projection_v1', 'target_id' => 'fixture-target', 'source_database' => 'synthetic_fixture',
            'captured_at' => '2026-10-12T00:00:00+00:00', 'consistency' => 'repeatable_read', 'read_only' => true, 'network' => 'none', 'tables' => $tables];
        $pdo->exec('CREATE TABLE legacy_readiness_projection (manifest TEXT NOT NULL)');
        $pdo->prepare('INSERT INTO legacy_readiness_projection VALUES (?)')->execute([ReadinessFiles::json($manifest)]);
        $pdo = null;

        return $path;
    }

    public function observation(string $baseline, array $scopes): string
    {
        $path = $this->root.'/observations-'.(++$this->sequence).'.json';
        $this->write($path, ReadinessFiles::json(['format' => 'legacy_readiness_observations_v1',
            'source_database' => 'main_school_db', 'captured_at' => '2026-10-11T12:00:00+00:00', 'consistency' => 'sequential_non_atomic',
            'baseline_manifest_sha256' => hash_file('sha256', $baseline.'/manifest.json'),
            'provenance' => array_fill_keys(['capture_sha256', 'order_capture_sha256', 'normalizer_sha256'], str_repeat('d', 64)),
            'scopes' => $scopes]));

        return $path;
    }

    public function write(string $path, string $bytes): void
    {
        file_put_contents($path, $bytes);
        chmod($path, 0600);
    }

    public function cleanup(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->root);
    }
}
