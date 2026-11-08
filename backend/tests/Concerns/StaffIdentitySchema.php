<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait StaffIdentitySchema
{
    public function test_identity_migration_preserves_values_handles_nullable_drift_and_refuses_lossy_rollback(): void
    {
        $migration = require database_path('migrations/2026_11_06_000001_add_staff_username_to_users.php');
        $old = User::factory()->create(['email' => 'preserved@example.test']);
        $before = $old->fresh()->getRawOriginal();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'username'));
        $migration->up();
        $this->assertSame($before, $old->fresh()->getRawOriginal());
        $migration->down();
        Schema::table('users', fn (Blueprint $table) => $table->string('email')->nullable()->change());
        $migration->up();
        $this->assertSame('preserved@example.test', $old->fresh()->email);
        $first = User::factory()->create(['email' => null, 'username' => ' POLINA ']);
        User::factory()->create(['email' => null, 'username' => null]);
        User::factory()->create(['email' => null, 'username' => null]);
        $this->assertSame('polina', $first->username);
        try {
            $migration->down();
            $this->fail('Rollback cannot discard identity data.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('rollback refused', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('users', 'username'));
        $this->assertSame('polina', $first->fresh()->username);
        try {
            User::factory()->create(['email' => null, 'username' => 'POLINA']);
            $this->fail('Unique username must reject normalized duplicates.');
        } catch (QueryException) {
            $this->assertSame(4, User::count());
        }
        try {
            User::factory()->create(['email' => 'preserved@example.test']);
            $this->fail('Existing unique email must remain intact.');
        } catch (QueryException) {
            $this->assertSame(4, User::count());
        }
    }
}
