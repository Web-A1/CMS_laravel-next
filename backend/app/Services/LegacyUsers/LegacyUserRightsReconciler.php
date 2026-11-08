<?php

namespace App\Services\LegacyUsers;

use App\Models\User;
use App\Models\UserEntitlementGrant;
use App\Services\EntitlementGrantService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Local internal operation: callers must approve the complete bundle and its predecessor. */
final class LegacyUserRightsReconciler
{
    private const OWNER = 'legacy-user-rights-v1';

    public function __construct(private readonly EntitlementGrantService $grants) {}

    public function reconcile(LegacyUserBundle $bundle, int $expectedGeneration, string $approvedManifestSha): array
    {
        if ($expectedGeneration < 0 || ! hash_equals($bundle->manifestHash, $approvedManifestSha)) {
            throw new InvalidArgumentException('Rights authority requires the approved manifest and predecessor.');
        }
        $records = [];
        foreach ($bundle->tableOrder() as $table) {
            foreach ($bundle->rows($table) as $record) {
                if (in_array($table, ['member', 'order', 'platform_access', 'platform_page'], true)) {
                    $records[$table][$record['source_id']] = $record;
                }
            }
        }
        $database = $bundle->manifest['source_database'];

        return DB::transaction(function () use ($bundle, $records, $database, $expectedGeneration): array {
            LegacyUserMigrationLock::shared();
            DB::table('legacy_user_rights_authorities')->insertOrIgnore([
                'source_database' => $database, 'generation' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $authority = DB::table('legacy_user_rights_authorities')->where('source_database', $database)->lockForUpdate()->first();
            if ((int) $authority->generation !== $expectedGeneration) {
                throw new InvalidArgumentException('Stale rights predecessor.');
            }
            $repeat = $authority->manifest_sha256 === $bundle->manifestHash;
            if (! $repeat && DB::table('legacy_user_rights_generations')->where('source_database', $database)
                ->where(fn ($query) => $query->where('manifest_sha256', $bundle->manifestHash)->orWhere('snapshot_sha256', $bundle->manifest['snapshot_sha256']))->exists()) {
                throw new InvalidArgumentException('Previously superseded rights snapshot cannot become authoritative again.');
            }
            $generation = $expectedGeneration + ($repeat ? 0 : 1);
            $profiles = DB::table('legacy_user_profiles')->whereIn('last_import_run_id',
                DB::table('legacy_user_import_runs')->select('id')->where('source_database', $database))
                ->orderBy('id')->lockForUpdate()->get();
            $users = User::query()->whereIn('id', $profiles->pluck('user_id')->filter()->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $owned = UserEntitlementGrant::query()->whereIn('user_id', $users->keys())->where('source_type', UserEntitlementGrant::SOURCE_MIGRATION)
                ->orderBy('id')->lockForUpdate()->get()->filter(fn ($grant) => ($grant->metadata['owner'] ?? null) === self::OWNER
                    && ($grant->metadata['source_database'] ?? null) === $database);
            $result = ['generation' => $generation, 'repeated' => $repeat, 'created' => 0, 'revoked' => 0, 'profiles' => []];
            foreach ($profiles as $profile) {
                $user = $users->get($profile->user_id);
                $existing = $owned->where('user_id', $profile->user_id);
                $desired = [];
                $blocked = [];
                if ($this->eligible($profile, $user, $records['member'][$profile->source_member_id] ?? null)) {
                    foreach ($records['platform_access'] ?? [] as $access) {
                        if ($access['source_member_id'] !== (int) $profile->source_member_id) {
                            continue;
                        }
                        $lesson = $this->confirmedLesson($access, $records, (int) $profile->source_member_id);
                        if ($lesson === null) {
                            $blocked[] = ['source_access_id' => $access['source_id'], 'reason' => 'unconfirmed_source_or_mapping'];

                            continue;
                        }
                        $prior = $existing->filter(fn ($grant) => ($grant->metadata['source_access_id'] ?? null) === $access['source_id']);
                        if ($prior->contains(fn ($grant) => $grant->atom_kind !== UserEntitlementGrant::ATOM_LESSON || (int) $grant->atom_id !== $lesson
                            || $grant->status !== UserEntitlementGrant::STATUS_ACTIVE || ($grant->expires_at && $grant->expires_at->isPast()))) {
                            $blocked[] = ['source_access_id' => $access['source_id'], 'reason' => 'tombstone_or_changed_mapping'];

                            continue;
                        }
                        $sourceId = $database.':platform_access:'.$access['source_id'];
                        $idempotency = hash('sha256', self::OWNER.':'.$sourceId.':lesson:'.$lesson);
                        $grantResult = $this->grants->grantAtoms($user->id, [['kind' => UserEntitlementGrant::ATOM_LESSON, 'id' => $lesson]],
                            UserEntitlementGrant::SOURCE_MIGRATION, $sourceId, $idempotency,
                            metadata: ['owner' => self::OWNER, 'source_database' => $database, 'source_access_id' => $access['source_id']]);
                        $result['created'] += $grantResult['created_count'];
                        foreach ($grantResult['grants'] as $grant) {
                            $desired[] = $grant->id;
                        }
                    }
                } else {
                    $blocked[] = ['reason' => 'activation_or_source_identity_unconfirmed'];
                }
                foreach ($existing as $grant) {
                    if (! in_array($grant->id, $desired, true) && in_array($grant->status, [UserEntitlementGrant::STATUS_ACTIVE, UserEntitlementGrant::STATUS_PENDING], true)) {
                        $grant->forceFill(['status' => UserEntitlementGrant::STATUS_REVOKED, 'revoked_at' => now(), 'revoked_reason' => 'legacy_authoritative_reconciliation'])->save();
                        $result['revoked']++;
                    }
                }
                $outcome = ['confirmed_grant_ids' => $desired, 'blocked' => $blocked];
                DB::table('legacy_user_rights_states')->updateOrInsert(['legacy_user_profile_id' => $profile->id], [
                    'source_database' => $database, 'generation' => $generation, 'manifest_sha256' => $bundle->manifestHash,
                    'outcome' => json_encode($outcome, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $result['profiles'][(string) $profile->id] = $outcome;
            }
            if (! $repeat) {
                DB::table('legacy_user_rights_generations')->insert(['source_database' => $database, 'generation' => $generation,
                    'predecessor_generation' => $expectedGeneration, 'manifest_sha256' => $bundle->manifestHash,
                    'snapshot_sha256' => $bundle->manifest['snapshot_sha256'], 'created_at' => now()]);
                DB::table('legacy_user_rights_authorities')->where('source_database', $database)->update([
                    'generation' => $generation, 'manifest_sha256' => $bundle->manifestHash, 'updated_at' => now(),
                ]);
            }

            return $result;
        });
    }

    private function eligible(object $profile, ?User $user, ?array $member): bool
    {
        if (! $user || $profile->mapping_status !== 'linked' || $user->blocksAuthenticationForMigration() || $user->password_state !== 'set'
            || ! in_array($user->role, ['user_no_purchase', 'user_with_purchase'], true) || ! $user->email_verified_at || ! $user->phone_verified_at
            || ! $member || $member['source_member_id'] !== (int) $profile->source_member_id) {
            return false;
        }
        $completed = DB::table('legacy_user_activations')->where('legacy_user_profile_id', $profile->id)->where('user_id', $user->id)
            ->whereNotNull('completed_at')->orderByDesc('id')->first();
        try {
            $payload = json_decode($profile->payload, true, 64, JSON_THROW_ON_ERROR);
            $issues = json_decode($profile->issues, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
        $email = strtolower(trim((string) ($member['row']['email'] ?? '')));

        return $completed && is_array($payload) && $this->onlyVerifiedPhoneReview($issues)
            && $this->sourceReference($payload['id'] ?? null) === (int) $profile->source_member_id
            && in_array($payload['isBanned'] ?? null, [0, '0', false], true)
            && in_array($member['row']['isBanned'] ?? null, [0, '0', false], true)
            && $email !== '' && $email === $completed->source_email && $email === strtolower(trim((string) $profile->source_email))
            && $email === strtolower(trim((string) ($payload['email'] ?? '')))
            && DB::table('legacy_user_profiles')->whereRaw('LOWER(TRIM(source_email)) = ?', [$email])->count() === 1;
    }

    private function onlyVerifiedPhoneReview(mixed $issues): bool
    {
        if (! is_array($issues)) {
            return false;
        }
        foreach ($issues as $issue) {
            if (! is_array($issue) || ($issue['reason'] ?? null) !== 'contact_change_requires_review' || ($issue['field'] ?? null) !== 'phone') {
                return false;
            }
        }

        return true;
    }

    private function confirmedLesson(array $access, array $records, int $member): ?int
    {
        $row = $access['row'];
        if ($access['ownership_status'] !== 'linked' || $this->sourceReference($row['memberId'] ?? null) !== $member) {
            return null;
        }
        if (($row['grantedBy'] ?? null) === 'order') {
            $orderId = $this->sourceReference($row['grantedById'] ?? null);
            $order = $orderId === null ? null : ($records['order'][$orderId] ?? null);
            if (! $order || $order['ownership_status'] !== 'linked' || $order['source_member_id'] !== $member
                || $this->sourceReference($order['row']['memberId'] ?? null) !== $member || ! in_array($order['row']['status'] ?? null, [2, 3, '2', '3'], true)) {
                return null;
            }
        } elseif (($row['grantedBy'] ?? null) !== 'manual') {
            return null;
        }
        $page = $this->sourceReference($row['pageId'] ?? null);
        if ($page === null || $page === 1195 || ! isset($records['platform_page'][$page])) {
            return null;
        }
        $mappings = DB::table('legacy_import_mappings')->where('source_table', 'platform_page')->where('source_id', (string) $page)
            ->where('mapping_status', 'mapped')->where('validation_status', 'passed')->where('target_domain', 'learning_lesson_body')
            ->where('target_type', 'lesson_learning_content')->get();
        if ($mappings->count() !== 1) {
            return null;
        }
        $lesson = DB::table('lesson_learning_contents as content')->join('lessons', 'lessons.id', '=', 'content.lesson_id')
            ->join('courses', 'courses.id', '=', 'lessons.course_id')->join('subjects', 'subjects.id', '=', 'courses.subject_id')
            ->where('content.id', $mappings->first()->target_id)->where('content.status', 'published')
            ->where('lessons.is_active', true)->where('courses.is_active', true)->where('subjects.is_active', true)->value('lessons.id');

        return $lesson ? (int) $lesson : null;
    }

    private function sourceReference(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }
        $id = (int) $value;

        return $id > 0 && (string) $id === $value ? $id : null;
    }
}
