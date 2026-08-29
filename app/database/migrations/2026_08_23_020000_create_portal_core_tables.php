<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('lock_starts_at');
            $table->timestampTz('lock_ends_at');
            $table->string('status', 32)->default('confirmed');
            $table->text('purpose')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('first_connected_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('end_reason', 32)->nullable();
            $table->timestampsTz();
            $table->index(['user_id', 'starts_at']);
            $table->index(['status', 'lock_starts_at']);
        });

        Schema::create('maintenance_windows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->text('reason');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('automatic')->default(false);
            $table->timestampsTz();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('actor_id')->nullable();
            $table->string('action', 128);
            $table->string('target_type', 255)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->uuid('request_id')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['action', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE reservations
              ADD CONSTRAINT reservations_status_check CHECK (
                status IN ('confirmed', 'provisioning', 'active', 'ending',
                           'completed', 'cancelled', 'failed', 'resource_locked')
              ),
              ADD CONSTRAINT reservations_duration_check CHECK (
                ends_at > starts_at AND ends_at <= starts_at + INTERVAL '8 hours'
              ),
              ADD CONSTRAINT reservations_ends_on_hour CHECK (
                (EXTRACT(EPOCH FROM ends_at)::bigint % 3600) = 0
              ),
              ADD CONSTRAINT reservations_lock_bounds CHECK (
                lock_starts_at <= starts_at AND lock_ends_at >= ends_at
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

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_audit_event_mutation() RETURNS trigger AS $$
            BEGIN
              RAISE EXCEPTION 'audit_events is append-only' USING ERRCODE = '42501';
            END;
            $$ LANGUAGE plpgsql
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER audit_events_append_only
            BEFORE UPDATE OR DELETE ON audit_events
            FOR EACH ROW EXECUTE FUNCTION prevent_audit_event_mutation()
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS prevent_audit_event_mutation()');
        }

        Schema::dropIfExists('maintenance_windows');
        Schema::dropIfExists('reservations');
    }
};
