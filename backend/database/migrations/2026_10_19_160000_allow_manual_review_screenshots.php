<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE review_media DROP CONSTRAINT review_media_type_check');
        DB::statement("ALTER TABLE review_media ADD CONSTRAINT review_media_type_check CHECK (type IN ('attachment', 'legacy_screenshot', 'screenshot'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // Restoring the narrower constraint must fail rather than discard manual screenshots.
        DB::statement('ALTER TABLE review_media DROP CONSTRAINT review_media_type_check');
        DB::statement("ALTER TABLE review_media ADD CONSTRAINT review_media_type_check CHECK (type IN ('attachment', 'legacy_screenshot'))");
    }
};
