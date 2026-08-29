<?php

namespace App\Services;

use App\Enums\ComputeNodeSchedulingState;
use App\Models\ComputeNode;
use Throwable;

class ComputeNodeHealthService
{
    public function __construct(private readonly MockBrokerControlClient $broker) {}

    /** @return array{checked: int, healthy: int, failed: int} */
    public function refreshRegisteredNodes(): array
    {
        $result = ['checked' => 0, 'healthy' => 0, 'failed' => 0];

        ComputeNode::query()
            ->where('scheduling_state', '!=', ComputeNodeSchedulingState::Offline->value)
            ->orderBy('sort_order')
            ->each(function (ComputeNode $node) use (&$result): void {
                $result['checked']++;
                if ($this->refresh($node)) {
                    $result['healthy']++;
                } else {
                    $result['failed']++;
                }
            });

        return $result;
    }

    public function refresh(ComputeNode $node): bool
    {
        try {
            $response = $this->broker->health($node);

            if (! $response->successful() || $response->json('ok') !== true) {
                $this->recordFailure($node, 'health_check_failed');

                return false;
            }

            if (! is_string($response->json('compute_node_id'))
                || ! hash_equals($node->id, $response->json('compute_node_id'))) {
                $this->recordFailure($node, 'node_identity_mismatch');

                return false;
            }

            $reportedCapabilities = collect((array) $response->json('capabilities'))
                ->filter(fn (mixed $value): bool => is_string($value))
                ->take(32)
                ->values()
                ->all();
            $capabilities = collect($reportedCapabilities)
                ->map(fn (string $value): ?string => match ($value) {
                    'h3.generate' => 'h3',
                    'qwen.responses' => 'qwen',
                    'image.generate' => 'z-image',
                    default => null,
                })
                ->filter()
                ->unique()
                ->values()
                ->all();

            $node->forceFill([
                'last_heartbeat_at' => now(),
                'last_health_summary' => [
                    'ok' => true,
                    'mode' => is_string($response->json('mode')) ? $response->json('mode') : null,
                    'capabilities' => $reportedCapabilities,
                ],
                'capabilities' => $capabilities,
                'last_error_code' => null,
                'worker_revision' => is_string($response->json('worker_revision'))
                    ? $response->json('worker_revision')
                    : $node->worker_revision,
                'workflow_revision' => is_string($response->json('workflow_revision'))
                    ? $response->json('workflow_revision')
                    : $node->workflow_revision,
                'model_manifest_sha256' => is_string($response->json('model_manifest_sha256'))
                    ? $response->json('model_manifest_sha256')
                    : $node->model_manifest_sha256,
            ])->save();

            return true;
        } catch (Throwable) {
            $this->recordFailure($node, 'worker_unreachable');

            return false;
        }
    }

    private function recordFailure(ComputeNode $node, string $code): void
    {
        $node->forceFill([
            'last_health_summary' => ['ok' => false, 'error' => $code],
            'last_error_code' => $code,
        ])->save();
    }
}
