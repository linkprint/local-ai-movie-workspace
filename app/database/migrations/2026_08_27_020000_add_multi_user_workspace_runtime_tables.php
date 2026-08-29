<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_runtimes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('workspace_project_id')->constrained('workspace_projects')->restrictOnDelete();
            $table->string('status', 32)->default('provisioning');
            $table->string('auth_mode', 16);
            $table->string('session_mode', 16)->default('new');
            $table->uuid('session_id')->nullable();
            $table->string('container_name', 80)->nullable();
            $table->string('network_name', 96)->nullable();
            $table->unsignedBigInteger('generation')->default(1);
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('idle_expires_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('stopped_at')->nullable();
            $table->string('failure_reason', 64)->nullable();
            $table->timestampsTz();
            $table->index(['status', 'idle_expires_at']);
            $table->index(['workspace_project_id', 'status']);
        });

        Schema::create('company_codex_leases', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->foreignUuid('workspace_runtime_id')
                ->nullable()
                ->unique()
                ->constrained('workspace_runtimes')
                ->restrictOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('available');
            $table->uuid('fencing_token')->nullable();
            $table->timestampTz('acquired_at')->nullable();
            $table->timestampTz('heartbeat_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();
        });

        Schema::table('reservations', function (Blueprint $table): void {
            $table->foreignUuid('workspace_runtime_id')
                ->nullable()
                ->after('workspace_stopped_at')
                ->constrained('workspace_runtimes')
                ->restrictOnDelete();
            $table->unsignedBigInteger('ai_grant_generation')->nullable()->after('workspace_runtime_id');
            $table->timestampTz('ai_granted_at')->nullable()->after('ai_grant_generation');
            $table->timestampTz('ai_revoked_at')->nullable()->after('ai_granted_at');
            $table->index(['workspace_runtime_id', 'status'], 'reservations_runtime_status_index');
        });

        DB::table('company_codex_leases')->insert([
            'id' => 1,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE workspace_runtimes
              ADD CONSTRAINT workspace_runtimes_status_check CHECK (
                status IN ('provisioning', 'running', 'stopping', 'stopped', 'resource_locked')
              ),
              ADD CONSTRAINT workspace_runtimes_auth_mode_check CHECK (
                auth_mode IN ('personal', 'company')
              ),
              ADD CONSTRAINT workspace_runtimes_session_mode_check CHECK (
                session_mode IN ('new', 'resume')
              ),
              ADD CONSTRAINT workspace_runtimes_generation_check CHECK (generation > 0),
              ADD CONSTRAINT workspace_runtimes_session_selection_check CHECK (
                (session_mode = 'new' AND session_id IS NULL)
                OR (session_mode = 'resume' AND session_id IS NOT NULL AND auth_mode = 'personal')
              )
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE company_codex_leases
              ADD CONSTRAINT company_codex_leases_singleton_check CHECK (id = 1),
              ADD CONSTRAINT company_codex_leases_status_check CHECK (
                status IN ('available', 'acquiring', 'active', 'releasing', 'resource_locked')
              ),
              ADD CONSTRAINT company_codex_leases_binding_check CHECK (
                (status = 'available' AND workspace_runtime_id IS NULL AND user_id IS NULL AND fencing_token IS NULL)
                OR (status <> 'available' AND workspace_runtime_id IS NOT NULL AND user_id IS NOT NULL AND fencing_token IS NOT NULL)
              )
            SQL);
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropIndex('reservations_runtime_status_index');
            $table->dropConstrainedForeignId('workspace_runtime_id');
            $table->dropColumn(['ai_grant_generation', 'ai_granted_at', 'ai_revoked_at']);
        });

        Schema::dropIfExists('company_codex_leases');
        Schema::dropIfExists('workspace_runtimes');
    }
};
