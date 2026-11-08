<?php

namespace Tests\Feature;

use App\Models\ContentAuthor;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('pgsql')]
class StaffAuthorPostgresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        $this->assertSame('cms_author_20261030_test', DB::connection()->getDatabaseName());
        $this->assertContains(DB::connection()->getConfig('url'), [null, '']);
        $this->assertSame('cms_author_20261030_test', DB::selectOne('SELECT current_database() AS db')->db);
    }

    public function test_schema_rollback_reapply_unique_fk_and_snapshot(): void
    {
        $migration = require database_path('migrations/2026_10_30_120000_add_staff_names_and_author_user_link.php');
        $user = User::factory()->create(['name' => 'Unparsed existing display']);
        $author = ContentAuthor::create(['slug' => 'pg-schema-'.bin2hex(random_bytes(4)), 'name' => 'Preserved author']);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'first_name'));
        $this->assertFalse(Schema::hasColumn('content_authors', 'user_id'));
        $this->assertSame('Preserved author', $author->fresh()->name);
        $migration->up();
        $this->assertNull($user->fresh()->first_name);
        $this->assertSame('Unparsed existing display', $user->fresh()->name);
        $this->assertSame(120, DB::selectOne("SELECT character_maximum_length AS size FROM information_schema.columns WHERE table_schema = current_schema() AND table_name='users' AND column_name='first_name'")->size);
        DB::table('content_authors')->where('id', $author->id)->update(['user_id' => $user->id]);
        try {
            DB::table('content_authors')->insert(['slug' => 'pg-duplicate', 'name' => 'Duplicate', 'user_id' => $user->id]);
            $this->fail('Unique FK must reject duplicate');
        } catch (QueryException $exception) {
            $this->assertSame('23505', (string) $exception->getCode());
        }
        $user->delete();
        $this->assertNull($author->fresh()->user_id);
        $this->assertSame('Preserved author', $author->fresh()->name);
    }
}
