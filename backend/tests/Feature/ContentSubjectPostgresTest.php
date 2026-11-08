<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillArticleSubjects;
use App\Models\ContentCategory;
use App\Models\ContentEntry;
use App\Models\LegacyImportMapping;
use App\Models\Subject;
use App\Models\User;
use App\Services\Articles\ArticleEditor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\SeedsArticleSubjects;
use Tests\TestCase;

#[Group('pgsql')]
class ContentSubjectPostgresTest extends TestCase
{
    use SeedsArticleSubjects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        $this->assertSame('cms_taxonomy_20261030_test', DB::connection()->getDatabaseName());
        $this->assertContains(DB::connection()->getConfig('url'), [null, '']);
        $this->assertSame('cms_taxonomy_20261030_test', DB::selectOne('SELECT current_database() AS db')->db);
    }

    public function test_additive_replay_index_and_restrict_for_articles_and_faqs(): void
    {
        $migration = require database_path('migrations/2026_10_30_160000_add_subject_to_content_entries.php');
        $subject = Subject::create(['slug' => 'pg-taxonomy-'.bin2hex(random_bytes(6)), 'title' => 'Preserved subject']);
        $article = ContentEntry::create(['content_type' => 'article', 'slug' => 'pg-taxonomy-article', 'title' => 'Preserved', 'body' => 'Body']);
        $faq = ContentEntry::create(['content_type' => 'faq', 'slug' => 'pg-taxonomy-faq', 'title' => 'Question', 'body' => 'Answer']);
        $this->assertNull($article->fresh()->subject_id);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('content_entries', 'subject_id'));
        $migration->up();
        $this->assertSame('Body', $article->fresh()->body);
        $this->assertNull($faq->fresh()->subject_id);
        $this->assertNotNull(DB::selectOne("SELECT indexname FROM pg_indexes WHERE tablename='content_entries' AND indexname='content_entries_subject_id_index'"));
        $rules = DB::select("SELECT conrelid::regclass::text AS source, confdeltype FROM pg_constraint WHERE contype='f' AND confrelid='subjects'::regclass AND conrelid IN ('content_entries'::regclass,'courses'::regclass,'reviews'::regclass)");
        $this->assertSame(['content_entries' => 'r', 'courses' => 'c', 'reviews' => 'n'], collect($rules)->pluck('confdeltype', 'source')->sortKeys()->all());
        foreach ([$article, $faq] as $entry) {
            $entry->update(['subject_id' => $subject->id]);
            try {
                $subject->delete();
                $this->fail('Assigned content must restrict subject deletion');
            } catch (QueryException $exception) {
                $this->assertSame('23503', (string) $exception->getCode());
            }
            $entry->update(['subject_id' => null]);
        }
        $subject->delete();
        $article->delete();
        $faq->delete();
    }

    public function test_backfill_verifies_backup_before_first_write_and_rolls_back_on_later_audit_failure(): void
    {
        // setUp verifies the actual task database before this fixture can write.
        // Refuse an occupied database rather than clearing any existing data.
        foreach (['content_entries', 'content_categories', 'subjects', 'legacy_import_mappings', 'article_audit_events'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), 'The guarded taxonomy test database must have an empty '.$table);
        }
        $path = base_path('../demo-imports/backups/pg-taxonomy-test-'.bin2hex(random_bytes(8)).'.json');
        $dispatcher = ContentEntry::getEventDispatcher();
        $probe = clone $dispatcher;
        $backupVerifiedBeforeWrite = false;
        $earlierWriteObserved = false;
        $writes = 0;
        DB::beginTransaction();
        try {
            $this->seedArticles();
            $before = ContentEntry::query()->orderBy('id')->get()->map(fn ($entry) => $entry->getAttributes())->all();
            $mappings = LegacyImportMapping::query()->orderBy('id')->get()->map(fn ($mapping) => $mapping->getAttributes())->all();
            $this->assertCount(225, $before);
            $this->assertSame(16, ContentCategory::query()->count());
            $extra = ContentEntry::create(['content_type' => 'article', 'slug' => 'pg-unmapped-hidden', 'title' => 'Preserve QA', 'body' => 'Preserve body', 'status' => 'hidden']);
            $extraBefore = $extra->fresh()->getAttributes();
            $candidateIds = ContentEntry::query()->whereIn('content_category_id', array_keys(BackfillArticleSubjects::MAP))->orderBy('id')->pluck('id');
            $second = (int) $candidateIds[1];
            DB::unprepared("CREATE FUNCTION pg_temp.reject_taxonomy_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.action = 'backfill_subject' AND NEW.entity_id = {$second} THEN RAISE EXCEPTION 'taxonomy audit unavailable'; END IF; RETURN NEW; END; \$\$");
            DB::unprepared('CREATE TRIGGER reject_taxonomy_audit BEFORE INSERT ON article_audit_events FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_taxonomy_audit()');
            $probe->listen('eloquent.updating: '.ContentEntry::class, function (ContentEntry $entry) use ($path, $before, $mappings, &$backupVerifiedBeforeWrite, &$earlierWriteObserved, &$writes): void {
                if (! $entry->isDirty('subject_id')) {
                    return;
                }
                $writes++;
                if ($writes === 1) {
                    // This event runs before Eloquent issues the first UPDATE.
                    $this->assertFileExists($path);
                    $bytes = file_get_contents($path);
                    $backup = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
                    $this->assertSame($bytes, file_get_contents($path));
                    $this->assertSame($before, array_values($backup['articles']));
                    $this->assertSame($mappings, $backup['mappings']);
                    $this->assertSame(0, ContentEntry::query()->whereNotNull('subject_id')->count());
                    $this->assertSame(0, DB::table('article_audit_events')->count());
                    $backupVerifiedBeforeWrite = true;
                } elseif ($writes === 2) {
                    $this->assertSame(1, ContentEntry::query()->whereNotNull('subject_id')->where('revision', 1)->count());
                    $this->assertSame(1, DB::table('article_audit_events')->where('action', 'backfill_subject')->count());
                    $earlierWriteObserved = true;
                }
            });
            ContentEntry::setEventDispatcher($probe);
            $this->artisan('articles:backfill-subjects', ['--apply' => true, '--backup' => $path])
                ->expectsOutputToContain('taxonomy audit unavailable')->assertFailed();
            $this->assertTrue($backupVerifiedBeforeWrite, 'The valid baseline backup must be observed before the first UPDATE');
            $this->assertTrue($earlierWriteObserved, 'A subject/revision update and audit must occur before the forced later failure');
            $this->assertSame(2, $writes);
            $this->assertSame($before, ContentEntry::query()->whereKey(array_column($before, 'id'))->orderBy('id')->get()->map(fn ($entry) => $entry->getAttributes())->all());
            $this->assertSame($extraBefore, $extra->fresh()->getAttributes());
            $this->assertSame($mappings, LegacyImportMapping::query()->orderBy('id')->get()->map(fn ($mapping) => $mapping->getAttributes())->all());
            $this->assertSame(0, ContentEntry::query()->whereNotNull('subject_id')->count());
            $this->assertSame(0, DB::table('article_audit_events')->count());
            $this->assertFileExists($path);
        } finally {
            ContentEntry::setEventDispatcher($dispatcher);
            // Includes fixture rows and the temporary trigger/function; no reset.
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_concurrent_edit_waits_for_row_lock_and_rechecks_revision(): void
    {
        foreach (['pcntl_fork', 'pcntl_waitpid', 'stream_socket_pair'] as $function) {
            $this->assertTrue(function_exists($function));
        }
        $actor = User::factory()->create(['role' => 'admin_full']);
        $subject = Subject::create(['slug' => 'pg-lock-'.bin2hex(random_bytes(6)), 'title' => 'Lock subject']);
        $entry = ContentEntry::create(['content_type' => 'article', 'slug' => 'pg-lock-'.bin2hex(random_bytes(6)), 'title' => 'Before', 'body' => '<p>Body</p>']);
        $application = 'taxonomy-lock-'.bin2hex(random_bytes(6));
        DB::disconnect();
        [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($parent);
            try {
                DB::reconnect();
                DB::selectOne("SELECT set_config('application_name', ?, false)", [$application]);
                DB::statement("SET statement_timeout = '8s'");
                DB::statement("SET lock_timeout = '5s'");
                fwrite($child, 'R');
                stream_set_timeout($child, 8);
                if (fread($child, 1) !== 'G') {
                    throw new \RuntimeException('Missing barrier');
                }
                auth('web')->login(User::findOrFail($actor->id));
                try {
                    app(ArticleEditor::class)->save($entry->id, ['title' => 'Stale', 'slug' => $entry->slug, 'body' => '<p>Body</p>', 'status' => 'draft', 'subject_id' => null], 0);
                    fwrite($child, 'unexpected-save');
                } catch (ValidationException $exception) {
                    fwrite($child, array_key_exists('data.title', $exception->errors()) ? 'revision-conflict' : 'wrong-validation');
                }
                fclose($child);
                exit(0);
            } catch (\Throwable $exception) {
                fwrite($child, 'error:'.$exception->getMessage());
                fclose($child);
                exit(1);
            }
        }
        fclose($child);
        stream_set_timeout($parent, 10);
        DB::reconnect();
        $locked = false;
        try {
            $this->assertSame('R', fread($parent, 1));
            DB::beginTransaction();
            ContentEntry::query()->lockForUpdate()->findOrFail($entry->id);
            fwrite($parent, 'G');
            $deadline = microtime(true) + 3;
            do {
                $locked = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name=? AND wait_event_type='Lock') AS waiting", [$application])->waiting;
                if (! $locked) {
                    usleep(20000);
                }
            } while (! $locked && microtime(true) < $deadline);
            DB::table('content_entries')->where('id', $entry->id)->update(['subject_id' => $subject->id, 'revision' => 1]);
            DB::commit();
            $result = stream_get_contents($parent);
            pcntl_waitpid($pid, $status);
            $this->assertTrue((bool) $locked, 'Writer must wait on the locked content row');
            $this->assertSame('revision-conflict', $result);
            $this->assertSame(0, pcntl_wexitstatus($status));
            $this->assertSame($subject->id, $entry->fresh()->subject_id);
            $this->assertSame('Before', $entry->fresh()->title);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            fclose($parent);
            $entry->delete();
            $subject->delete();
            $actor->delete();
        }
    }
}
