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
            $table->boolean('show_on_home')->default(false);
        });

        // Preserve the existing homepage selection; new reviews require an explicit choice.
        $ids = DB::table('reviews')
            ->where(fn ($query) => $query->where('publication_status', 'published')
                ->orWhere(fn ($query) => $query->whereNull('publication_status')->where('status', 'published')))
            ->where(fn ($query) => $query->where('moderation_status', 'approved')->orWhereNull('moderation_status'))
            ->where(fn ($query) => $query->where('dedupe_status', '!=', 'duplicate')->orWhereNull('dedupe_status'))
            ->whereNotNull('published_at')->where('published_at', '<=', now())
            ->orderByRaw('COALESCE(review_written_at, published_at) DESC')
            ->orderByDesc('id')->limit(20)->pluck('id');
        DB::table('reviews')->whereIn('id', $ids)->update(['show_on_home' => true]);
    }

    public function down(): void
    {
        Schema::table('reviews', fn (Blueprint $table) => $table->dropColumn('show_on_home'));
    }
};
