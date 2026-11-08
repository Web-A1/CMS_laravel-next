<?php

namespace App\Services\LegacyUsers;

use Illuminate\Support\Facades\DB;
use LogicException;

final class LegacyUserMigrationLock
{
    public static function shared(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Migration coordination requires a transaction.');
        }
        if (DB::connection()->getDriverName() === 'pgsql') {
            // ImportLegacyUsersCommand holds the exclusive session lock on this pair.
            DB::select('SELECT pg_advisory_xact_lock_shared(183621719, 104)');
        } elseif (DB::connection()->getDriverName() !== 'sqlite') {
            throw new LogicException('Unsupported migration coordination driver.');
        }
    }
}
