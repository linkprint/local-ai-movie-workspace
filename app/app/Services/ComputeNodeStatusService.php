<?php

namespace App\Services;

use App\Enums\ComputeNodeAvailabilityState;
use App\Enums\ComputeNodeSchedulingState;
use App\Enums\ReservationStatus;
use App\Models\ComputeNode;
use App\Models\Reservation;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ComputeNodeStatusService
{
    /** @return list<string> */
    private function occupyingStatuses(): array
    {
        return array_map(
            fn (ReservationStatus $status): string => $status->value,
            array_filter(
                ReservationStatus::cases(),
                fn (ReservationStatus $status): bool => $status->occupiesLockWindow(),
            ),
        );
    }

    public function stateFor(ComputeNode $node): ComputeNodeAvailabilityState
    {
        if (! $this->acceptsReservations($node)) {
            return ComputeNodeAvailabilityState::Abnormal;
        }

        return $this->currentReservation($node) === null
            ? ComputeNodeAvailabilityState::Idle
            : ComputeNodeAvailabilityState::Busy;
    }

    public function acceptsReservations(ComputeNode $node): bool
    {
        if ($node->scheduling_state !== ComputeNodeSchedulingState::Online) {
            return false;
        }

        $heartbeat = $node->last_heartbeat_at;
        if ($heartbeat === null
            || $heartbeat->lt(now()->subSeconds((int) config('movie.node_heartbeat_stale_seconds')))) {
            return false;
        }

        $health = $node->last_health_summary;
        if (! is_array($health) || ($health['ok'] ?? false) !== true) {
            return false;
        }

        $requiredWorker = trim((string) config('movie.required_worker_revision'));
        if ($requiredWorker !== '' && ! hash_equals($requiredWorker, (string) $node->worker_revision)) {
            return false;
        }

        $requiredWorkflow = trim((string) config('movie.required_workflow_revision'));
        if ($requiredWorkflow !== '' && ! hash_equals($requiredWorkflow, (string) $node->workflow_revision)) {
            return false;
        }

        return true;
    }

    public function assertAcceptsReservations(ComputeNode $node, string $field = 'compute_node_id'): void
    {
        if (! $node->visible_in_reservations || ! $this->acceptsReservations($node)) {
            throw ValidationException::withMessages([
                $field => __('ui.compute_nodes.errors.unavailable'),
            ]);
        }
    }

    public function currentReservation(ComputeNode $node): ?Reservation
    {
        return $node->reservations()
            ->whereIn('status', $this->occupyingStatuses())
            ->where('lock_starts_at', '<=', now())
            ->where('lock_ends_at', '>', now())
            ->orderBy('lock_ends_at')
            ->first();
    }

    /** @return array<string, mixed> */
    public function publicNode(ComputeNode $node): array
    {
        $state = $this->stateFor($node);
        $current = $state === ComputeNodeAvailabilityState::Busy
            ? $this->currentReservation($node)
            : null;

        return [
            'id' => $node->id,
            'display_name' => $node->display_name,
            'availability_state' => $state->value,
            'state_label' => $state->getLabel(),
            'selectable' => $state->selectable(),
            'capability_labels' => $this->capabilityLabels((array) $node->capabilities),
            'busy_until' => $current?->lock_ends_at?->toIso8601String(),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function publicNodes(): Collection
    {
        $priority = ['idle' => 0, 'busy' => 1, 'abnormal' => 2];

        return ComputeNode::query()
            ->visibleInReservations()
            ->orderBy('sort_order')
            ->orderBy('display_name')
            ->get()
            ->map(fn (ComputeNode $node): array => $this->publicNode($node))
            ->sortBy(fn (array $node): array => [
                $priority[$node['availability_state']] ?? 99,
                $node['display_name'],
            ])
            ->values();
    }

    /** @param list<string> $capabilities
     * @return list<string>
     */
    private function capabilityLabels(array $capabilities): array
    {
        $labels = [
            'h3' => 'MiniMax H3',
            'qwen' => __('ui.compute_nodes.capabilities.local_ai'),
            'z-image' => __('ui.compute_nodes.capabilities.local_image'),
            'hunyuan' => __('ui.compute_nodes.capabilities.local_image'),
        ];

        return collect($capabilities)
            ->filter(fn (mixed $capability): bool => is_string($capability) && isset($labels[$capability]))
            ->map(fn (string $capability): string => $labels[$capability])
            ->unique()
            ->values()
            ->all();
    }
}
