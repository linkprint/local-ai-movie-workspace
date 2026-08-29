<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CompanyCodexLease;
use App\Models\User;
use App\Models\WorkspaceRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CompanyCodexLeaseService
{
    public function __construct(private readonly WorkspaceManagerClient $manager) {}

    public function stateFor(User $user, ?WorkspaceRuntime $runtime = null): array
    {
        if (! config('movie.company_codex_enabled')) {
            return ['state' => 'unavailable', 'owned_by_me' => false];
        }
        $lease = CompanyCodexLease::query()->find(CompanyCodexLease::SINGLETON_ID);
        if (! $lease || $lease->status === 'available') {
            return ['state' => 'available', 'owned_by_me' => false];
        }
        $owned = $runtime !== null
            && $lease->workspace_runtime_id === $runtime->id
            && $lease->user_id === $user->id;
        if ($lease->status === 'resource_locked') {
            return ['state' => 'unavailable', 'owned_by_me' => $owned];
        }

        return [
            'state' => $owned ? 'owned_by_me' : 'occupied',
            'owned_by_me' => $owned,
        ];
    }

    public function beginAcquire(WorkspaceRuntime $runtime): string
    {
        if (! config('movie.company_codex_enabled')) {
            throw new RuntimeException('company_codex_unavailable');
        }

        $fencingToken = DB::transaction(function () use ($runtime): string {
            $lease = CompanyCodexLease::query()
                ->lockForUpdate()
                ->findOrFail(CompanyCodexLease::SINGLETON_ID);
            $sameRuntime = $lease->workspace_runtime_id === $runtime->id
                && $lease->user_id === $runtime->user_id;
            if ($sameRuntime && in_array($lease->status, ['acquiring', 'active'], true)) {
                return (string) $lease->fencing_token;
            }
            if ($lease->status !== 'available') {
                AuditEvent::record('company_codex.lease_denied', $runtime, [
                    'reason' => $lease->status === 'resource_locked' ? 'resource_locked' : 'occupied',
                ]);
                throw new RuntimeException(
                    $lease->status === 'resource_locked'
                        ? 'company_codex_unavailable'
                        : 'company_codex_occupied'
                );
            }

            $token = (string) Str::uuid();
            $lease->forceFill([
                'workspace_runtime_id' => $runtime->id,
                'user_id' => $runtime->user_id,
                'status' => 'acquiring',
                'fencing_token' => $token,
                'acquired_at' => now(),
                'heartbeat_at' => now(),
                'released_at' => null,
            ])->save();
            AuditEvent::record('company_codex.lease_acquiring', $runtime);

            return $token;
        });

        try {
            $this->manager->assertCompanyVolumeAvailable($runtime);
        } catch (Throwable $exception) {
            $this->markResourceLocked($runtime, $fencingToken, 'manager_volume_scan_failed');
            throw $exception;
        }

        return $fencingToken;
    }

    public function activate(WorkspaceRuntime $runtime, string $fencingToken): void
    {
        DB::transaction(function () use ($runtime, $fencingToken): void {
            $lease = CompanyCodexLease::query()
                ->lockForUpdate()
                ->findOrFail(CompanyCodexLease::SINGLETON_ID);
            if ($lease->workspace_runtime_id !== $runtime->id
                || $lease->user_id !== $runtime->user_id
                || ! hash_equals((string) $lease->fencing_token, $fencingToken)
                || ! in_array($lease->status, ['acquiring', 'active'], true)) {
                throw new RuntimeException('company_codex_fencing_mismatch');
            }
            $lease->forceFill(['status' => 'active', 'heartbeat_at' => now()])->save();
            AuditEvent::record('company_codex.lease_acquired', $runtime);
        });
    }

    public function beginRelease(WorkspaceRuntime $runtime): ?string
    {
        return DB::transaction(function () use ($runtime): ?string {
            $lease = CompanyCodexLease::query()
                ->lockForUpdate()
                ->findOrFail(CompanyCodexLease::SINGLETON_ID);
            if ($lease->status === 'available') {
                return null;
            }
            if ($lease->workspace_runtime_id !== $runtime->id || $lease->user_id !== $runtime->user_id) {
                throw new RuntimeException('company_codex_occupied');
            }
            if ($lease->status === 'resource_locked') {
                throw new RuntimeException('company_codex_unavailable');
            }
            $lease->forceFill(['status' => 'releasing', 'heartbeat_at' => now()])->save();
            AuditEvent::record('company_codex.lease_releasing', $runtime);

            return (string) $lease->fencing_token;
        });
    }

    public function releaseAfterUnmount(WorkspaceRuntime $runtime, ?string $fencingToken): void
    {
        if ($fencingToken === null) {
            return;
        }
        $managerStatus = $this->manager->companyVolumeStatus();
        if (($managerStatus['unavailable'] ?? false) || (int) ($managerStatus['mount_count'] ?? -1) !== 0) {
            $this->markResourceLocked($runtime, $fencingToken, 'company_volume_still_mounted');
            throw new RuntimeException('company_codex_resource_locked');
        }

        DB::transaction(function () use ($runtime, $fencingToken): void {
            $lease = CompanyCodexLease::query()
                ->lockForUpdate()
                ->findOrFail(CompanyCodexLease::SINGLETON_ID);
            if ($lease->workspace_runtime_id !== $runtime->id
                || ! hash_equals((string) $lease->fencing_token, $fencingToken)
                || $lease->status !== 'releasing') {
                throw new RuntimeException('company_codex_fencing_mismatch');
            }
            $lease->forceFill([
                'workspace_runtime_id' => null,
                'user_id' => null,
                'status' => 'available',
                'fencing_token' => null,
                'heartbeat_at' => null,
                'released_at' => now(),
            ])->save();
            AuditEvent::record('company_codex.lease_released', $runtime);
        });
    }

    public function heartbeat(WorkspaceRuntime $runtime): void
    {
        CompanyCodexLease::query()
            ->whereKey(CompanyCodexLease::SINGLETON_ID)
            ->where('workspace_runtime_id', $runtime->id)
            ->where('status', 'active')
            ->update(['heartbeat_at' => now(), 'updated_at' => now()]);
    }

    public function markResourceLocked(
        WorkspaceRuntime $runtime,
        string $fencingToken,
        string $reason,
    ): void {
        DB::transaction(function () use ($runtime, $fencingToken, $reason): void {
            $lease = CompanyCodexLease::query()
                ->lockForUpdate()
                ->findOrFail(CompanyCodexLease::SINGLETON_ID);
            if ($lease->workspace_runtime_id !== $runtime->id
                || ! hash_equals((string) $lease->fencing_token, $fencingToken)) {
                return;
            }
            $lease->forceFill(['status' => 'resource_locked', 'heartbeat_at' => now()])->save();
            AuditEvent::record('company_codex.lease_resource_locked', $runtime, ['reason' => $reason]);
        });
    }
}
