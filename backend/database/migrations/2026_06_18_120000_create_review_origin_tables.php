<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('source_type');
            $table->string('batch_ref')->nullable()->unique();
            $table->string('source_label')->nullable();
            $table->string('source_path')->nullable();
            $table->string('source_url')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('records_total')->default(0);
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->json('stats')->nullable();
            $table->json('errors')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'status']);
            $table->index(['finished_at', 'source_type']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->string('moderation_status')->nullable()->after('status');
            $table->string('publication_status')->nullable()->after('moderation_status');
            $table->string('dedupe_status')->nullable()->after('publication_status');
            $table->string('origin_type')->nullable()->after('dedupe_status');
            $table->foreignId('created_from_import_batch_id')
                ->nullable()
                ->after('origin_type')
                ->constrained('review_import_batches')
                ->nullOnDelete();

            $table->index(['moderation_status', 'publication_status'], 'reviews_moderation_publication_index');
            $table->index(['publication_status', 'published_at'], 'reviews_publication_published_at_index');
            $table->index(['dedupe_status', 'origin_type'], 'reviews_dedupe_origin_index');
        });

        DB::table('reviews')->orderBy('id')->chunkById(500, function ($reviews): void {
            foreach ($reviews as $review) {
                $workflow = match ($review->status) {
                    'published' => ['approved', 'published'],
                    'reviewed' => ['approved', 'draft'],
                    'rejected' => ['rejected', 'archived'],
                    default => ['pending', 'draft'],
                };

                DB::table('reviews')
                    ->where('id', $review->id)
                    ->update([
                        'moderation_status' => $workflow[0],
                        'publication_status' => $workflow[1],
                        'dedupe_status' => 'unchecked',
                        'origin_type' => match ($review->source) {
                            'student' => 'platform',
                            'admin', 'legacy_manual' => 'manual',
                            'legacy_ocr' => 'legacy_import',
                            'dev_seed' => 'dev_seed',
                            default => 'legacy_import',
                        },
                    ]);
            }
        });

        Schema::create('review_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('review_import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type');
            $table->string('source_ref')->nullable();
            $table->string('source_label')->nullable();
            $table->string('source_url')->nullable();
            $table->boolean('is_public')->default(false);
            $table->string('external_id')->nullable();
            $table->string('external_author_name')->nullable();
            $table->unsignedTinyInteger('external_rating')->nullable();
            $table->timestamp('external_written_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->text('raw_text')->nullable();
            $table->json('raw_payload')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_ref']);
            $table->unique(['source_type', 'external_id']);
            $table->index(['review_id', 'source_type']);
            $table->index(['source_type', 'content_hash']);
            $table->index(['is_public', 'source_url']);
        });

        Schema::create('review_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('review_source_id')->nullable()->constrained('review_sources')->cascadeOnDelete();
            $table->foreignId('review_import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('file_path')->nullable();
            $table->string('storage_url')->nullable();
            $table->string('mime_type')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->string('caption')->nullable();
            $table->string('provenance')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['review_id', 'type']);
            $table->index(['review_source_id', 'type']);
            $table->index(['review_import_batch_id', 'type']);
            $table->index(['sha256']);
        });

        Schema::create('review_duplicate_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_review_id')->nullable()->constrained('reviews')->cascadeOnDelete();
            $table->foreignId('candidate_review_source_id')->nullable()->constrained('review_sources')->cascadeOnDelete();
            $table->decimal('similarity_score', 5, 4)->nullable();
            $table->string('match_type');
            $table->string('status')->default('pending');
            $table->text('reason')->nullable();
            $table->json('signals')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['review_id', 'status']);
            $table->unique(['review_id', 'candidate_review_id']);
            $table->index(['candidate_review_id', 'status']);
            $table->index(['candidate_review_source_id', 'status']);
        });

        Schema::create('review_moderation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type');
            $table->string('from_moderation_status')->nullable();
            $table->string('to_moderation_status')->nullable();
            $table->string('from_publication_status')->nullable();
            $table->string('to_publication_status')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['review_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE review_import_batches ADD CONSTRAINT review_import_batches_status_check CHECK (status IN ('pending', 'running', 'completed', 'failed'))");
            DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_moderation_status_check CHECK (moderation_status IN ('pending', 'needs_review', 'approved', 'rejected'))");
            DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_publication_status_check CHECK (publication_status IN ('draft', 'published', 'hidden', 'archived'))");
            DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_dedupe_status_check CHECK (dedupe_status IN ('unchecked', 'unique', 'candidate', 'duplicate'))");
            DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_origin_type_check CHECK (origin_type IN ('platform', 'legacy_import', 'manual', 'dev_seed'))");
            DB::statement("ALTER TABLE review_sources ADD CONSTRAINT review_sources_type_check CHECK (source_type IN ('platform', 'telegram', 'vk_webarchive', 'yandex_maps_webarchive', 'legacy_site', 'manual'))");
            DB::statement('ALTER TABLE review_sources ADD CONSTRAINT review_sources_external_rating_check CHECK (external_rating BETWEEN 1 AND 5)');
            DB::statement('ALTER TABLE review_sources ADD CONSTRAINT review_sources_public_url_check CHECK (is_public = false OR source_url IS NOT NULL)');
            DB::statement("ALTER TABLE review_evidence ADD CONSTRAINT review_evidence_type_check CHECK (type IN ('screenshot', 'webarchive', 'html_snapshot', 'image', 'attachment'))");
            DB::statement("ALTER TABLE review_duplicate_candidates ADD CONSTRAINT review_duplicate_candidates_status_check CHECK (status IN ('pending', 'confirmed_duplicate', 'rejected', 'merged'))");
            DB::statement('ALTER TABLE review_duplicate_candidates ADD CONSTRAINT review_duplicate_candidates_similarity_check CHECK (similarity_score IS NULL OR (similarity_score >= 0 AND similarity_score <= 1))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('review_moderation_events');
        Schema::dropIfExists('review_duplicate_candidates');
        Schema::dropIfExists('review_evidence');
        Schema::dropIfExists('review_sources');

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_from_import_batch_id');
            $table->dropColumn([
                'moderation_status',
                'publication_status',
                'dedupe_status',
                'origin_type',
            ]);
        });

        Schema::dropIfExists('review_import_batches');
    }
};
