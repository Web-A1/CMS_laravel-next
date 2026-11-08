<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropIndex(['rating', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reviews DROP CONSTRAINT IF EXISTS reviews_rating_check');
            DB::statement('ALTER TABLE review_sources DROP CONSTRAINT IF EXISTS review_sources_external_rating_check');
        }

        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropColumn('rating');
        });

        Schema::table('review_sources', function (Blueprint $table): void {
            $table->dropColumn('external_rating');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->unsignedTinyInteger('rating')->default(5)->after('user_id');
            $table->index(['rating', 'status']);
        });

        Schema::table('review_sources', function (Blueprint $table): void {
            $table->unsignedTinyInteger('external_rating')->nullable()->after('external_author_name');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');
            DB::statement('ALTER TABLE review_sources ADD CONSTRAINT review_sources_external_rating_check CHECK (external_rating BETWEEN 1 AND 5)');
        }
    }
};
