<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\AuditEvent;
use App\Models\Reservation;
use App\Models\WorkspaceRuntime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LocalAiLeaseService
{
    public function __construct(
        private readonly WorkspaceManagerClient $manager,
        private readonly MockBrokerControlClient $broker,
        private readonly ComputeNodeStatusService $nodes,
    ) {}

    public function activate(Reservation $reservation): Reservation
    {
        $reservation->loadMissing('computeNode');
        if (! $reservation->computeNode) {
            throw new RuntimeException('compute_node_missing');
        }
        $this->nodes->assertAcceptsReservations($reservation->computeNode);

        [$locked, $runtime, $token] = DB::transaction(function () use ($reservation): array {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $now = CarbonImmutable::now();
            if ($locked->status === ReservationStatus::Active && $locked->broker_token) {
                $runtime = $locked->workspaceRuntime;

                return [$locked, $runtime, (string) $locked->broker_token];
            }
            if ($locked->status !== ReservationStatus::Confirmed) {
                throw new RuntimeException('reservation_cannot_activate');
            }
            if ($now->lessThan($locked->starts_at) || $now->greaterThanOrEqualTo($locked->ends_at)) {
                throw new RuntimeException('outside_reservation_window');
            }
            $runtime = WorkspaceRuntime::query()
                ->where('user_id', $locked->user_id)
                ->where('status', 'running')
                ->first();
            if (! $runtime) {
                return [$locked, null, null];
            }

            $token = Str::random(96);
            $locked->forceFill([
                'status' => ReservationStatus::Provisioning,
                'activated_at' => $locked->activated_at ?? $now,
                'broker_token' => $token,
                'workspace_runtime_id' => $runtime->id,
                'ai_grant_generation' => $runtime->generation,
                'ai_granted_at' => null,
                'ai_revoked_at' => null,
                'workspace_stopped_at' => null,
            ])->save();
            AuditEvent::record('local_ai.grant_provisioning', $locked, [
                'runtime_id' => $runtime->id,
                'generation' => $runtime->generation,
                'compute_node_id' => $locked->compute_node_id,
            ]);

            return [$locked->refresh(), $runtime, $token];
        });

        if (! $runtime || ! is_string($token)) {
            return $locked;
        }

        if ($locked->status === ReservationStatus::Active) {
            $this->protectRuntimeUntilReservationEnds($runtime, $locked);
            $this->broker->register($locked, $token, $runtime);
            $this->manager->grantLocalAi($runtime, $locked, $token);

            return $locked->refresh();
        }

        try {
            $status = $this->manager->runtimeStatus($runtime);
            if (! ($status['running'] ?? false)
                || ! ($status['healthy'] ?? false)
                || ($status['user_id'] ?? null) !== $runtime->user_id
                || (int) ($status['generation'] ?? 0) !== $runtime->generation) {
                throw new RuntimeException('workspace_runtime_unhealthy');
            }
            $this->protectRuntimeUntilReservationEnds($runtime, $locked);
            $this->broker->register($locked, $token, $runtime);
            $this->manager->grantLocalAi($runtime, $locked, $token);

            return DB::transaction(function () use ($locked, $runtime): Reservation {
                $current = Reservation::query()->lockForUpdate()->findOrFail($locked->id);
                if ($current->status !== ReservationStatus::Provisioning
                    || $current->workspace_runtime_id !== $runtime->id
                    || (int) $current->ai_grant_generation !== $runtime->generation) {
                    throw new RuntimeException('reservation_state_changed');
                }
                $current->forceFill([
                    'status' => ReservationStatus::Active,
                    'ai_granted_at' => now(),
                    'ai_revoked_at' => null,
                ])->save();
                AuditEvent::record('local_ai.grant_active', $current, [
                    'runtime_id' => $runtime->id,
                    'generation' => $runtime->generation,
                    'compute_node_id' => $current->compute_node_id,
                ]);

                return $current->refresh();
            });
        } catch (Throwable $exception) {
            [$brokerReleased, $runtimeReleased] = $this->forceRelease($locked, $runtime);
            $cleanupSucceeded = $brokerReleased && $runtimeReleased;
            DB::transaction(function () use ($locked, $cleanupSucceeded, $exception): void {
                $current = Reservation::query()->lockForUpdate()->find($locked->id);
                if (! $current || $current->status !== ReservationStatus::Provisioning) {
                    return;
                }
                $current->forceFill([
                    'status' => $cleanupSucceeded ? ReservationStatus::Confirmed : ReservationStatus::Failed,
                    'end_reason' => $cleanupSucceeded ? null : 'cleanup_failed',
                    'broker_token' => null,
                    'workspace_runtime_id' => null,
                    'ai_grant_generation' => null,
                    'ai_revoked_at' => $cleanupSucceeded ? now() : null,
                    'workspace_stopped_at' => $cleanupSucceeded ? now() : $current->workspace_stopped_at,
                ])->save();
                AuditEvent::record(
                    $cleanupSucceeded ? 'local_ai.grant_failed' : 'local_ai.grant_cleanup_failed',
                    $current,
                    ['failure_type' => $exception::class]
                );
            });
            throw $exception;
        }
    }

    public function revoke(Reservation $reservation, string $reason = 'expired'): Reservation
    {
        $locked = DB::transaction(function () use ($reservation, $reason): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if (in_array($locked->status, [ReservationStatus::Completed, ReservationStatus::Cancelled, ReservationStatus::Failed], true)) {
                return $locked;
            }
            if (! in_array($locked->status, [ReservationStatus::Provisioning, ReservationStatus::Active, ReservationStatus::Ending], true)) {
                throw new RuntimeException('reservation_cannot_revoke');
            }
            $locked->forceFill(['status' => ReservationStatus::Ending, 'end_reason' => $reason])->save();
            AuditEvent::record('local_ai.grant_revoking', $locked, ['reason' => $reason]);

            return $locked->refresh();
        });
        if (in_array($locked->status, [ReservationStatus::Completed, ReservationStatus::Cancelled, ReservationStatus::Failed], true)) {
            return $locked;
        }

        $runtime = $locked->workspaceRuntime;
        try {
            if ($reason === 'session_switch') {
                $this->broker->revoke($locked, $runtime, true, true);
            } else {
                try {
                    $this->broker->revoke($locked, $runtime);
                } catch (Throwable $exception) {
                    if (! in_array($reason, ['user_abandon', 'admin_cancel'], true)
                        || $exception->getMessage() !== 'runtime_binding_mismatch') {
                        throw $exception;
                    }
                    $this->broker->forceRevoke($locked);
                    AuditEvent::record('local_ai.grant_force_revoked', $locked, [
                        'reason' => $reason,
                        'cause' => 'runtime_binding_mismatch',
                    ]);
                }
            }
            if ($runtime) {
                $status = $this->manager->runtimeStatus($runtime);
                $grantGeneration = (int) ($locked->ai_grant_generation ?? 0);
                $runtimeGeneration = (int) ($status['generation'] ?? 0);
                if (($status['running'] ?? false)
                    && $runtimeGeneration === $grantGeneration) {
                    $this->manager->revokeLocalAi($runtime, $locked, $grantGeneration);
                } elseif (($status['running'] ?? false)
                    && ($status['ai_network_connected'] ?? false)) {
                    if (! in_array($reason, ['user_abandon', 'admin_cancel'], true) || $runtimeGeneration < 1) {
                        throw new RuntimeException('ai_grant_generation_mismatch');
                    }
                    $this->manager->revokeLocalAi($runtime, $locked, $runtimeGeneration);
                    AuditEvent::record('local_ai.runtime_force_revoked', $locked, [
                        'grant_generation' => $grantGeneration,
                        'runtime_generation' => $runtimeGeneration,
                        'reason' => $reason,
                    ]);
                }
            }
        } catch (Throwable $exception) {
            if ($reason === 'session_switch') {
                DB::transaction(function () use ($locked, $exception): void {
                    $current = Reservation::query()->lockForUpdate()->findOrFail($locked->id);
                    $current->forceFill([
                        'status' => ReservationStatus::Active,
                        'end_reason' => null,
                    ])->save();
                    $activeJobs = $exception->getMessage() === 'reservation_has_active_jobs';
                    AuditEvent::record($activeJobs
                        ? 'local_ai.session_switch_blocked'
                        : 'local_ai.session_switch_failed', $current, [
                            'reason' => $activeJobs ? 'active_jobs' : $exception->getMessage(),
                        ]);
                });

                if ($exception->getMessage() === 'reservation_has_active_jobs') {
                    throw new RuntimeException('local_ai_job_active', 0, $exception);
                }
                throw $exception;
            }

            [$brokerReleased, $runtimeReleased] = $this->forceRelease($locked, $runtime);
            $releaseCompleted = $brokerReleased && $runtimeReleased;
            $adminCancelled = $reason === 'admin_cancel' && $releaseCompleted;
            $released = DB::transaction(function () use (
                $locked,
                $reason,
                $exception,
                $releaseCompleted,
                $adminCancelled,
                $brokerReleased,
                $runtimeReleased,
            ): Reservation {
                $current = Reservation::query()->lockForUpdate()->findOrFail($locked->id);
                $current->forceFill([
                    'status' => match (true) {
                        $reason === 'user_abandon' => ReservationStatus::Completed,
                        $adminCancelled => ReservationStatus::Cancelled,
                        default => ReservationStatus::Failed,
                    },
                    'end_reason' => $reason === 'user_abandon' || $adminCancelled ? $reason : 'cleanup_failed',
                    'cancelled_at' => $adminCancelled ? now() : $current->cancelled_at,
                    'broker_token' => null,
                    'workspace_runtime_id' => null,
                    'ai_grant_generation' => null,
                    'ai_revoked_at' => now(),
                    'workspace_stopped_at' => now(),
                ])->save();
                AuditEvent::record(
                    $releaseCompleted ? 'local_ai.grant_force_released' : 'local_ai.grant_cleanup_failed',
                    $current,
                    [
                        'failure_type' => $exception::class,
                        'reason' => $reason,
                        'broker_released' => $brokerReleased,
                        'runtime_released' => $runtimeReleased,
                    ],
                );

                return $current->refresh();
            });

            if ($reason === 'user_abandon' || $adminCancelled) {
                return $released;
            }
            throw $exception;
        }

        return DB::transaction(function () use ($locked, $reason): Reservation {
            $current = Reservation::query()->lockForUpdate()->findOrFail($locked->id);
            $reservationRetained = in_array($reason, ['user_runtime_stop', 'session_switch'], true)
                && now()->lessThan($current->ends_at);
            $adminCancelled = $reason === 'admin_cancel';
            $current->forceFill([
                'status' => match (true) {
                    $reservationRetained => ReservationStatus::Confirmed,
                    $adminCancelled => ReservationStatus::Cancelled,
                    default => ReservationStatus::Completed,
                },
                'end_reason' => $reservationRetained ? null : $reason,
                'cancelled_at' => $adminCancelled ? now() : $current->cancelled_at,
                'broker_token' => null,
                'workspace_runtime_id' => null,
                'ai_grant_generation' => null,
                'ai_revoked_at' => now(),
                'workspace_stopped_at' => $reservationRetained ? now() : $current->workspace_stopped_at,
            ])->save();
            AuditEvent::record('local_ai.grant_revoked', $current, [
                'reservation_retained' => $reservationRetained,
                'reason' => $reason,
            ]);

            return $current->refresh();
        });
    }

    public function syncExpiry(Reservation $reservation): bool
    {
        $current = $reservation->fresh();
        $runtime = $current?->workspaceRuntime;
        if (! $current || $current->status !== ReservationStatus::Active || ! $current->broker_token || ! $runtime) {
            return true;
        }
        try {
            $this->protectRuntimeUntilReservationEnds($runtime, $current);
            $this->broker->register($current, (string) $current->broker_token, $runtime);
            $this->manager->grantLocalAi($runtime, $current, (string) $current->broker_token);

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function forceRelease(Reservation $reservation, ?WorkspaceRuntime $runtime): array
    {
        $brokerReleased = false;
        try {
            $this->broker->forceRevoke($reservation);
            $brokerReleased = true;
        } catch (Throwable) {
            // The reservation state still converges out of the shared booking lock.
        }

        $runtimeReleased = $runtime === null;
        if ($runtime) {
            try {
                $status = $this->manager->runtimeStatus($runtime);
                if (! ($status['running'] ?? false) || ! ($status['ai_network_connected'] ?? false)) {
                    $runtimeReleased = true;
                } else {
                    $generation = (int) ($status['generation'] ?? 0);
                    if ($generation < 1) {
                        throw new RuntimeException('invalid_runtime_generation');
                    }
                    $this->manager->revokeLocalAi($runtime, $reservation, $generation);
                    $runtimeReleased = true;
                }
            } catch (Throwable) {
                try {
                    $this->manager->stopRuntime($runtime);
                    $runtime->forceFill([
                        'status' => 'stopped',
                        'stopped_at' => now(),
                        'failure_reason' => null,
                    ])->save();
                    $runtimeReleased = true;
                } catch (Throwable) {
                    $runtimeReleased = false;
                }
            }
        }

        return [$brokerReleased, $runtimeReleased];
    }

    public function isEnabled(?Reservation $reservation, ?WorkspaceRuntime $runtime, array $managerStatus): bool
    {
        return $reservation?->status === ReservationStatus::Active
            && $reservation->broker_token !== null
            && $runtime !== null
            && $reservation->workspace_runtime_id === $runtime->id
            && (int) $reservation->ai_grant_generation === $runtime->generation
            && now()->greaterThanOrEqualTo($reservation->starts_at)
            && now()->lessThan($reservation->ends_at)
            && ($managerStatus['running'] ?? false)
            && ($managerStatus['healthy'] ?? false)
            && ($managerStatus['ai_network_connected'] ?? false);
    }

    public function reconcile(): array
    {
        $result = ['started' => 0, 'stopped' => 0, 'errors' => 0];
        $now = CarbonImmutable::now();

        Reservation::query()
            ->where('status', ReservationStatus::Confirmed)
            ->where('ends_at', '<=', $now)
            ->orderBy('ends_at')
            ->each(function (Reservation $reservation) use (&$result): void {
                DB::transaction(function () use ($reservation): void {
                    $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
                    if ($locked->status !== ReservationStatus::Confirmed || $locked->ends_at->isFuture()) {
                        return;
                    }
                    $locked->forceFill([
                        'status' => ReservationStatus::Completed,
                        'end_reason' => 'expired',
                        'broker_token' => null,
                        'workspace_runtime_id' => null,
                        'ai_grant_generation' => null,
                        'ai_revoked_at' => $locked->ai_revoked_at ?? now(),
                    ])->save();
                    AuditEvent::record('local_ai.reservation_expired', $locked);
                });
                $result['stopped']++;
            });

        Reservation::query()
            ->whereIn('status', [ReservationStatus::Provisioning, ReservationStatus::Active])
            ->where('ends_at', '<=', $now)
            ->orderBy('ends_at')
            ->each(function (Reservation $reservation) use (&$result): void {
                try {
                    $this->revoke($reservation, 'expired');
                    $result['stopped']++;
                } catch (Throwable $exception) {
                    report($exception);
                    $result['errors']++;
                }
            });

        Reservation::query()
            ->where('status', ReservationStatus::Active)
            ->where('ends_at', '>', $now)
            ->each(function (Reservation $reservation) use (&$result): void {
                if (! $this->syncExpiry($reservation)) {
                    $result['errors']++;
                }
            });

        Reservation::query()
            ->where('status', ReservationStatus::Confirmed)
            ->whereNull('workspace_stopped_at')
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->orderBy('starts_at')
            ->each(function (Reservation $reservation) use ($now, &$result): void {
                try {
                    $activated = $this->activate($reservation);
                    if ($activated->status === ReservationStatus::Active) {
                        $result['started']++;
                    } elseif ($now->greaterThanOrEqualTo($reservation->starts_at->addMinutes(config('movie.no_show_minutes')))) {
                        DB::transaction(function () use ($reservation): void {
                            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
                            if ($locked->status === ReservationStatus::Confirmed) {
                                $locked->forceFill(['status' => ReservationStatus::Completed, 'end_reason' => 'no_show'])->save();
                                AuditEvent::record('local_ai.no_show', $locked);
                            }
                        });
                        $result['stopped']++;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $result['errors']++;
                }
            });

        return $result;
    }

    private function protectRuntimeUntilReservationEnds(
        WorkspaceRuntime $runtime,
        Reservation $reservation,
    ): void {
        $protectedUntil = $reservation->ends_at->addMinutes(10);
        if ($runtime->idle_expires_at === null || $runtime->idle_expires_at->lessThan($protectedUntil)) {
            $runtime->forceFill(['idle_expires_at' => $protectedUntil])->save();
        }
        $this->manager->updateRuntimeDeadline($runtime->refresh());
    }
}
