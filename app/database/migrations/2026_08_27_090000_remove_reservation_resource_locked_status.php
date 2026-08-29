<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('reservations')
            ->where('status', 'resource_locked')
            ->update(['status' => 'failed', 'end_reason' => 'cleanup_failed']);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_no_overlap');
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_status_check');
        DB::statement('DROP INDEX IF EXISTS reservations_one_runtime_owner');
        DB::statement(<<<'SQL'
            ALTER TABLE reservations
              ADD CONSTRAINT reservations_status_check CHECK (
                status IN ('confirmed', 'provisioning', 'active', 'ending',
                           'completed', 'cancelled', 'failed')
              )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE reservations
              ADD CONSTRAINT reservations_no_overlap
              EXCLUDE USING gist (
                tstzrange(lock_starts_at, lock_ends_at, '[)') WITH &&
              )
              WHERE (status IN ('confirmed', 'provisioning', 'active', 'ending'))
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX reservations_one_runtime_owner
              ON reservations ((true))
              WHERE (status IN ('provisioning', 'active', 'ending'))
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_no_overlap');
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_status_check');
        DB::statement('DROP INDEX IF EXISTS reservations_one_runtime_owner');
        DB::statement(<<<'SQL'
            ALTER TABLE reservations
              ADD CONSTRAINT reservations_status_check CHECK (
                status IN ('confirmed', 'provisioning', 'active', 'ending',
                           'completed', 'cancelled', 'failed', 'resource_locked')
              )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE reservations
              ADD CONSTRAINT reservations_no_overlap
              EXCLUDE USING gist (
                tstzrange(lock_starts_at, lock_ends_at, '[)') WITH &&
              )
              WHERE (status IN ('confirmed', 'provisioning', 'active', 'ending', 'resource_locked'))
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX reservations_one_runtime_owner
              ON reservations ((true))
              WHERE (status IN ('provisioning', 'active', 'ending', 'resource_locked'))
            SQL);
    }
};
