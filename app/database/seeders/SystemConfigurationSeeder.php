<?php

namespace Database\Seeders;

use App\Models\CompanyCodexLease;
use App\Models\ComputeNode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SystemConfigurationSeeder extends Seeder
{
    /**
     * Restore only the sanitized baseline rows required by the control plane.
     * Existing operator configuration is intentionally never overwritten.
     */
    public function run(): void
    {
        $now = now();

        DB::table('company_codex_leases')->insertOrIgnore([
            'id' => CompanyCodexLease::SINGLETON_ID,
            'status' => 'available',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $capabilities = json_encode(
            ['h3', 'qwen', 'z-image', 'hunyuan'],
            JSON_THROW_ON_ERROR,
        );

        DB::table('compute_nodes')->insertOrIgnore([
            [
                'id' => ComputeNode::LOCAL_NODE_ID,
                'slug' => ComputeNode::LOCAL_NODE_SLUG,
                'display_name' => 'Compute Node 01',
                'host_ip' => '192.168.88.20',
                'visible_in_reservations' => true,
                'scheduling_state' => 'online',
                'sort_order' => 10,
                'capabilities' => $capabilities,
                'last_heartbeat_at' => $now,
                'last_health_summary' => json_encode(
                    ['ok' => true, 'mode' => 'migration-bootstrap'],
                    JSON_THROW_ON_ERROR,
                ),
                'last_error_code' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => ComputeNode::SECONDARY_NODE_ID,
                'slug' => ComputeNode::SECONDARY_NODE_SLUG,
                'display_name' => 'Compute Node 02',
                'host_ip' => '192.168.88.200',
                'visible_in_reservations' => true,
                'scheduling_state' => 'maintenance',
                'sort_order' => 20,
                'capabilities' => $capabilities,
                'last_heartbeat_at' => null,
                'last_health_summary' => json_encode(
                    ['ok' => false, 'error' => 'not_connected'],
                    JSON_THROW_ON_ERROR,
                ),
                'last_error_code' => 'not_connected',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}
