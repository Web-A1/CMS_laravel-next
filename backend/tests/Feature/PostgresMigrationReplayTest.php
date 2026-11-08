<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('pgsql')]
class PostgresMigrationReplayTest extends TestCase
{
    public function test_fresh_migrations_recreate_schema_when_trigger_functions_remain(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('cms_test', DB::connection()->getDatabaseName());
        $this->assertSame('127.0.0.1', config('database.connections.pgsql.host'));

        // PostgreSQL rolls back the schema and fixtures after this destructive test.
        DB::beginTransaction();
        try {
            Schema::dropAllTables();
            $this->assertFalse(Schema::hasTable('migrations'));
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());
            $this->assertTrue(Schema::hasTable('video_watermark_sessions'));
            $this->assertTrue(Schema::hasTable('lesson_assessment_review_events'));

            foreach ([
                'video_watermark_lookup_audit_events_immutable_trigger',
                'video_watermark_lookup_audit_events_truncate_immutable_trigger',
                'lesson_assessment_review_events_append_only_trigger',
            ] as $trigger) {
                $this->assertNotNull(DB::selectOne(
                    'SELECT oid FROM pg_trigger WHERE tgname = ? AND tgenabled = ?',
                    [$trigger, 'O'],
                ));
            }
        } finally {
            DB::rollBack();
        }
    }
}
