<?php

namespace App\Services;

use App\Models\ComputeNode;
use App\Models\Reservation;
use App\Models\WorkspaceRuntime;
use Illuminate\Http\Client\Response;
use RuntimeException;

class MockBrokerControlClient extends SignedControlClient
{
    private const ACTIVE_JOB_STATUSES = [
        'queued', 'preparing', 'running', 'postprocessing', 'cancel_requested',
    ];

    public function __construct(private readonly ComputeNodeRegistry $nodes) {}

    protected function baseUrl(): string
    {
        return rtrim((string) config('movie.broker_control_url'), '/');
    }

    protected function secretFile(): string
    {
        return (string) config('movie.broker_secret_file');
    }

    public function register(Reservation $reservation, string $token, ?WorkspaceRuntime $runtime = null): void
    {
        $reservation->loadMissing('computeNode');
        if (! $reservation->computeNode) {
            throw new RuntimeException('compute_node_missing');
        }

        $response = $this->post('/internal/register', [
            'reservation_id' => $reservation->id,
            'compute_node_id' => $reservation->compute_node_id,
            'user_id' => $reservation->user_id,
            'expires_at' => $reservation->ends_at->timestamp,
            'token' => $token,
            'runtime_id' => $runtime?->id,
            'generation' => $runtime?->generation ?? 0,
            'node_url' => $this->nodes->brokerUrl($reservation->computeNode),
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'mock_broker_register_failed'), $response->status());
        }
    }

    public function health(ComputeNode $node): Response
    {
        return $this->post('/internal/node-health', [
            'compute_node_id' => $node->id,
            'node_url' => $this->nodes->brokerUrl($node),
        ]);
    }

    public function revoke(
        Reservation $reservation,
        ?WorkspaceRuntime $runtime = null,
        bool $requireIdle = false,
        bool $preserveFiles = false,
    ): void {
        $response = $this->post('/internal/revoke', [
            'reservation_id' => $reservation->id,
            'compute_node_id' => $reservation->compute_node_id,
            'runtime_id' => $reservation->workspace_runtime_id ?? $runtime?->id,
            'generation' => $reservation->ai_grant_generation ?? $runtime?->generation ?? 0,
            'require_idle' => $requireIdle,
            'preserve_files' => $preserveFiles,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'mock_broker_revoke_failed'), $response->status());
        }
    }

    public function forceRevoke(Reservation $reservation): void
    {
        $response = $this->post('/internal/revoke', [
            'reservation_id' => $reservation->id,
            'compute_node_id' => $reservation->compute_node_id,
            'runtime_id' => '',
            'generation' => 0,
            'require_idle' => false,
            'preserve_files' => false,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'mock_broker_force_revoke_failed'), $response->status());
        }
    }

    public function hasActiveJobs(Reservation $reservation): bool
    {
        $token = (string) $reservation->broker_token;
        if ($token === '') {
            throw new RuntimeException('broker_token_unavailable');
        }

        $response = $this->http()
            ->withToken($token)
            ->get($this->baseUrl().'/v1/jobs');
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'broker_jobs_unavailable'), $response->status());
        }
        $jobs = $response->json('jobs');
        if (! is_array($jobs)) {
            throw new RuntimeException('broker_jobs_invalid');
        }

        foreach ($jobs as $job) {
            if (is_array($job) && in_array($job['status'] ?? null, self::ACTIVE_JOB_STATUSES, true)) {
                return true;
            }
        }

        return false;
    }
}
