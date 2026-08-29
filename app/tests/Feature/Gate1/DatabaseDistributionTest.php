<?php

namespace Tests\Feature\Gate1;

use App\Models\CompanyCodexLease;
use App\Models\ComputeNode;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseDistributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_seeder_restores_only_sanitized_system_configuration(): void
    {
        ComputeNode::query()
            ->whereKey(ComputeNode::LOCAL_NODE_ID)
            ->update(['display_name' => 'Operator customized node']);
        ComputeNode::query()->whereKey(ComputeNode::SECONDARY_NODE_ID)->delete();
        DB::table('company_codex_leases')->delete();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('compute_nodes', [
            'id' => ComputeNode::LOCAL_NODE_ID,
            'display_name' => 'Operator customized node',
        ]);
        $this->assertDatabaseHas('compute_nodes', [
            'id' => ComputeNode::SECONDARY_NODE_ID,
            'host_ip' => '192.168.88.200',
            'scheduling_state' => 'maintenance',
        ]);
        $this->assertDatabaseHas('company_codex_leases', [
            'id' => CompanyCodexLease::SINGLETON_ID,
            'status' => 'available',
        ]);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('maintenance_windows', 0);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertDatabaseCount('workspace_profiles', 0);
        $this->assertDatabaseCount('workspace_projects', 0);
        $this->assertDatabaseCount('workspace_runtimes', 0);
        $this->assertDatabaseCount('sessions', 0);
        $this->assertDatabaseCount('jobs', 0);
    }
}
