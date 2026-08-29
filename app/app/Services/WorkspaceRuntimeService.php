<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\AuditEvent;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WorkspaceProfile;
use App\Models\WorkspaceProject;
use App\Models\WorkspaceRuntime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class WorkspaceRuntimeService
{
    public const AUTH_MODES = ['personal', 'company'];

    public const SESSION_ACTIONS = ['new', 'resume'];

    private const RUNTIME_MUTATION_LOCK_PREFIX = 'workspace-runtime-mutation:';

    public function __construct(
        private readonly WorkspaceManagerClient $manager,
        private readonly MockBrokerControlClient $broker,
        private readonly WorkspaceProjectService $projects,
        private readonly CompanyCodexLeaseService $companyCodex,
        private readonly LocalAiLeaseService $localAi,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('movie.workspace_enabled');
    }

    public function currentFor(User $user): ?Reservation
    {
        return $user->reservations()
            ->whereIn('status', [
                ReservationStatus::Confirmed,
                ReservationStatus::Provisioning,
                ReservationStatus::Active,
                ReservationStatus::Ending,
            ])
            ->where('lock_starts_at', '<=', now())
            ->where('lock_ends_at', '>', now())
            ->orderBy('starts_at')
            ->first();
    }

    public function nextFor(User $user): ?Reservation
    {
        return $user->reservations()
            ->where('status', ReservationStatus::Confirmed)
            ->where('lock_starts_at', '>', now())
            ->orderBy('starts_at')
            ->first();
    }

    public function runtimeFor(User $user): ?WorkspaceRuntime
    {
        return WorkspaceRuntime::query()->where('user_id', $user->id)->first();
    }

    public function heartbeatRuntime(User $user): ?WorkspaceRuntime
    {
        $runtime = $this->runtimeFor($user);
        if (! $runtime || $runtime->status !== 'running') {
            return $runtime;
        }
        $now = CarbonImmutable::now();
        if ($runtime->last_seen_at && $runtime->last_seen_at->greaterThan($now->subMinute())) {
            return $runtime;
        }
        $deadline = $now->addMinutes((int) config('movie.workspace_idle_minutes'));
        if ($runtime->idle_expires_at && $runtime->idle_expires_at->greaterThan($deadline)) {
            $deadline = $runtime->idle_expires_at;
        }
        $updated = WorkspaceRuntime::query()
            ->whereKey($runtime->id)
            ->where('status', 'running')
            ->where(function ($query) use ($now): void {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<=', $now->subMinute());
            })
            ->update([
                'last_seen_at' => $now,
                'idle_expires_at' => $deadline,
                'updated_at' => $now,
            ]);
        $runtime = $runtime->fresh();
        if ($updated === 1 && $runtime) {
            $this->manager->updateRuntimeDeadline($runtime);
        }

        return $runtime;
    }

    public function managerStatus(Reservation|WorkspaceRuntime|null $subject = null): array
    {
        if (! $this->enabled()) {
            return ['running' => false];
        }
        $runtime = $subject instanceof WorkspaceRuntime
            ? $subject
            : ($subject instanceof Reservation
                ? WorkspaceRuntime::query()->where('user_id', $subject->user_id)->first()
                : null);

        return $runtime ? $this->manager->runtimeStatus($runtime) : ['running' => false];
    }

    public function localAiEnabled(
        ?Reservation $reservation,
        ?WorkspaceRuntime $runtime,
        array $managerStatus,
    ): bool {
        return $this->localAi->isEnabled($reservation, $runtime, $managerStatus);
    }

    public function companyCodexState(User $user, ?WorkspaceRuntime $runtime = null): array
    {
        return $this->companyCodex->stateFor($user, $runtime);
    }

    public function syncExtendedDeadline(Reservation $reservation): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        return $this->localAi->syncExpiry($reservation);
    }

    public function start(Reservation $reservation, string $authMode = 'personal'): Reservation
    {
        if (! $this->enabled()) {
            throw ValidationException::withMessages(['workspace' => __('ui.errors.workspace_not_enabled')]);
        }
        $profile = $this->projects->profileFor($reservation->user);
        $project = $profile->selectedProject()->where('user_id', $reservation->user_id)->first();
        if (! $project) {
            throw ValidationException::withMessages(['workspace' => __('ui.errors.choose_project_start')]);
        }
        $this->selectAuthMode($reservation->user, $project, $authMode);

        $current = $reservation->fresh();
        if ($current?->status === ReservationStatus::Active && $current->broker_token) {
            return $current;
        }

        return $this->localAi->activate($reservation);
    }

    public function selectAuthMode(User $user, WorkspaceProject $project, string $authMode): array
    {
        $lockSeconds = max(60, (int) config('movie.workspace_mutation_lock_seconds', 180));
        $waitSeconds = max(1, (int) config('movie.workspace_mutation_lock_wait_seconds', 120));

        return Cache::lock(self::RUNTIME_MUTATION_LOCK_PREFIX.$user->id, $lockSeconds)
            ->block($waitSeconds, fn (): array => $this->selectAuthModeLocked($user, $project, $authMode));
    }

    private function selectAuthModeLocked(User $user, WorkspaceProject $project, string $authMode): array
    {
        $this->validateAuthMode($authMode);
        if ($project->user_id !== $user->id) {
            abort(404);
        }
        if (! $this->enabled()) {
            throw new RuntimeException('workspace_not_enabled');
        }
        $profile = $this->projects->profileFor($user);
        $previous = $this->runtimeFor($user);
        $managerStatus = $previous ? $this->manager->runtimeStatus($previous) : ['running' => false];
        if ($previous) {
            $previous = $this->reconcileRunningManagerRuntime($previous, $user, $profile, $managerStatus)
                ?? $previous;
        }
        $sameContext = $previous
            && $this->managerStatusMatchesContext($managerStatus, $previous, $profile, $project, $authMode);
        if ($sameContext) {
            $previous->forceFill([
                'last_seen_at' => now(),
                'idle_expires_at' => now()->addMinutes(config('movie.workspace_idle_minutes')),
            ])->save();
            $this->manager->updateRuntimeDeadline($previous->refresh());
            if ($authMode === 'company') {
                $this->companyCodex->heartbeat($previous);
            }
            AuditEvent::record('workspace.auth_mode_selected', $previous, [
                'auth_mode' => $authMode,
                'runtime_reused' => true,
            ]);

            return $managerStatus;
        }

        $activeAi = $user->reservations()
            ->where('status', ReservationStatus::Active)
            ->whereNotNull('broker_token')
            ->first();
        if ($activeAi && $previous && ($previous->workspace_project_id !== $project->id || $previous->auth_mode !== $authMode)) {
            throw new RuntimeException('local_ai_runtime_change_blocked');
        }

        $oldAuthMode = $previous?->auth_mode;
        $managerGeneration = (int) ($managerStatus['generation'] ?? 0);
        $runtime = DB::transaction(function () use ($user, $project, $authMode, $managerGeneration): WorkspaceRuntime {
            $runtime = WorkspaceRuntime::query()->lockForUpdate()->firstOrNew(['user_id' => $user->id]);
            $generation = $runtime->exists
                ? max(1, (int) $runtime->generation + 1, $managerGeneration + 1)
                : max(1, $managerGeneration + 1);
            $runtime->forceFill([
                'workspace_project_id' => $project->id,
                'status' => 'provisioning',
                'auth_mode' => $authMode,
                'session_mode' => 'new',
                'session_id' => null,
                'generation' => $generation,
                'last_seen_at' => now(),
                'idle_expires_at' => now()->addMinutes(config('movie.workspace_idle_minutes')),
                'stopped_at' => null,
                'failure_reason' => null,
            ])->save();
            AuditEvent::record('workspace.runtime_provisioning', $runtime, [
                'project_id' => $project->id,
                'auth_mode' => $authMode,
                'generation' => $generation,
            ]);

            return $runtime->refresh();
        });

        $companyFencingToken = null;
        $releaseFencingToken = null;
        $managerEnsureAttempted = false;
        try {
            if ($authMode === 'company') {
                $companyFencingToken = $this->companyCodex->beginAcquire($runtime);
            } elseif ($oldAuthMode === 'company'
                && $previous
                && ($this->companyCodex->stateFor($user, $previous)['owned_by_me'] ?? false)) {
                $releaseFencingToken = $this->companyCodex->beginRelease($runtime);
            }

            $managerEnsureAttempted = true;
            $status = $this->manager->ensureRuntime($runtime, $profile, $project);
            if (! ($status['running'] ?? false)
                || ($status['auth_mode'] ?? null) !== $authMode
                || ($status['user_id'] ?? null) !== $user->id
                || (int) ($status['generation'] ?? 0) !== $runtime->generation) {
                throw new RuntimeException('workspace_runtime_verification_failed');
            }
            $runtime->forceFill([
                'status' => 'running',
                'container_name' => $status['container_name'] ?? null,
                'network_name' => $status['network_name'] ?? null,
                'started_at' => now(),
                'failure_reason' => null,
            ])->save();
            if ($authMode === 'company') {
                $this->companyCodex->activate($runtime, (string) $companyFencingToken);
            } elseif ($releaseFencingToken !== null) {
                $this->companyCodex->releaseAfterUnmount($runtime, $releaseFencingToken);
            }
            AuditEvent::record('workspace.runtime_started', $runtime, [
                'container_id' => $status['container_id'] ?? null,
                'auth_mode' => $authMode,
                'generation' => $runtime->generation,
            ]);
            AuditEvent::record('workspace.auth_mode_selected', $runtime, [
                'auth_mode' => $authMode,
                'runtime_reused' => false,
            ]);

            $reservation = $this->currentFor($user);
            if ($reservation?->status === ReservationStatus::Confirmed
                && now()->greaterThanOrEqualTo($reservation->starts_at)
                && now()->lessThan($reservation->ends_at)) {
                $this->localAi->activate($reservation);
            }

            return $status;
        } catch (Throwable $exception) {
            $recoveredStatus = ['running' => false];
            $recoveredRuntime = null;
            if ($managerEnsureAttempted) {
                try {
                    $recoveredStatus = $this->manager->runtimeStatus($runtime);
                    $recoveredRuntime = $this->reconcileRunningManagerRuntime(
                        $runtime,
                        $user,
                        $profile,
                        $recoveredStatus,
                    );
                } catch (Throwable) {
                    // Preserve the original provisioning failure when Manager cannot be queried.
                }
            }
            if ($recoveredRuntime
                && $this->managerStatusMatchesContext(
                    $recoveredStatus,
                    $recoveredRuntime,
                    $profile,
                    $project,
                    $authMode,
                )) {
                if ($authMode === 'company' && $companyFencingToken !== null) {
                    $this->companyCodex->activate($recoveredRuntime, $companyFencingToken);
                } elseif ($releaseFencingToken !== null) {
                    $this->companyCodex->releaseAfterUnmount($recoveredRuntime, $releaseFencingToken);
                }
                AuditEvent::record('workspace.runtime_start_recovered', $recoveredRuntime, [
                    'failure_type' => $exception::class,
                    'auth_mode' => $authMode,
                    'generation' => $recoveredRuntime->generation,
                ]);
                AuditEvent::record('workspace.auth_mode_selected', $recoveredRuntime, [
                    'auth_mode' => $authMode,
                    'runtime_reused' => true,
                    'recovered_after_error' => true,
                ]);

                return $recoveredStatus;
            }
            if ($companyFencingToken !== null) {
                $this->companyCodex->markResourceLocked(
                    $runtime,
                    $companyFencingToken,
                    'runtime_start_failed',
                );
            }
            $resourceLocked = in_array($exception->getMessage(), [
                'company_codex_resource_locked', 'company_codex_fencing_mismatch',
                'runtime_identity_mismatch', 'runtime_image_mismatch',
            ], true);
            if (! $recoveredRuntime) {
                $runtime->forceFill([
                    'status' => $resourceLocked ? 'resource_locked' : 'stopped',
                    'failure_reason' => Str::limit($exception->getMessage(), 64, ''),
                    'stopped_at' => $resourceLocked ? null : now(),
                ])->save();
            }
            AuditEvent::record('workspace.runtime_start_failed', $runtime, [
                'failure_type' => $exception::class,
                'resource_locked' => $resourceLocked,
                'portal_reconciled' => $recoveredRuntime !== null,
            ]);
            throw $exception;
        }
    }

    public function sessions(User $user, WorkspaceProject $project, string $authMode): array
    {
        [$runtime] = $this->activeSessionContext($user, $project, $authMode);

        return $this->manager->runtimeSessions($runtime, $project);
    }

    public function selectSession(
        User $user,
        WorkspaceProject $project,
        string $authMode,
        string $action,
        ?string $sessionId,
    ): array {
        if (! in_array($action, self::SESSION_ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => __('ui.errors.valid_session_action')]);
        }
        [$runtime, $profile] = $this->activeSessionContext($user, $project, $authMode);
        $listing = $this->manager->runtimeSessions($runtime, $project);
        if ($action === 'resume') {
            $known = collect($listing['sessions'] ?? [])->pluck('id');
            if (! ($listing['available'] ?? false) || ! $known->contains($sessionId)) {
                throw new RuntimeException('session_not_found');
            }
        }
        $activeAi = $user->reservations()
            ->where('status', ReservationStatus::Active)
            ->whereNotNull('broker_token')
            ->first();
        if ($activeAi) {
            if ($activeAi->workspace_runtime_id !== $runtime->id
                || (int) $activeAi->ai_grant_generation !== (int) $runtime->generation) {
                throw new RuntimeException('local_ai_runtime_change_blocked');
            }
            if ($this->broker->hasActiveJobs($activeAi)) {
                throw new RuntimeException('local_ai_job_active');
            }
            $activeAi = $this->localAi->revoke($activeAi, 'session_switch');
        }
        $runtime->forceFill([
            'status' => 'provisioning',
            'session_mode' => $action,
            'session_id' => $action === 'resume' ? $sessionId : null,
            'generation' => $runtime->generation + 1,
            'last_seen_at' => now(),
            'idle_expires_at' => now()->addMinutes(config('movie.workspace_idle_minutes')),
        ])->save();
        $result = $this->manager->ensureRuntime($runtime, $profile, $project);
        if (! ($result['running'] ?? false)
            || ! ($result['healthy'] ?? false)
            || ($result['user_id'] ?? null) !== $user->id
            || (int) ($result['generation'] ?? 0) !== (int) $runtime->generation
            || ($result['session_mode'] ?? null) !== $action
            || ($action === 'resume' && ($result['session_id'] ?? null) !== $sessionId)) {
            throw new RuntimeException('workspace_session_switch_failed');
        }
        $runtime->forceFill([
            'status' => 'running',
            'container_name' => $result['container_name'] ?? null,
            'network_name' => $result['network_name'] ?? null,
            'started_at' => now(),
            'failure_reason' => null,
        ])->save();

        $localAiReissued = false;
        if ($activeAi && now()->lessThan($activeAi->ends_at)) {
            $reactivated = $this->localAi->activate($activeAi->refresh());
            $localAiReissued = $reactivated->status === ReservationStatus::Active
                && $reactivated->workspace_runtime_id === $runtime->id
                && (int) $reactivated->ai_grant_generation === (int) $runtime->generation
                && $reactivated->broker_token !== null;
            if (! $localAiReissued) {
                throw new RuntimeException('local_ai_regrant_failed');
            }
        }

        AuditEvent::record('workspace.session_selected', $runtime, [
            'codex_auth_mode' => $authMode,
            'project_id' => $project->id,
            'session_action' => $action,
            'session_id' => $sessionId,
            'local_ai_reissued' => $localAiReissued,
        ]);

        return $result;
    }

    public function deleteSession(
        User $user,
        WorkspaceProject $project,
        string $authMode,
        string $sessionId,
        bool $confirmed,
    ): array {
        if (! $confirmed) {
            throw ValidationException::withMessages([
                'confirmed' => __('ui.errors.confirm_session_delete'),
            ]);
        }
        [$runtime] = $this->activeSessionContext($user, $project, $authMode);
        $listing = $this->manager->runtimeSessions($runtime, $project);
        $known = collect($listing['sessions'] ?? [])->pluck('id');
        if (! ($listing['available'] ?? false) || ! $known->contains($sessionId)) {
            throw new RuntimeException('session_not_found');
        }
        if (($listing['current_session_id'] ?? null) === $sessionId) {
            throw new RuntimeException('session_active');
        }

        $result = $this->manager->deleteRuntimeSession($runtime, $project, $sessionId);
        if (! ($result['deleted'] ?? false) || ($result['session_id'] ?? null) !== $sessionId) {
            throw new RuntimeException('session_delete_failed');
        }
        AuditEvent::record('workspace.session_deleted', $runtime, [
            'codex_auth_mode' => $authMode,
            'project_id' => $project->id,
            'session_id' => $sessionId,
        ]);

        return $result;
    }

    private function activeSessionContext(
        User $user,
        WorkspaceProject $project,
        string $authMode,
    ): array {
        $this->validateAuthMode($authMode);
        if ($project->user_id !== $user->id) {
            abort(404);
        }
        $runtime = $this->runtimeFor($user);
        if (! $runtime || $runtime->status !== 'running') {
            throw new RuntimeException('workspace_not_running');
        }
        $profile = $this->projects->profileFor($user);
        $status = $this->manager->runtimeStatus($runtime);
        $matches = ($status['running'] ?? false)
            && ($status['workspace_root'] ?? null) === $profile->root_directory
            && ($status['project_id'] ?? null) === $project->id
            && ($status['project_directory'] ?? null) === $project->directory_name
            && ($status['auth_mode'] ?? 'personal') === $authMode;
        if (! $matches) {
            throw new RuntimeException('workspace_context_mismatch');
        }

        return [$runtime, $profile];
    }

    public function selectProject(User $user, WorkspaceProject $project): WorkspaceProfile
    {
        if ($project->user_id !== $user->id) {
            abort(404);
        }
        $profile = $this->projects->profileFor($user);
        $runtime = $this->runtimeFor($user);
        if ($runtime?->status === 'running'
            && $runtime->workspace_project_id !== $project->id
            && $user->reservations()->where('status', ReservationStatus::Active)->whereNotNull('broker_token')->exists()) {
            throw new RuntimeException('local_ai_runtime_change_blocked');
        }
        $previousProjectId = $profile->selected_project_id;
        $profile->forceFill(['selected_project_id' => $project->id])->save();
        if ($runtime?->status === 'running' && $runtime->workspace_project_id !== $project->id) {
            try {
                $this->selectAuthMode($user, $project, $runtime->auth_mode);
            } catch (Throwable $exception) {
                $profile->forceFill(['selected_project_id' => $previousProjectId])->save();
                throw $exception;
            }
        }
        AuditEvent::record('workspace.project_selected', $project, [
            'directory_name' => $project->directory_name,
            'runtime_active' => $runtime?->status === 'running',
        ]);

        return $profile->refresh();
    }

    public function updateProject(
        User $user,
        WorkspaceProject $project,
        string $name,
        string $directoryName,
    ): WorkspaceProject {
        if ($project->user_id !== $user->id) {
            abort(404);
        }
        $attributes = $this->projects->validatedAttributes($user, $name, $directoryName, $project);
        $oldDirectory = $project->directory_name;
        $directoryChanged = $oldDirectory !== $attributes['directory_name'];

        if ($directoryChanged && $this->runtimeFor($user)?->status === 'running') {
            throw ValidationException::withMessages([
                'directory_name' => __('ui.errors.stop_before_directory'),
            ]);
        }

        $profile = $this->projects->profileFor($user);
        $renamed = false;
        try {
            $updated = DB::transaction(function () use (
                $user,
                $project,
                $attributes,
                $profile,
                $oldDirectory,
                $directoryChanged,
                &$renamed,
            ): WorkspaceProject {
                $locked = WorkspaceProject::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->findOrFail($project->id);
                $attributes = $this->projects->validatedAttributes(
                    $user,
                    $attributes['name'],
                    $attributes['directory_name'],
                    $locked,
                );
                if ($directoryChanged) {
                    $this->manager->renameProjectDirectory(
                        $profile,
                        $oldDirectory,
                        $attributes['directory_name'],
                    );
                    $renamed = true;
                }
                $locked->forceFill($attributes)->save();

                return $locked->refresh();
            });
        } catch (Throwable $exception) {
            if ($renamed) {
                try {
                    $this->manager->renameProjectDirectory($profile, $attributes['directory_name'], $oldDirectory);
                } catch (Throwable $rollbackException) {
                    throw new RuntimeException('project_directory_rollback_failed', 0, $rollbackException);
                }
            }
            throw $exception;
        }

        AuditEvent::record('workspace.project_updated', $updated, [
            'old_directory_name' => $oldDirectory,
            'directory_name' => $updated->directory_name,
        ]);

        return $updated;
    }

    public function deleteProject(User $user, WorkspaceProject $project): void
    {
        if ($project->user_id !== $user->id) {
            abort(404);
        }
        $runtime = $this->runtimeFor($user);
        if ($runtime && in_array($runtime->status, WorkspaceRuntime::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'delete_confirmation' => __('ui.errors.stop_before_project_delete'),
            ]);
        }

        $profile = $this->projects->profileFor($user);
        $trashed = false;
        try {
            $this->manager->trashProjectDirectory($profile, $project);
            $trashed = true;
            DB::transaction(function () use ($user, $project, $profile, $runtime): void {
                $locked = WorkspaceProject::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->findOrFail($project->id);
                $lockedProfile = WorkspaceProfile::query()->lockForUpdate()->findOrFail($profile->id);
                if ($lockedProfile->selected_project_id === $locked->id) {
                    $lockedProfile->forceFill(['selected_project_id' => null])->save();
                }
                AuditEvent::record('workspace.project_deleted', $locked, [
                    'directory_name' => $locked->directory_name,
                    'directory_disposition' => 'private_trash',
                ]);
                if ($runtime && $runtime->workspace_project_id === $locked->id) {
                    WorkspaceRuntime::query()
                        ->whereKey($runtime->id)
                        ->where('status', 'stopped')
                        ->delete();
                }
                $locked->delete();
            });
        } catch (Throwable $exception) {
            if ($trashed) {
                try {
                    $this->manager->restoreProjectDirectory($profile, $project);
                } catch (Throwable $rollbackException) {
                    throw new RuntimeException('project_delete_rollback_failed', 0, $rollbackException);
                }
            }
            throw $exception;
        }
    }

    public function authorizeTerminal(User $user, WorkspaceProject $project, ?string $authMode = null): WorkspaceRuntime
    {
        $profile = WorkspaceProfile::query()->where('user_id', $user->id)->first();
        if (! $profile || $profile->selected_project_id !== $project->id || $project->user_id !== $user->id) {
            abort(403);
        }
        $runtime = $this->runtimeFor($user);
        if (! $runtime || $runtime->status !== 'running' || $runtime->workspace_project_id !== $project->id) {
            abort(403);
        }
        $status = $this->manager->runtimeStatus($runtime);
        if (! ($status['running'] ?? false)
            || ! ($status['healthy'] ?? false)
            || ($status['user_id'] ?? null) !== $user->id
            || ($status['project_id'] ?? null) !== $project->id
            || (int) ($status['generation'] ?? 0) !== $runtime->generation) {
            abort(503);
        }
        if ($authMode !== null) {
            $this->validateAuthMode($authMode);
            if (($status['auth_mode'] ?? 'personal') !== $authMode) {
                abort(403);
            }
        }

        $runtime = $this->heartbeatRuntime($user) ?? $runtime;
        $reservation = $user->reservations()
            ->where('status', ReservationStatus::Active)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->first();
        if ($reservation && $reservation->first_connected_at === null) {
            $reservation->forceFill(['first_connected_at' => now()])->save();
            AuditEvent::record('workspace.first_connected', $reservation);
        }
        AuditEvent::record('workspace.route_authorized', $runtime, [
            'generation' => $runtime->generation,
        ]);

        return $runtime->refresh();
    }

    /** @return array{path: string, relative_path: string, library_relative_path: string, filename: string, mime: string, size: int, sha256: string} */
    public function uploadMedia(
        User $user,
        WorkspaceProject $project,
        string $filename,
        string $mime,
        string $contents,
        string $mediaType,
        ?string $authMode = null,
    ): array {
        if (! in_array($mediaType, ['image', 'video'], true)) {
            throw new RuntimeException('workspace_media_type_invalid');
        }
        $runtime = $this->authorizeTerminal($user, $project, $authMode);
        $profile = $this->projects->profileFor($user);
        $relativePath = 'uploads/'.$filename;
        $libraryPath = $this->writeLibraryUpload($profile, $project, $filename, $contents);

        try {
            $stored = $this->manager->uploadRuntimeImage(
                $runtime,
                $project,
                $filename,
                $mime,
                $contents,
            );
        } catch (Throwable $exception) {
            @unlink($libraryPath);
            throw $exception;
        }

        $expectedPath = '/workspace/'.$project->directory_name.'/'.$relativePath;
        if (($stored['path'] ?? null) !== $expectedPath
            || ($stored['relative_path'] ?? null) !== $relativePath
            || ($stored['sha256'] ?? null) !== hash('sha256', $contents)) {
            @unlink($libraryPath);
            throw new RuntimeException('workspace_media_upload_invalid_response');
        }

        AuditEvent::record('workspace.'.$mediaType.'_uploaded', $project, [
            'runtime_id' => $runtime->id,
            'relative_path' => $relativePath,
            'library_relative_path' => $relativePath,
            'mime' => $mime,
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ]);

        return [
            'path' => $expectedPath,
            'relative_path' => $relativePath,
            'library_relative_path' => $relativePath,
            'filename' => $filename,
            'mime' => $mime,
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }

    private function writeLibraryUpload(
        WorkspaceProfile $profile,
        WorkspaceProject $project,
        string $filename,
        string $contents,
    ): string {
        $storage = strtolower((string) $profile->storage_uuid);
        $projectId = strtolower((string) $project->id);
        if (! Str::isUuid($storage) || ! Str::isUuid($projectId)
            || preg_match('/\A[0-9a-f-]{36}\.(?:gif|jpe?g|png|webp|mp4|webm|mov|m4v)\z/', $filename) !== 1) {
            throw new RuntimeException('workspace_library_upload_scope_invalid');
        }

        $root = rtrim((string) config('movie.video_root'), DIRECTORY_SEPARATOR);
        if ($root === '' || str_contains($root, "\0")) {
            throw new RuntimeException('workspace_library_root_invalid');
        }
        $directory = $root.DIRECTORY_SEPARATOR.$storage.DIRECTORY_SEPARATOR.$projectId.DIRECTORY_SEPARATOR.'uploads';
        if (! is_dir($directory) && ! mkdir($directory, 0770, true) && ! is_dir($directory)) {
            throw new RuntimeException('workspace_library_upload_failed');
        }
        foreach ([$root, $root.DIRECTORY_SEPARATOR.$storage, $root.DIRECTORY_SEPARATOR.$storage.DIRECTORY_SEPARATOR.$projectId, $directory] as $segment) {
            if (is_link($segment) || ! is_dir($segment)) {
                throw new RuntimeException('workspace_library_upload_scope_invalid');
            }
        }

        $destination = $directory.DIRECTORY_SEPARATOR.$filename;
        $temporary = $directory.DIRECTORY_SEPARATOR.'.'.$filename.'.'.Str::uuid().'.partial';
        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
                || ! chmod($temporary, 0660)
                || ! rename($temporary, $destination)) {
                throw new RuntimeException('workspace_library_upload_failed');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $destination;
    }

    public function stop(Reservation $reservation, string $reason = 'expired'): Reservation
    {
        return $this->localAi->revoke($reservation, $reason);
    }

    public function stopRuntime(User $user): WorkspaceRuntime
    {
        $runtime = WorkspaceRuntime::query()->where('user_id', $user->id)->first();
        if (! $runtime || $runtime->status !== 'running') {
            throw ValidationException::withMessages(['workspace' => __('ui.errors.no_active_stop')]);
        }
        $reservation = $user->reservations()
            ->whereIn('status', [ReservationStatus::Provisioning, ReservationStatus::Active, ReservationStatus::Ending])
            ->whereNotNull('broker_token')
            ->first();
        if ($reservation) {
            $this->localAi->revoke($reservation, 'user_runtime_stop');
        }
        $companyFencingToken = $runtime->auth_mode === 'company'
            ? $this->companyCodex->beginRelease($runtime)
            : null;
        $runtime->forceFill(['status' => 'stopping'])->save();
        try {
            $this->manager->stopRuntime($runtime);
            if ($companyFencingToken !== null) {
                $this->companyCodex->releaseAfterUnmount($runtime, $companyFencingToken);
            }
            $runtime->forceFill([
                'status' => 'stopped',
                'stopped_at' => now(),
                'failure_reason' => null,
            ])->save();
            AuditEvent::record('workspace.runtime_stopped', $runtime, ['requested_by_user' => true]);

            return $runtime->refresh();
        } catch (Throwable $exception) {
            $runtime->forceFill(['status' => 'resource_locked', 'failure_reason' => 'cleanup_failed'])->save();
            AuditEvent::record('workspace.runtime_resource_locked', $runtime, [
                'failure_type' => $exception::class,
            ]);
            throw $exception;
        }
    }

    public function abandon(Reservation $reservation, User $user): Reservation
    {
        if ($reservation->user_id !== $user->id) {
            abort(404);
        }

        $current = DB::transaction(function () use ($reservation): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if (! in_array($locked->status, [
                ReservationStatus::Confirmed,
                ReservationStatus::Provisioning,
                ReservationStatus::Active,
            ], true) || $locked->lock_starts_at->isFuture() || ! $locked->lock_ends_at->isFuture()) {
                throw ValidationException::withMessages([
                    'workspace' => __('ui.errors.not_current_reservation'),
                ]);
            }

            return $locked;
        });

        if (in_array($current->status, [ReservationStatus::Provisioning, ReservationStatus::Active], true)) {
            $current = $this->stop($current, 'user_abandon');
        }

        return DB::transaction(function () use ($current): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($current->id);
            if (! in_array($locked->status, [ReservationStatus::Confirmed, ReservationStatus::Completed], true)) {
                throw new RuntimeException('reservation_state_changed');
            }

            $locked->update([
                'status' => ReservationStatus::Cancelled,
                'cancelled_at' => CarbonImmutable::now(),
                'end_reason' => 'user_abandon',
                'broker_token' => null,
                'workspace_stopped_at' => $locked->workspace_stopped_at ?? CarbonImmutable::now(),
            ]);
            AuditEvent::record('reservation.abandoned', $locked, [
                'local_ai_revoked' => $current->status === ReservationStatus::Completed,
                'runtime_retained' => true,
                'files_retained' => true,
            ]);

            return $locked->refresh();
        });
    }

    public function reconcile(): array
    {
        if (! $this->enabled()) {
            return ['started' => 0, 'stopped' => 0, 'errors' => 0];
        }
        $result = $this->localAi->reconcile();
        $now = CarbonImmutable::now();
        WorkspaceRuntime::query()
            ->where('status', 'running')
            ->orderBy('started_at')
            ->each(function (WorkspaceRuntime $runtime) use ($now, &$result): void {
                try {
                    $status = $this->manager->runtimeStatus($runtime);
                    if (! ($status['running'] ?? false)) {
                        $runtime->forceFill(['status' => 'stopped', 'stopped_at' => $now])->save();
                        if ($runtime->auth_mode === 'company') {
                            $token = $this->companyCodex->beginRelease($runtime);
                            $this->companyCodex->releaseAfterUnmount($runtime, $token);
                        }
                        $result['stopped']++;

                        return;
                    }
                    if ($runtime->auth_mode === 'company') {
                        $this->companyCodex->heartbeat($runtime);
                    }
                    $hasProtectedReservation = Reservation::query()
                        ->where('user_id', $runtime->user_id)
                        ->whereIn('status', [
                            ReservationStatus::Confirmed,
                            ReservationStatus::Provisioning,
                            ReservationStatus::Active,
                            ReservationStatus::Ending,
                        ])
                        ->where('lock_starts_at', '<=', $now->addMinutes(15))
                        ->where('lock_ends_at', '>', $now)
                        ->exists();
                    if (! $hasProtectedReservation
                        && $runtime->idle_expires_at !== null
                        && $now->greaterThanOrEqualTo($runtime->idle_expires_at)) {
                        $this->stopRuntime($runtime->user);
                        $result['stopped']++;
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $result['errors']++;
                }
            });

        return $result;
    }

    private function validateAuthMode(string $authMode): void
    {
        if (! in_array($authMode, self::AUTH_MODES, true)) {
            throw ValidationException::withMessages(['auth_mode' => __('ui.errors.valid_codex_account')]);
        }
        if ($authMode === 'company' && ! config('movie.company_codex_enabled')) {
            throw ValidationException::withMessages(['auth_mode' => __('ui.errors.company_account_unavailable')]);
        }
    }

    private function managerStatusMatchesContext(
        array $status,
        WorkspaceRuntime $runtime,
        WorkspaceProfile $profile,
        WorkspaceProject $project,
        string $authMode,
    ): bool {
        return ($status['running'] ?? false)
            && ($status['healthy'] ?? false)
            && ($status['runtime_id'] ?? $runtime->id) === $runtime->id
            && ($status['user_id'] ?? null) === $runtime->user_id
            && ($status['workspace_root'] ?? null) === $profile->root_directory
            && ($status['project_id'] ?? null) === $project->id
            && ($status['project_directory'] ?? null) === $project->directory_name
            && ($status['auth_mode'] ?? null) === $authMode
            && (int) ($status['generation'] ?? 0) === $runtime->generation;
    }

    private function reconcileRunningManagerRuntime(
        WorkspaceRuntime $runtime,
        User $user,
        WorkspaceProfile $profile,
        array $status,
    ): ?WorkspaceRuntime {
        $generation = (int) ($status['generation'] ?? 0);
        $projectId = $status['project_id'] ?? null;
        $authMode = $status['auth_mode'] ?? null;
        $sessionMode = $status['session_mode'] ?? 'new';
        $sessionId = $status['session_id'] ?? null;
        if (! ($status['running'] ?? false)
            || ! ($status['healthy'] ?? false)
            || ($status['runtime_id'] ?? $runtime->id) !== $runtime->id
            || ($status['user_id'] ?? null) !== $user->id
            || ($status['workspace_root'] ?? null) !== $profile->root_directory
            || $generation < 1
            || ! is_string($projectId)
            || ! in_array($authMode, self::AUTH_MODES, true)
            || ! in_array($sessionMode, self::SESSION_ACTIONS, true)
            || ($sessionMode === 'resume' && (! is_string($sessionId) || ! Str::isUuid($sessionId)))
            || ($sessionMode === 'new' && $sessionId !== null)) {
            return null;
        }
        $project = WorkspaceProject::query()
            ->where('user_id', $user->id)
            ->whereKey($projectId)
            ->first();
        if (! $project || ($status['project_directory'] ?? null) !== $project->directory_name) {
            return null;
        }

        return DB::transaction(function () use (
            $runtime,
            $user,
            $project,
            $status,
            $authMode,
            $sessionMode,
            $sessionId,
            $generation,
        ): ?WorkspaceRuntime {
            $locked = WorkspaceRuntime::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if (! $locked || $locked->id !== $runtime->id) {
                return null;
            }
            $changed = $locked->status !== 'running'
                || $locked->workspace_project_id !== $project->id
                || $locked->auth_mode !== $authMode
                || $locked->session_mode !== $sessionMode
                || $locked->session_id !== $sessionId
                || $locked->generation !== $generation
                || $locked->failure_reason !== null
                || $locked->stopped_at !== null;
            $previousStatus = $locked->status;
            $previousGeneration = $locked->generation;
            $previousFailure = $locked->failure_reason;
            $locked->forceFill([
                'workspace_project_id' => $project->id,
                'status' => 'running',
                'auth_mode' => $authMode,
                'session_mode' => $sessionMode,
                'session_id' => $sessionMode === 'resume' ? $sessionId : null,
                'generation' => $generation,
                'container_name' => $status['container_name'] ?? $locked->container_name,
                'network_name' => $status['network_name'] ?? $locked->network_name,
                'started_at' => $locked->started_at ?? now(),
                'stopped_at' => null,
                'failure_reason' => null,
            ])->save();
            if ($changed) {
                AuditEvent::record('workspace.runtime_reconciled', $locked, [
                    'previous_status' => $previousStatus,
                    'previous_generation' => $previousGeneration,
                    'manager_generation' => $generation,
                    'previous_failure_reason' => $previousFailure,
                    'ai_network_connected' => (bool) ($status['ai_network_connected'] ?? false),
                ]);
            }

            return $locked->refresh();
        });
    }
}
