<?php

use App\Models\ComputeNode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compute_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 80)->unique();
            $table->string('display_name', 80)->unique();
            $table->string('host_ip', 45)->unique();
            $table->boolean('visible_in_reservations')->default(true);
            $table->string('scheduling_state', 24)->default('maintenance');
            $table->integer('sort_order')->default(0);
            $table->jsonb('capabilities')->default('[]');
            $table->string('worker_revision', 128)->nullable();
            $table->string('workflow_revision', 128)->nullable();
            $table->char('model_manifest_sha256', 64)->nullable();
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->jsonb('last_health_summary')->nullable();
            $table->string('last_error_code', 128)->nullable();
            $table->timestampsTz();
            $table->index(['visible_in_reservations', 'sort_order']);
            $table->index(['scheduling_state', 'last_heartbeat_at']);
        });

        $now = now();
        $capabilities = json_encode(['h3', 'qwen', 'z-image', 'hunyuan'], JSON_THROW_ON_ERROR);
        DB::table('compute_nodes')->insert([
            [
                'id' => ComputeNode::LOCAL_NODE_ID,
                'slug' => ComputeNode::LOCAL_NODE_SLUG,
                'display_name' => 'AI 制作服务器 01',
                'host_ip' => '192.168.88.20',
                'visible_in_reservations' => true,
                'scheduling_state' => 'online',
                'sort_order' => 10,
                'capabilities' => $capabilities,
                'last_heartbeat_at' => $now,
                'last_health_summary' => json_encode(['ok' => true, 'mode' => 'migration-bootstrap'], JSON_THROW_ON_ERROR),
                'last_error_code' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => ComputeNode::SECONDARY_NODE_ID,
                'slug' => ComputeNode::SECONDARY_NODE_SLUG,
                'display_name' => 'AI 制作服务器 02',
                'host_ip' => '192.168.88.200',
                'visible_in_reservations' => true,
                'scheduling_state' => 'maintenance',
                'sort_order' => 20,
                'capabilities' => $capabilities,
                'last_heartbeat_at' => null,
                'last_health_summary' => json_encode(['ok' => false, 'error' => 'not_connected'], JSON_THROW_ON_ERROR),
                'last_error_code' => 'not_connected',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Schema::table('reservations', function (Blueprint $table) {
            $table->foreignUuid('compute_node_id')
                ->nullable()
                ->after('user_id')
                ->constrained('compute_nodes')
                ->restrictOnDelete();
            $table->index(['compute_node_id', 'starts_at']);
        });
        DB::table('reservations')->whereNull('compute_node_id')->update([
            'compute_node_id' => ComputeNode::LOCAL_NODE_ID,
        ]);

        Schema::table('maintenance_windows', function (Blueprint $table) {
            $table->foreignUuid('compute_node_id')
                ->nullable()
                ->after('id')
                ->constrained('compute_nodes')
                ->restrictOnDelete();
            $table->index(['compute_node_id', 'starts_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement('ALTER TABLE compute_nodes ALTER COLUMN host_ip TYPE inet USING host_ip::inet');
        DB::statement(<<<'SQL'
            ALTER TABLE compute_nodes
              ADD CONSTRAINT compute_nodes_host_ip_is_ipv4_host
              CHECK (family(host_ip) = 4 AND masklen(host_ip) = 32),
              ADD CONSTRAINT compute_nodes_scheduling_state_check
              CHECK (scheduling_state IN ('online', 'draining', 'maintenance', 'offline'))
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX compute_nodes_display_name_ci_unique
              ON compute_nodes (lower(display_name))
            SQL);
        DB::statement('ALTER TABLE reservations ALTER COLUMN compute_node_id SET NOT NULL');
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_no_overlap');
        DB::statement('DROP INDEX IF EXISTS reservations_one_runtime_owner');
        DB::statement(<<<'SQL'
            ALTER TABLE reservations
              ADD CONSTRAINT reservations_no_overlap_per_node
              EXCLUDE USING gist (
                compute_node_id WITH =,
                tstzrange(lock_starts_at, lock_ends_at, '[)') WITH &&
              )
              WHERE (status IN ('confirmed', 'provisioning', 'active', 'ending'))
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE reservations
              ADD CONSTRAINT reservations_no_overlap_per_user
              EXCLUDE USING gist (
                user_id WITH =,
                tstzrange(lock_starts_at, lock_ends_at, '[)') WITH &&
              )
              WHERE (status IN ('confirmed', 'provisioning', 'active', 'ending'))
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX reservations_one_runtime_owner_per_node
              ON reservations (compute_node_id)
              WHERE (status IN ('provisioning', 'active', 'ending'))
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_no_overlap_per_node');
            DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_no_overlap_per_user');
            DB::statement('DROP INDEX IF EXISTS reservations_one_runtime_owner_per_node');
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

        Schema::table('maintenance_windows', function (Blueprint $table) {
            $table->dropIndex('maintenance_windows_compute_node_id_starts_at_index');
            $table->dropConstrainedForeignId('compute_node_id');
        });
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex('reservations_compute_node_id_starts_at_index');
            $table->dropConstrainedForeignId('compute_node_id');
        });
        Schema::dropIfExists('compute_nodes');
    }
};
