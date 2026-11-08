<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Services\EntitlementGrantService;
use App\Services\LegacyUsers\LegacyUserBundle;
use App\Services\LegacyUsers\LegacyUserRightsReconciler;
use App\Services\LegacyUsers\LegacyUserSourcePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\Support\LegacyUserBundleFixtures;
use Tests\TestCase;

class LegacyUserRightsTest extends TestCase
{
    use RefreshDatabase;

    private LegacyUserBundleFixtures $fixtures;

    private User $student;

    private int $lesson;

    private const BASE = '/api/v1/auth/legacy-activation/';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        config(['services.redsms.mode' => 'mock']);
        $this->fixtures = new LegacyUserBundleFixtures;
        $this->fixtures->import($this->fixtures->bundle(LegacyUserBundleFixtures::records()));
        $this->student = User::query()->where('email', 'synthetic13@example.test')->sole();
        $this->lesson = $this->fixtures->mapping();
    }

    protected function tearDown(): void
    {
        $this->fixtures->cleanup();
        parent::tearDown();
    }

    private function activate(): void
    {
        $started = $this->postJson(self::BASE.'email/start', ['email' => $this->student->email])->assertAccepted()->json();
        $credential = array_intersect_key($started, array_flip(['activation_id', 'capability']));
        $this->postJson(self::BASE.'email/verify', $credential + ['code' => $started['debug_code']])->assertOk();
        $this->postJson(self::BASE.'password', $credential + ['password' => 'new-password-123'])->assertOk();
        $this->postJson(self::BASE.'phone/start', $credential + ['phone' => '+79990000123'])->assertAccepted();
        $this->postJson(self::BASE.'complete', $credential)->assertOk();
        $this->student->refresh();
    }

    private function bundle(array $records, string $name): LegacyUserBundle
    {
        return new LegacyUserBundle($this->fixtures->bundle($records, $name, hash('sha256', json_encode($records))), app(LegacyUserSourcePolicy::class));
    }

    private function reconcile(LegacyUserBundle $bundle, int $predecessor): array
    {
        return app(LegacyUserRightsReconciler::class)->reconcile($bundle, $predecessor, $bundle->manifestHash);
    }

    private function history(): array
    {
        $result = [];
        foreach (['legacy_user_profiles', 'legacy_user_orders', 'legacy_user_order_items', 'legacy_user_accesses', 'legacy_user_records', 'legacy_user_import_runs'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $result;
    }

    public function test_atomic_exact_atom_idempotence_history_and_independent_new_purchase_preserved(): void
    {
        $this->activate();
        $history = $this->history();
        $new = app(EntitlementGrantService::class)->grantAtoms($this->student->id, [['kind' => 'lesson', 'id' => $this->lesson]],
            UserEntitlementGrant::SOURCE_ORDER_ITEM, 'independent-new-purchase', 'independent-new-purchase')['grants']->sole();
        $bundle = $this->bundle(LegacyUserBundleFixtures::records(), 'A');
        $result = $this->reconcile($bundle, 0);
        $this->assertSame(1, $result['created']);
        $grant = UserEntitlementGrant::query()->where('source_type', 'migration')->sole();
        $this->assertSame('lesson', $grant->atom_kind);
        $this->assertSame($this->lesson, (int) $grant->atom_id);
        $this->assertNull($grant->order_id);
        $this->assertNull($grant->order_item_id);
        $this->assertSame(0, $this->reconcile($bundle, 1)['created']);
        $this->assertDatabaseCount('legacy_user_rights_generations', 1);
        $this->assertSame($history, $this->history());
        $records = LegacyUserBundleFixtures::records();
        $records['platform_access'] = [];
        $this->assertSame(1, $this->reconcile($this->bundle($records, 'B'), 1)['revoked']);
        $this->assertSame('revoked', $grant->refresh()->status);
        $this->assertSame('active', $new->refresh()->status);
        $this->assertSame($history, $this->history());
        $this->assertDatabaseCount('orders', 0);
        Http::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_complete_empty_bundle_revokes_and_stale_a_b_a_or_renamed_snapshot_cannot_restore(): void
    {
        $this->activate();
        $a = $this->bundle(LegacyUserBundleFixtures::records(), 'A');
        $this->reconcile($a, 0);
        $this->assertSame(1, $this->reconcile($this->bundle([], 'B'), 1)['revoked']);
        foreach ([$a, $this->bundle(LegacyUserBundleFixtures::records(), 'renamed-A')] as $stale) {
            try {
                $this->reconcile($stale, 2);
                $this->fail('Stale snapshot accepted');
            } catch (InvalidArgumentException $error) {
                $this->assertStringContainsString('superseded', $error->getMessage());
            }
        }
        $this->assertSame(2, DB::table('legacy_user_rights_authorities')->value('generation'));
        $this->assertDatabaseCount('legacy_user_rights_generations', 2);
        $this->assertSame('revoked', UserEntitlementGrant::query()->sole()->status);
    }

    public function test_hold_wrong_approval_and_stale_predecessor_never_grant(): void
    {
        $bundle = $this->bundle(LegacyUserBundleFixtures::records(), 'A');
        $this->assertSame(0, $this->reconcile($bundle, 0)['created']);
        $this->activate();
        foreach (['wrong_approval', 'wrong_predecessor'] as $case) {
            try {
                app(LegacyUserRightsReconciler::class)->reconcile($bundle, $case === 'wrong_predecessor' ? 0 : 1,
                    $case === 'wrong_approval' ? str_repeat('f', 64) : $bundle->manifestHash);
                $this->fail('Invalid authority accepted');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('user_entitlement_grants', 0);
            }
        }
        $this->assertSame(1, $this->reconcile($bundle, 1)['created']);
    }

    public function test_source_references_require_exact_positive_integer_ids_without_coercion(): void
    {
        $this->activate();
        $history = $this->history();
        $generation = 0;
        foreach (['grantedById' => 5, 'pageId' => 90] as $field => $id) {
            foreach ([$id.'junk', ' '.$id, $id.' ', '+'.$id, '0'.$id, $id.'.0', $id.'e0', $id + 0.5, true, 0, -$id, null, [], PHP_INT_MAX.'0'] as $index => $invalid) {
                $records = LegacyUserBundleFixtures::records();
                $records['platform_access'][0]['row'][$field] = $invalid;
                $this->assertSame(0, $this->reconcile($this->bundle($records, $field.'-'.$index), $generation++)['created'], $field.'-'.$index);
                $this->assertDatabaseCount('user_entitlement_grants', 0);
            }
        }
        $records = LegacyUserBundleFixtures::records();
        $records['platform_access'][0]['row']['grantedById'] = '5';
        $records['platform_access'][0]['row']['pageId'] = '90';
        $this->assertSame(1, $this->reconcile($this->bundle($records, 'canonical-decimal-references'), $generation)['created']);
        $this->assertSame($history, $this->history());
    }

    public function test_refund_unknown_marker_inactive_or_ambiguous_mapping_and_ban_are_denied(): void
    {
        $this->activate();
        $records = LegacyUserBundleFixtures::records();
        $records['order'][0]['row']['status'] = 6;
        $this->assertSame(0, $this->reconcile($this->bundle($records, 'refund'), 0)['created']);
        $records = LegacyUserBundleFixtures::records();
        $records['platform_access'][0]['row']['grantedBy'] = 'platform_access_order';
        $this->assertSame(0, $this->reconcile($this->bundle($records, 'marker'), 1)['created']);
        $this->fixtures->mapping();
        $this->assertSame(0, $this->reconcile($this->bundle(LegacyUserBundleFixtures::records(), 'ambiguous'), 2)['created']);
        $records = LegacyUserBundleFixtures::records();
        $records['member'][0]['row']['isBanned'] = 1;
        $this->assertSame(0, $this->reconcile($this->bundle($records, 'banned'), 3)['created']);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }

    public function test_tombstone_and_changed_mapping_cannot_create_fresh_grant_for_same_source_access(): void
    {
        $this->activate();
        $this->reconcile($this->bundle(LegacyUserBundleFixtures::records(), 'A'), 0);
        $grant = UserEntitlementGrant::query()->sole();
        $records = LegacyUserBundleFixtures::records();
        $records['member'][0]['row']['firstname'] = 'Updated';
        $grant->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        $this->assertSame(0, $this->reconcile($this->bundle($records, 'B'), 1)['created']);
        $this->assertSame('revoked', $grant->refresh()->status);
        DB::table('legacy_import_mappings')->delete();
        $different = $this->fixtures->mapping();
        $records['member'][0]['row']['firstname'] = 'Updated again';
        $this->assertSame(0, $this->reconcile($this->bundle($records, 'C'), 2)['created']);
        $this->assertNotSame($this->lesson, $different);
        $this->assertDatabaseCount('user_entitlement_grants', 1);
    }

    public function test_fully_exhausts_all_tables_before_authority_and_preserves_existing_grants_on_corruption(): void
    {
        $this->activate();
        $a = $this->bundle(LegacyUserBundleFixtures::records(), 'A');
        $this->reconcile($a, 0);
        $records = LegacyUserBundleFixtures::records();
        $records['platform_access'] = [];
        $directory = $this->fixtures->bundle($records, 'B', str_repeat('b', 64));
        $b = new LegacyUserBundle($directory, app(LegacyUserSourcePolicy::class));
        file_put_contents($directory.'/pollAnswer.jsonl', "corrupt-late-table\n");
        try {
            $this->reconcile($b, 1);
            $this->fail('Corrupt complete bundle accepted');
        } catch (\JsonException|InvalidArgumentException) {
            $this->assertSame(1, DB::table('legacy_user_rights_authorities')->value('generation'));
            $this->assertSame('active', UserEntitlementGrant::query()->sole()->status);
            $this->assertDatabaseCount('legacy_user_rights_generations', 1);
        }
    }

    public function test_reimport_preserves_completed_password_verified_contacts_and_confirmed_rights(): void
    {
        $this->activate();
        $this->reconcile($this->bundle(LegacyUserBundleFixtures::records(), 'A'), 0);
        $fields = ['password', 'password_state', 'migration_hold', 'phone', 'phone_verified_at', 'email_verified_at'];
        $before = $this->student->only($fields);
        $grants = UserEntitlementGrant::query()->get()->toJson();
        $records = LegacyUserBundleFixtures::records();
        $records['member'][0]['row']['firstname'] = 'Updated source name';
        $directory = $this->fixtures->bundle($records, 'B', hash('sha256', json_encode($records)));
        $this->fixtures->import($directory);
        $this->assertEquals($before, $this->student->refresh()->only($fields));
        $this->assertSame($grants, UserEntitlementGrant::query()->get()->toJson());
        $this->assertSame([['field' => 'phone', 'reason' => 'contact_change_requires_review']], json_decode(DB::table('legacy_user_profiles')->value('issues'), true));
        $b = new LegacyUserBundle($directory, app(LegacyUserSourcePolicy::class));
        $result = $this->reconcile($b, 1);
        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['revoked']);
        $this->assertSame('active', UserEntitlementGrant::query()->sole()->status);
        $issues = [['field' => 'email', 'reason' => 'contact_change_requires_review']];
        DB::table('legacy_user_profiles')->update(['issues' => json_encode($issues)]);
        $this->assertSame(1, $this->reconcile($b, 2)['revoked']);
    }

    public function test_inactive_material_and_blocked_page_do_not_expand_to_course_or_guide(): void
    {
        $this->activate();
        DB::table('lessons')->where('id', $this->lesson)->update(['is_active' => false]);
        $this->assertSame(0, $this->reconcile($this->bundle(LegacyUserBundleFixtures::records(), 'A'), 0)['created']);
        $records = LegacyUserBundleFixtures::records();
        $records['platform_access'][0]['row']['pageId'] = 1195;
        $records['platform_page'] = [LegacyUserBundleFixtures::envelope(1195, null, 'reference', ['h1' => 'Blocked'])];
        $this->fixtures->mapping(1195);
        $this->assertSame(0, $this->reconcile($this->bundle($records, 'B'), 1)['created']);
        $this->assertDatabaseCount('user_entitlement_grants', 0);
    }
}
