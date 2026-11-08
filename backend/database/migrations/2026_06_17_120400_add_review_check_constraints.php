<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_status_check CHECK (status IN ('draft', 'reviewed', 'published', 'rejected'))");
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_source_check CHECK (source IN ('student', 'admin', 'legacy_ocr', 'legacy_manual', 'dev_seed'))");
        DB::statement("ALTER TABLE review_media ADD CONSTRAINT review_media_type_check CHECK (type IN ('attachment', 'legacy_screenshot'))");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE review_media DROP CONSTRAINT IF EXISTS review_media_type_check');
        DB::statement('ALTER TABLE reviews DROP CONSTRAINT IF EXISTS reviews_source_check');
        DB::statement('ALTER TABLE reviews DROP CONSTRAINT IF EXISTS reviews_status_check');
        DB::statement('ALTER TABLE reviews DROP CONSTRAINT IF EXISTS reviews_rating_check');
    }
};
