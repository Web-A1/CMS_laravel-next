<?php

namespace App\Services\LegacyUsers\Readiness;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Closed, checksummed file only. No configured PostgreSQL connection is consulted. */
final class ReadOnlyProjection
{
    public const TABLES = [
        'users' => 'id email phone migration_hold password_state',
        'user_profiles' => 'user_id',
        'legacy_user_profiles' => 'id source_member_id user_id source_fingerprint mapping_status',
        'legacy_user_orders' => 'id source_order_id source_fingerprint source_member_id source_status amount_minor legacy_user_profile_id ownership_status',
        'legacy_user_order_items' => 'id source_item_id source_fingerprint source_member_id source_order_id legacy_user_order_id legacy_user_profile_id source_product_id unit_amount_minor coupon_unit_amount_minor cost_minor quantity ownership_status',
        'legacy_user_accesses' => 'id source_access_id source_fingerprint source_member_id source_page_id source_subject_id legacy_user_profile_id candidate_lesson_id mapping_status ownership_status',
        'legacy_user_records' => 'id source_table source_id source_fingerprint',
        'legacy_import_mappings' => 'id source_table source_id source_scope_type source_scope_id target_domain target_type target_id mapping_status',
        'lesson_learning_contents' => 'id lesson_id',
        'lessons' => 'id course_id is_active',
        'courses' => 'id subject_id is_active',
        'subjects' => 'id is_active',
        'user_entitlement_grants' => 'id user_id status',
    ];

    public function read(string $path, string $sha256, string $expectedTarget, callable $reader): mixed
    {
        ReadinessFiles::file($path, $sha256);
        $this->closed($path);
        $oldDefault = DB::getDefaultConnection();
        $connections = config('database.connections');
        $name = 'legacy_readiness_projection';
        if (array_key_exists($name, $connections)) {
            throw new RuntimeException('Reserved connection name is already configured.');
        }
        $uri = 'file:'.str_replace('%2F', '/', rawurlencode($path)).'?mode=ro&immutable=1';
        config(['database.connections.'.$name => ['driver' => 'sqlite', 'database' => $uri, 'prefix' => '']]);
        DB::setDefaultConnection($name);
        try {
            $connection = DB::connection();
            $connection->statement('PRAGMA query_only=ON');
            $connection->statement('PRAGMA trusted_schema=OFF');
            if ((int) $connection->selectOne('PRAGMA query_only')->query_only !== 1) {
                throw new RuntimeException('Read-only connection was not established.');
            }
            $connection->beginTransaction();
            $metadata = $this->validate($expectedTarget);
            $result = $reader($metadata);
        } finally {
            try {
                if (isset($connection) && $connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            } finally {
                DB::purge($name);
                config(['database.connections' => $connections]);
                DB::setDefaultConnection($oldDefault);
            }
        }
        $this->closed($path);
        ReadinessFiles::file($path, $sha256);

        return $result;
    }

    private function closed(string $path): void
    {
        clearstatcache();
        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            if (file_exists($path.$suffix) || is_link($path.$suffix)) {
                throw new RuntimeException('Projection is not a closed standalone file.');
            }
        }
    }

