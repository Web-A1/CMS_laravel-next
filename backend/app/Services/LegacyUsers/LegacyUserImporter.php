<?php

namespace App\Services\LegacyUsers;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Additive historical import. Raw inserts deliberately bypass application events and providers. */
final class LegacyUserImporter
{
    private array $profileIds = [];

    private array $orderIds = [];

    private array $lessonCandidates = [];

    private LegacyUserIdentityPlan $identities;

    public function __construct(private readonly LegacyUserSourcePolicy $policy, private readonly LegacyUserRecordMapper $mapper) {}

    public function preview(LegacyUserBundle $bundle): array
    {
        $this->prepare($bundle);
        $report = $this->reportHeader($bundle);
        foreach ($bundle->tableOrder() as $table) {
            if ($this->policy->table($table)['kind'] === 'archive') {
                continue;
            }
            foreach ($this->chunks($bundle, $table) as $chunk) {
                [$destination, $key] = $this->mapper->destination($table);
                $existing = $this->query($destination, $table)->whereIn($key, array_column($chunk, 'source_id'))->get()->keyBy($key);
                foreach ($chunk as $record) {
                    $old = $existing->get($record['source_id']);
                    $row = $this->map($table, $record, $old);
                    $state = $old === null ? 'new' : ($old->source_fingerprint === $row['source_fingerprint'] ? 'unchanged' : 'changed');
                    $report['changes'][$table][$state] = ($report['changes'][$table][$state] ?? 0) + 1;
                    if ($table === 'member') {
                        $report['identities'][$row['mapping_status']] = ($report['identities'][$row['mapping_status']] ?? 0) + 1;
                    }
                    foreach (json_decode($row['issues'], true, 64, JSON_THROW_ON_ERROR) as $issue) {
                        $reason = $table.'.'.$issue['field'].'.'.$issue['reason'];
                        $report['issues'][$reason] = ($report['issues'][$reason] ?? 0) + 1;
                    }
                    if ($table === 'order') {
                        $status = $row['source_status'];
                        $report['orders'][$status]['count'] = ($report['orders'][$status]['count'] ?? 0) + 1;
                        $report['orders'][$status]['amount_minor'] = ($report['orders'][$status]['amount_minor'] ?? 0) + $row['amount_minor'];
                    }
                }
            }
        }

        if ($report['target_fingerprint'] !== $this->targetFingerprint()) {
            throw new RuntimeException('Target changed while preparing the report; repeat the preview.');
        }

        return $report;
    }

    /** The command must hold the target-wide advisory lock for this entire call. */
    public function apply(LegacyUserBundle $bundle, array $approvedReport, string $approvedHash, ?callable $progress = null): array
    {
        if (! hash_equals($approvedHash, hash('sha256', LegacyUserRecordMapper::json($approvedReport)))
            || ($approvedReport['manifest_sha256'] ?? null) !== $bundle->manifestHash
            || ($approvedReport['target'] ?? null) !== $this->target()
            || ($approvedReport['target_fingerprint'] ?? null) !== $this->targetFingerprint()) {
            throw new RuntimeException('Approved report, bundle or target changed; run a new preview.');
        }
        $this->prepare($bundle);
        $run = DB::table('legacy_user_import_runs')->where('manifest_sha256', $bundle->manifestHash)->where('approved_report_sha256', $approvedHash)->orderByDesc('started_at')->first();
        if (! $run && ! hash_equals($approvedHash, hash('sha256', LegacyUserRecordMapper::json($this->preview($bundle))))) {
            throw new RuntimeException('Current preview differs from the approved report.');
        }
        if ($run && $run->status === 'completed') {
            return ['run_id' => $run->id, 'status' => 'already_completed'];
        }
        $runId = $run?->id ?? (string) Str::uuid();
        if (! $run) {
            DB::table('legacy_user_import_runs')->insert([
                'id' => $runId, 'source_database' => $bundle->manifest['source_database'], 'snapshot_id' => $bundle->manifest['snapshot_id'],
                'mapping_version' => $bundle->manifest['mapping_version'], 'manifest_sha256' => $bundle->manifestHash,
                'approved_report_sha256' => $approvedHash, 'target_environment' => app()->environment(),
                'target_database' => DB::connection()->getDatabaseName(), 'status' => 'running', 'checkpoints' => '{}',
                'report' => LegacyUserRecordMapper::json($approvedReport), 'started_at' => now(),
            ]);
        } else {
            DB::table('legacy_user_import_runs')->where('id', $runId)->update(['status' => 'running', 'finished_at' => null]);
        }
        $checkpoints = $run ? json_decode($run->checkpoints, true, 64, JSON_THROW_ON_ERROR) : [];
        try {
            foreach ($bundle->tableOrder() as $table) {
                if ($this->policy->table($table)['kind'] === 'archive') {
                    continue;
                }
                foreach ($this->chunks($bundle, $table) as $chunk) {
                    $last = end($chunk)['source_id'];
                    if (isset($checkpoints[$table]) && $last <= $checkpoints[$table]) {
                        continue;
                    }
                    DB::transaction(function () use ($table, $chunk, $runId, $last, &$checkpoints): void {
                        $this->writeChunk($table, $chunk, $runId);
                        $checkpoints[$table] = $last;
                        DB::table('legacy_user_import_runs')->where('id', $runId)->update(['checkpoints' => LegacyUserRecordMapper::json($checkpoints)]);
                    });
                    if ($progress) {
                        $progress(['table' => $table, 'last_source_id' => $last]);
                    }
                }
            }
            DB::table('legacy_user_import_runs')->where('id', $runId)->update(['status' => 'completed', 'finished_at' => now()]);
        } catch (Throwable $e) {
            DB::table('legacy_user_import_runs')->where('id', $runId)->update(['status' => 'failed', 'finished_at' => now()]);
            // Never log DB exception messages: a failed query may contain personal data.
            throw new RuntimeException('Import stopped; committed chunks can be resumed after checking private diagnostics.', previous: $e);
        }

        return ['run_id' => $runId, 'status' => 'completed', 'checkpoints' => $checkpoints];
    }

