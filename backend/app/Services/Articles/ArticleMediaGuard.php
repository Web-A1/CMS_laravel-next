<?php

namespace App\Services\Articles;

use Closure;
use Illuminate\Support\Facades\DB;

final class ArticleMediaGuard
{
    public function run(Closure $work): mixed
    {
        return DB::transaction(function () use ($work) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(1769305165, 1)');
            }

            return $work();
        });
    }
}