    private function validate(string $expectedTarget): array
    {
        $objects = DB::select("SELECT name, type, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY name");
        $tables = [];
        foreach ($objects as $object) {
            if (! in_array($object->type, ['table', 'index'], true)
                || str_contains(strtoupper($object->sql ?? ''), 'VIRTUAL TABLE')) {
                throw new RuntimeException('Unsupported projection schema.');
            }
            if ($object->type === 'table') {
                $tables[] = $object->name;
            }
        }
        $expectedTables = [...array_keys(self::TABLES), 'legacy_readiness_projection'];
        sort($expectedTables);
        if ($tables !== $expectedTables || DB::table('legacy_readiness_projection')->count() !== 1) {
            throw new RuntimeException('Incomplete projection schema.');
        }
        $metadata = json_decode(DB::table('legacy_readiness_projection')->value('manifest'), true, 64, JSON_THROW_ON_ERROR);
        ReadinessFiles::keys($metadata, ['format', 'target_id', 'source_database', 'captured_at', 'consistency', 'read_only', 'network', 'tables']);
        if ($metadata['format'] !== 'legacy_readiness_projection_v1'
            || ! preg_match('/\A[a-zA-Z0-9_-]{1,96}\z/', $expectedTarget)
            || $metadata['target_id'] !== $expectedTarget || $metadata['read_only'] !== true
            || $metadata['network'] !== 'none' || $metadata['consistency'] !== 'repeatable_read'
            || ! preg_match('/\A[a-zA-Z0-9_-]{1,96}\z/', $metadata['source_database'])
            || ! preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)\z/', $metadata['captured_at'])) {
            throw new RuntimeException('Invalid projection provenance.');
        }
        ReadinessFiles::keys($metadata['tables'], array_keys(self::TABLES));
        foreach (self::TABLES as $table => $specification) {
            $columns = explode(' ', $specification);
            $entry = $metadata['tables'][$table];
            ReadinessFiles::keys($entry, ['columns', 'rows', 'rows_sha256']);
            $actualColumns = array_column(DB::select('PRAGMA table_info("'.$table.'")'), 'name');
            if ($actualColumns !== $columns || $entry['columns'] !== $columns
                || ! is_int($entry['rows']) || $entry['rows'] < 0 || ! ReadinessFiles::digest($entry['rows_sha256'])) {
                throw new RuntimeException('Invalid projection table contract.');
            }
            $keys = match ($table) {
                'legacy_user_profiles' => ['source_member_id'],
                'legacy_user_orders' => ['source_order_id'],
                'legacy_user_order_items' => ['source_item_id'],
                'legacy_user_accesses' => ['source_access_id'],
                'legacy_user_records' => ['source_table', 'source_id'],
                default => [$columns[0]],
            };
            foreach ([$keys, [$columns[0]]] as $unique) {
                if (DB::table($table)->select($unique)->groupBy($unique)->havingRaw('COUNT(*) > 1')->exists()) {
                    throw new RuntimeException('Duplicate projection identity.');
                }
            }
            $digest = hash_init('sha256');
            $count = 0;
            foreach (DB::table($table)->orderBy($columns[0])->cursor() as $object) {
                $row = (array) $object;
                if (! is_int($row[$columns[0]]) || $row[$columns[0]] <= 0) {
                    throw new RuntimeException('Invalid projection row identity.');
                }
                $textColumns = ['email', 'phone', 'password_state', 'source_fingerprint', 'mapping_status', 'ownership_status',
                    'source_table', 'source_scope_type', 'source_scope_id', 'target_domain', 'target_type', 'status'];
                foreach ($row as $column => $value) {
                    $text = in_array($column, $textColumns, true) || ($table === 'legacy_import_mappings' && $column === 'source_id');
                    if ($value !== null && ($text ? ! is_string($value) : ! is_int($value))) {
                        throw new RuntimeException('Invalid projected scalar type.');
                    }
                }
                foreach (['migration_hold', 'is_active'] as $boolean) {
                    if (array_key_exists($boolean, $row)) {
                        if (! in_array($row[$boolean], [0, 1], true)) {
                            throw new RuntimeException('Invalid projected boolean.');
                        }
                        $row[$boolean] = (bool) $row[$boolean];
                    }
                }
                if (isset($row['source_fingerprint']) && ! ReadinessFiles::digest($row['source_fingerprint'])) {
                    throw new RuntimeException('Invalid archived fingerprint.');
                }
                hash_update($digest, ReadinessFiles::json($row)."\n");
                $count++;
            }
            if ($count !== $entry['rows'] || ! hash_equals($entry['rows_sha256'], hash_final($digest))) {
                throw new RuntimeException('Projection rows differ from capture provenance.');
            }
        }

        return $metadata;
    }
}
