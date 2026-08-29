<?php

namespace Tests\Feature\Gate2;

use App\Enums\ComputeNodeSchedulingState;
use App\Enums\UserRole;
use App\Filament\Resources\ComputeNodes\Pages\CreateComputeNode;
use App\Models\ComputeNode;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

class AdminComputeNodeTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_admin_can_register_name_and_private_ip_but_new_node_stays_abnormal(): void
    {
        Livewire::test(CreateComputeNode::class)
            ->fillForm([
                'display_name' => 'AI 制作服务器 03',
                'host_ip' => '192.168.88.50',
                'visible_in_reservations' => true,
                'scheduling_state' => ComputeNodeSchedulingState::Online->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $node = ComputeNode::query()->where('display_name', 'AI 制作服务器 03')->sole();
        $this->assertSame('192.168.88.50', (string) $node->host_ip);
        $this->assertSame(ComputeNodeSchedulingState::Maintenance, $node->scheduling_state);
        $this->assertTrue($node->visible_in_reservations);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'compute_node.registered',
            'target_id' => $node->id,
        ]);
    }

    public function test_admin_cannot_register_public_or_out_of_range_ip(): void
    {
        Livewire::test(CreateComputeNode::class)
            ->fillForm([
                'display_name' => 'Invalid server',
                'host_ip' => 'not-an-ip',
                'visible_in_reservations' => true,
                'scheduling_state' => ComputeNodeSchedulingState::Maintenance->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['host_ip']);

        $this->assertDatabaseMissing('compute_nodes', ['display_name' => 'Invalid server']);
    }
}
