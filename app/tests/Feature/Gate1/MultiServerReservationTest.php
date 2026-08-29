<?php

namespace Tests\Feature\Gate1;

use App\Enums\ComputeNodeSchedulingState;
use App\Models\ComputeNode;
use App\Models\User;
use App\Services\ComputeNodeHealthService;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MultiServerReservationTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_only_registered_visible_nodes_are_returned_without_private_ips(): void
    {
        $user = User::factory()->create();
        ComputeNode::query()->create([
            'display_name' => '隐藏服务器',
            'slug' => 'hidden-server',
            'host_ip' => '192.168.88.88',
            'visible_in_reservations' => false,
            'scheduling_state' => ComputeNodeSchedulingState::Maintenance,
        ]);

        $response = $this->actingAs($user)->getJson(route('reservations.nodes'))
            ->assertOk()
            ->assertJsonCount(2, 'nodes')
            ->assertJsonPath('nodes.0.display_name', 'AI 制作服务器 01')
            ->assertJsonPath('nodes.1.display_name', 'AI 制作服务器 02')
            ->assertJsonPath('nodes.1.availability_state', 'abnormal')
            ->assertJsonPath('nodes.1.selectable', false);

        $body = $response->getContent();
        $this->assertStringNotContainsString('192.168.4.', $body);
        $this->assertStringNotContainsString('ai-server-', $body);
        $this->assertStringNotContainsString('隐藏服务器', $body);
    }

    public function test_create_page_shows_names_and_statuses_but_never_private_ips(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('reservations.create'))
            ->assertOk()
            ->assertSee('AI 制作服务器 01')
            ->assertSee('AI 制作服务器 02')
            ->assertSee('Abnormal');

        $this->assertStringNotContainsString('192.168.88.20', $response->getContent());
        $this->assertStringNotContainsString('192.168.88.200', $response->getContent());
    }

    public function test_abnormal_node_cannot_query_availability_or_create_reservation(): void
    {
        CarbonImmutable::setTestNow('2026-08-23T16:00:00Z');
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('reservations.availability', [
            'date' => '2026-08-24',
            'compute_node_id' => ComputeNode::SECONDARY_NODE_ID,
        ]))->assertUnprocessable()->assertJsonValidationErrors('compute_node_id');

        $this->actingAs($user)->post('/reservations', [
            'compute_node_id' => ComputeNode::SECONDARY_NODE_ID,
            'starts_at' => '2026-08-24T10:00:00-07:00',
            'ends_at' => '2026-08-24T12:00:00-07:00',
        ])->assertSessionHasErrors('compute_node_id');

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_different_users_can_book_same_time_on_different_nodes_but_one_user_cannot(): void
    {
        CarbonImmutable::setTestNow('2026-08-23T16:00:00Z');
        $local = ComputeNode::query()->findOrFail(ComputeNode::LOCAL_NODE_ID);
        $secondary = ComputeNode::query()->findOrFail(ComputeNode::SECONDARY_NODE_ID);
        $secondary->forceFill([
            'scheduling_state' => ComputeNodeSchedulingState::Online,
            'last_heartbeat_at' => now(),
            'last_health_summary' => ['ok' => true],
        ])->save();
        $starts = CarbonImmutable::parse('2026-08-24T10:00:00-07:00');
        $ends = CarbonImmutable::parse('2026-08-24T12:00:00-07:00');
        $first = User::factory()->create();
        $second = User::factory()->create();
        $service = app(ReservationService::class);

        $service->create($first, $starts, $ends, computeNode: $local);
        $service->create($second, $starts, $ends, computeNode: $secondary);
        $this->assertDatabaseCount('reservations', 2);

        $this->expectException(ValidationException::class);
        $service->create($first, $starts, $ends, computeNode: $secondary);
    }

    public function test_health_probe_requires_matching_node_identity(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'movie-node-health-secret-');
        file_put_contents($secret, str_repeat('s', 64));
        config(['movie.broker_secret_file' => $secret]);
        $node = ComputeNode::query()->findOrFail(ComputeNode::SECONDARY_NODE_ID);
        $node->update(['scheduling_state' => ComputeNodeSchedulingState::Online]);
        Http::fakeSequence()
            ->push([
                'ok' => true,
                'compute_node_id' => ComputeNode::SECONDARY_NODE_ID,
                'capabilities' => ['h3.generate', 'qwen.responses', 'image.generate'],
                'worker_revision' => 'worker-test',
                'workflow_revision' => 'workflow-test',
            ])
            ->push([
                'ok' => true,
                'compute_node_id' => ComputeNode::LOCAL_NODE_ID,
            ]);

        $this->assertTrue(app(ComputeNodeHealthService::class)->refresh($node));
        $node->refresh();
        $this->assertSame(['h3', 'qwen', 'z-image'], $node->capabilities);
        $this->assertSame('worker-test', $node->worker_revision);

        $this->assertFalse(app(ComputeNodeHealthService::class)->refresh($node));
        $this->assertSame('node_identity_mismatch', $node->refresh()->last_error_code);
        Http::assertSent(fn ($request): bool => $request->url() === 'http://movie-ai-router:8080/internal/node-health'
            && $request['node_url'] === 'http://192.168.88.200:8080');
        @unlink($secret);
    }
}