    public function target(): array
    {
        return ['environment' => app()->environment(), 'driver' => DB::connection()->getDriverName(),
            'host' => DB::connection()->getConfig('host'), 'port' => (int) DB::connection()->getConfig('port'), 'database' => DB::connection()->getDatabaseName()];
    }

    public function targetFingerprint(): string
    {
        $digest = hash_init('sha256');
        $owned = DB::table('legacy_user_profiles')->whereNotNull('user_id')->select('user_id');
        foreach (['users', 'user_profiles'] as $table) {
            $key = $table === 'users' ? 'id' : 'user_id';
            foreach (DB::table($table)->whereNotIn($key, clone $owned)->orderBy($key)->cursor() as $row) {
                hash_update($digest, $table.LegacyUserRecordMapper::json((array) $row));
            }
        }
        // Only the non-user mappings are catalog inputs. This import owns a separate scope.
        foreach (DB::table('legacy_import_mappings')->where('source_scope_type', '<>', 'legacy_users')->orderBy('id')->cursor() as $row) {
            hash_update($digest, LegacyUserRecordMapper::json((array) $row));
        }

        return hash_final($digest);
    }

    private function prepare(LegacyUserBundle $bundle): void
    {
        $this->identities = new LegacyUserIdentityPlan($bundle, $this->policy);
        $this->profileIds = DB::table('legacy_user_profiles')->pluck('id', 'source_member_id')->all();
        foreach ($this->identities->sourceMemberIds as $id => $_) {
            // A preview needs membership without allocating a target sequence or writing rows.
            $this->profileIds[$id] ??= -$id;
        }
        $this->orderIds = DB::table('legacy_user_orders')->pluck('id', 'source_order_id')->all();
        foreach ($bundle->rows('order') as $record) {
            $this->orderIds[$record['source_id']] ??= -$record['source_id'];
        }
        $this->lessonCandidates = [];
        $mappings = DB::table('legacy_import_mappings as m')->join('lesson_learning_contents as c', 'c.id', '=', 'm.target_id')
            ->join('lessons as l', 'l.id', '=', 'c.lesson_id')->where('m.source_table', 'platform_page')
            ->where('m.target_domain', 'learning_lesson_body')->where('m.target_type', 'lesson_learning_content')
            ->where('m.mapping_status', 'mapped')->select(['m.source_id', 'c.lesson_id'])->get();
        foreach ($mappings as $mapping) {
            $this->lessonCandidates[(int) $mapping->source_id][] = (int) $mapping->lesson_id;
        }
        foreach ($this->lessonCandidates as &$ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);
        }
    }

    private function reportHeader(LegacyUserBundle $bundle): array
    {
        return ['format' => 'legacy_users_import_report_v1', 'manifest_sha256' => $bundle->manifestHash,
            'snapshot_id' => $bundle->manifest['snapshot_id'], 'mapping_version' => $bundle->manifest['mapping_version'],
            'target' => $this->target(), 'target_fingerprint' => $this->targetFingerprint(),
            'source_rows' => $bundle->manifest['source_rows'], 'exported_rows' => $bundle->manifest['exported_rows'],
            'source_dispositions' => array_map(fn (array $table): array => $table['dispositions'], $bundle->manifest['tables']),
            'changes' => [], 'identities' => [], 'issues' => [], 'orders' => [],
            'customer_login' => 'held', 'outbound_effects' => false, 'binary_files' => false, 'messenger_history' => false];
    }

    private function chunks(LegacyUserBundle $bundle, string $table): \Generator
    {
        $chunk = [];
        foreach ($bundle->rows($table) as $record) {
            $chunk[] = $record;
            if (count($chunk) >= config('legacy_users.chunk_size')) {
                yield $chunk;
                $chunk = [];
            }
        }
        if ($chunk) {
            yield $chunk;
        }
    }

    private function map(string $table, array $record, ?object $existing): array
    {
        $row = $this->mapper->attributes($table, $record, $this->profileIds, $this->orderIds, $this->lessonCandidates, $this->identities->questionPollIds);
        if ($table === 'member') {
            $decision = $this->identities->decide($row, $existing?->user_id);
            $row['user_id'] = $existing?->user_id;
            $row['mapping_status'] = $existing?->user_id || $decision['eligible'] ? 'linked' : 'historical_only';
            $row['managed_values'] = LegacyUserRecordMapper::json(array_intersect_key($decision, array_flip(['name', 'email', 'phone', 'profile', 'tags'])));
            $row['issues'] = LegacyUserRecordMapper::json($decision['issues']);
            $row['source_fingerprint'] = hash('sha256', $row['source_fingerprint'].$row['managed_values'].$row['issues']);
        }

        return $row;
    }

    private function query(string $destination, string $source): Builder
    {
        $query = DB::table($destination);

        return $destination === 'legacy_user_records' ? $query->where('source_table', $source) : $query;
    }

    private function writeChunk(string $table, array $chunk, string $runId): void
    {
        [$destination, $key] = $this->mapper->destination($table);
        $ids = array_column($chunk, 'source_id');
        $existing = $this->query($destination, $table)->whereIn($key, $ids)->lockForUpdate()->get()->keyBy($key);
        $writes = $versions = [];
        $now = now();
        foreach ($chunk as $record) {
            $old = $existing->get($record['source_id']);
            $row = $this->map($table, $record, $old);
            if ($old && $old->source_fingerprint === $row['source_fingerprint']) {
                continue;
            }
            if ($old) {
                $versions[] = ['record_type' => $destination, 'record_id' => $old->id,
                    'source_fingerprint' => $old->source_fingerprint, 'replaced_by_run_id' => $runId,
                    'previous_data' => LegacyUserRecordMapper::json((array) $old), 'created_at' => $now];
            }
            if ($table === 'member') {
                $row = $this->writeIdentity($row, $old);
            }
            foreach (['legacy_user_profile_id', 'legacy_user_order_id'] as $foreign) {
                if (isset($row[$foreign]) && $row[$foreign] < 0) {
                    throw new RuntimeException('Historical parent was not committed before its child.');
                }
            }
            $writes[] = $row + ['first_import_run_id' => $old?->first_import_run_id ?? $runId,
                'last_import_run_id' => $runId, 'created_at' => $old?->created_at ?? $now, 'updated_at' => $now];
        }
        if ($versions) {
            DB::table('legacy_user_record_versions')->insert($versions);
        }
        if ($writes) {
            $unique = $destination === 'legacy_user_records' ? ['source_table', $key] : [$key];
            DB::table($destination)->upsert($writes, $unique, array_values(array_diff(array_keys($writes[0]), ['first_import_run_id', 'created_at'])));
        }
        $saved = $this->query($destination, $table)->whereIn($key, $ids)->get()->keyBy($key);
        $maps = [];
        foreach ($saved as $sourceId => $row) {
            if ($table === 'member') {
                $this->profileIds[$sourceId] = $row->id;
            } elseif ($table === 'order') {
                $this->orderIds[$sourceId] = $row->id;
            }
            if (isset($existing[$sourceId]) && $existing[$sourceId]->source_fingerprint === $row->source_fingerprint) {
                continue;
            }
            $maps[] = ['batch_key' => $runId, 'source_table' => $table, 'source_id' => (string) $sourceId,
                'source_scope_type' => 'legacy_users', 'source_scope_id' => 'main_school_db', 'target_domain' => 'legacy_user_history',
                'target_type' => $destination, 'target_id' => $row->id, 'mapping_status' => 'mapped',
                'validation_status' => json_decode($row->issues, true) ? 'manual_review' : 'passed',
                'idempotency_key' => 'legacy-users:main_school_db:'.$table.':'.$sourceId,
                'source_fingerprint_hash' => $row->source_fingerprint,
                'manual_review_reason' => json_decode($row->issues, true) ? 'Historical source requires review; no active rights granted.' : null,
                'errors' => $row->issues, 'metadata' => LegacyUserRecordMapper::json(['historical_only' => true]),
                'created_at' => $existing[$sourceId]->created_at ?? $now, 'updated_at' => $now];
        }
        if ($maps) {
            DB::table('legacy_import_mappings')->upsert($maps, ['idempotency_key'], array_values(array_diff(array_keys($maps[0]), ['idempotency_key', 'created_at'])));
        }
    }

    private function writeIdentity(array $row, ?object $old): array
    {
        $desired = json_decode($row['managed_values'], true, 64, JSON_THROW_ON_ERROR);
        $now = now();
        if (! $old?->user_id && $row['mapping_status'] === 'linked') {
            $row['user_id'] = DB::table('users')->insertGetId([
                'name' => $desired['name'], 'email' => $desired['email'], 'phone' => $desired['phone'],
                'password' => '!migration-held:'.bin2hex(random_bytes(32)), 'password_state' => User::PASSWORD_STATE_MIGRATION_HOLD,
                'migration_hold' => true, 'role' => 'user_no_purchase', 'manager_id' => null,
                'email_verified_at' => null, 'phone_verified_at' => null, 'legacy_auto_verified' => false,
                'created_at' => $row['source_created_at'] ?? $now, 'updated_at' => $now,
            ]);
            DB::table('user_profiles')->insert(['user_id' => $row['user_id']] + $desired['profile'] + ['revision' => 0, 'created_at' => $now, 'updated_at' => $now]);
            $this->writeTags($row['user_id'], $desired['tags']);
        } elseif ($old?->user_id) {
            $user = DB::table('users')->where('id', $old->user_id)->lockForUpdate()->first();
            $profile = DB::table('user_profiles')->where('user_id', $old->user_id)->lockForUpdate()->first();
            if (! $user || ! $profile) {
                throw new RuntimeException('Imported identity was removed; explicit reconciliation is required.');
            }
            $previous = json_decode($old->managed_values, true, 64, JSON_THROW_ON_ERROR);
            $issues = json_decode($row['issues'], true, 64, JSON_THROW_ON_ERROR);
            $name = LegacyUserIdentityPlan::mergeFields(['name' => $user->name], ['name' => $previous['name']], ['name' => $desired['name']]);
            $fields = LegacyUserIdentityPlan::mergeFields((array) $profile, $previous['profile'], $desired['profile']);
            foreach (array_merge($name['conflicts'], $fields['conflicts']) as $field) {
                $issues[] = ['field' => $field, 'reason' => 'manual_change_preserved'];
            }
            foreach (['email', 'phone'] as $field) {
                if ($desired[$field] !== $user->$field) {
                    $issues[] = ['field' => $field, 'reason' => 'contact_change_requires_review'];
                }
            }
            $changes = array_intersect_key($fields['values'], $desired['profile']);
            if ($changes !== array_intersect_key((array) $profile, $desired['profile']) || $name['values']['name'] !== $user->name) {
                DB::table('users')->where('id', $user->id)->update(['name' => $name['values']['name'], 'updated_at' => $now]);
                DB::table('user_profiles')->where('user_id', $user->id)->update($changes + ['revision' => $profile->revision + 1, 'updated_at' => $now]);
            }
            $currentTags = DB::table('user_tags as t')->join('user_tag as p', 'p.user_tag_id', '=', 't.id')->where('p.user_id', $user->id)->orderBy('t.name')->pluck('t.name')->all();
            if ($currentTags === $previous['tags']) {
                $this->writeTags($user->id, $desired['tags']);
            } elseif ($currentTags !== $desired['tags']) {
                $issues[] = ['field' => 'tags', 'reason' => 'manual_change_preserved'];
            }
            $row['issues'] = LegacyUserRecordMapper::json($issues);
        }

        return $row;
    }

    private function writeTags(int $userId, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            DB::table('user_tags')->insertOrIgnore(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            $ids[] = DB::table('user_tags')->where('name', $name)->value('id');
        }
        DB::table('user_tag')->where('user_id', $userId)->whereNotIn('user_tag_id', $ids)->delete();
        foreach ($ids as $id) {
            DB::table('user_tag')->insertOrIgnore(['user_id' => $userId, 'user_tag_id' => $id]);
        }
    }
}
