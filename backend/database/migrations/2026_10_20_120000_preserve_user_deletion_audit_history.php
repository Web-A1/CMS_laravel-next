<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Historical IDs deliberately remain scalar references after account erasure.
    private function references(): array
    {
        return [
            'admin_user_events' => ['user_id' => ['users', 'restrict'], 'actor_id' => ['users', 'set null']],
            'admin_impersonation_events' => ['actor_id' => ['users', 'set null'], 'subject_id' => ['users', 'set null']],
            'payment_audit_log' => ['order_id' => ['orders', 'cascade'], 'attempt_id' => ['payment_attempts', 'set null'], 'event_id' => ['provider_events', 'set null'], 'refund_id' => ['refunds', 'set null'], 'actor_id' => ['users', 'set null']],
            'provider_events' => ['order_id' => ['orders', 'set null'], 'attempt_id' => ['payment_attempts', 'set null']],
            'outbox_events' => ['order_id' => ['orders', 'set null'], 'attempt_id' => ['payment_attempts', 'set null'], 'refund_id' => ['refunds', 'set null']],
            'side_effect_executions' => ['order_id' => ['orders', 'set null'], 'attempt_id' => ['payment_attempts', 'set null'], 'refund_id' => ['refunds', 'set null']],
            'review_moderation_events' => ['review_id' => ['reviews', 'cascade'], 'actor_user_id' => ['users', 'set null']],
            'lesson_assessment_review_events' => ['lesson_assessment_attempt_id' => ['lesson_assessment_attempts', 'restrict'], 'lesson_assessment_manual_review_id' => ['lesson_assessment_manual_reviews', 'restrict']],
            'coin_ledger_entries' => ['user_id' => ['users', 'restrict'], 'order_id' => ['orders', 'restrict'], 'reservation_id' => ['coin_reservations', 'restrict']],
            'coin_reservation_allocations' => ['reservation_id' => ['coin_reservations', 'restrict']],
            'video_watermark_sessions' => ['user_id' => ['users', 'restrict']],
            'trusted_browser_audit_events' => ['account_id' => ['trusted_browser_accounts', 'set null'], 'user_id' => ['users', 'set null'], 'actor_user_id' => ['users', 'set null'], 'slot_id' => ['trusted_browser_slots', 'set null'], 'credential_id' => ['trusted_browser_credentials', 'set null'], 'challenge_id' => ['trusted_browser_challenges', 'set null']],
            'legacy_user_records' => ['legacy_user_profile_id' => ['legacy_user_profiles', 'restrict'], 'legacy_user_order_id' => ['legacy_user_orders', 'restrict']],
        ];
    }

    public function up(): void
    {
        foreach ($this->references() as $table => $references) {
            $names = [];
            foreach (Schema::getForeignKeys($table) as $foreign) {
                if (count($foreign['columns']) === 1 && isset($references[$foreign['columns'][0]])
                    || $table === 'lesson_assessment_review_events' && count($foreign['columns']) === 2) {
                    $names[] = DB::getDriverName() === 'sqlite' ? $foreign['columns'] : $foreign['name'];
                }
            }
            if ($names !== []) {
                Schema::table($table, function (Blueprint $blueprint) use ($names): void {
                    foreach ($names as $name) {
                        $blueprint->dropForeign($name);
                    }
                });
            }
        }
        Schema::create('protected_file_output_deletions', function (Blueprint $table): void {
            $table->id();
            $table->string('disk');
            $table->text('object_key');
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Never repair history by deleting it during rollback.
        foreach ($this->references() as $table => $references) {
            foreach ($references as $column => [$parent]) {
                if (DB::table($table)->whereNotNull($column)->whereNotExists(function ($query) use ($table, $column, $parent): void {
                    $query->selectRaw('1')->from($parent)->whereColumn($parent.'.id', $table.'.'.$column);
                })->exists()) {
                    throw new RuntimeException('Нельзя восстановить FK: сохранены исторические ссылки '.$table.'.'.$column);
                }
            }
        }
        if (DB::table('protected_file_output_deletions')->whereNull('completed_at')->exists()) {
            throw new RuntimeException('Сначала завершите удаление персональных PDF.');
        }
        foreach ($this->references() as $table => $references) {
            Schema::table($table, function (Blueprint $blueprint) use ($references): void {
                foreach ($references as $column => [$parent, $action]) {
                    $blueprint->foreign($column)->references('id')->on($parent)->onDelete($action);
                }
            });
        }
        Schema::table('lesson_assessment_review_events', function (Blueprint $table): void {
            $table->foreign(['lesson_assessment_manual_review_id', 'lesson_assessment_attempt_id'], 'lesson_assessment_review_events_review_attempt_foreign')
                ->references(['id', 'lesson_assessment_attempt_id'])->on('lesson_assessment_manual_reviews')->restrictOnDelete();
        });
        Schema::drop('protected_file_output_deletions');
    }
};
