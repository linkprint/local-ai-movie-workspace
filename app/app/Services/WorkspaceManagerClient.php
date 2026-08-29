<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\WorkspaceProfile;
use App\Models\WorkspaceProject;
use App\Models\WorkspaceRuntime;
use RuntimeException;

class WorkspaceManagerClient extends SignedControlClient
{
    protected function baseUrl(): string
    {
        return rtrim((string) config('movie.manager_url'), '/');
    }

    protected function secretFile(): string
    {
        return (string) config('movie.manager_secret_file');
    }

    public function ensureRuntime(
        WorkspaceRuntime $runtime,
        WorkspaceProfile $profile,
        WorkspaceProject $project,
    ): array {
        $response = $this->post('/v2/runtime/ensure', [
            'runtime_id' => $runtime->id,
            'user_id' => $runtime->user_id,
            'storage_uuid' => $profile->storage_uuid,
            'workspace_root' => $profile->root_directory,
            'project_id' => $project->id,
            'project_directory' => $project->directory_name,
            'generation' => $runtime->generation,
            'idle_deadline_epoch' => $runtime->idle_expires_at->timestamp,
            'auth_mode' => $runtime->auth_mode,
            'session_mode' => $runtime->session_mode,
            'session_id' => $runtime->session_id,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_manager_ensure_failed'), $response->status());
        }

        $result = $response->json();
        $deadline = microtime(true) + max(1, (int) config('movie.workspace_health_wait_seconds', 45));
        $pollMicroseconds = max(
            1000,
            (int) config('movie.workspace_health_poll_milliseconds', 500) * 1000,
        );
        while (! ($result['healthy'] ?? false) && microtime(true) < $deadline) {
            usleep($pollMicroseconds);
            $result = $this->runtimeStatus($runtime);
        }

        return $result;
    }

    public function runtimeStatus(WorkspaceRuntime|string $runtime): array
    {
        $runtimeId = $runtime instanceof WorkspaceRuntime ? $runtime->id : $runtime;
        $response = $this->get('/v2/runtime/status?runtime_id='.rawurlencode($runtimeId));
        if (! $response->successful()) {
            return ['running' => false, 'unavailable' => true];
        }

        return $response->json();
    }

    public function stopRuntime(WorkspaceRuntime $runtime): array
    {
        $response = $this->post('/v2/runtime/stop', [
            'runtime_id' => $runtime->id,
            'user_id' => $runtime->user_id,
            'generation' => $runtime->generation,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_manager_stop_failed'), $response->status());
        }

        return $response->json();
    }

    public function updateRuntimeDeadline(WorkspaceRuntime $runtime): void
    {
        $response = $this->post('/v2/runtime/deadline', [
            'runtime_id' => $runtime->id,
            'user_id' => $runtime->user_id,
            'generation' => $runtime->generation,
            'idle_deadline_epoch' => $runtime->idle_expires_at->timestamp,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_manager_deadline_failed'), $response->status());
        }
    }

    public function grantLocalAi(WorkspaceRuntime $runtime, Reservation $reservation, string $token): array
    {
        $response = $this->post('/v2/runtime/grant', [
            'runtime_id' => $runtime->id,
            'user_id' => $runtime->user_id,
            'generation' => $runtime->generation,
            'reservation_id' => $reservation->id,
            'compute_node_id' => $reservation->compute_node_id,
            'expires_at' => $reservation->ends_at->timestamp,
            'token' => $token,
            'capabilities' => [
                'qwen.responses', 'image.generate', 'h3.generate', 'upload',
                'job.read', 'artifact.download', 'gpu.status',
            ],
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_manager_grant_failed'), $response->status());
        }

        return $response->json();
    }

    public function revokeLocalAi(
        WorkspaceRuntime $runtime,
        Reservation $reservation,
        ?int $generation = null,
    ): array {
        $response = $this->post('/v2/runtime/revoke', [
            'runtime_id' => $runtime->id,
            'user_id' => $runtime->user_id,
            'generation' => $generation ?? $runtime->generation,
            'reservation_id' => $reservation->id,
            'compute_node_id' => $reservation->compute_node_id,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_manager_revoke_failed'), $response->status());
        }

        return $response->json();
    }

    public function companyVolumeStatus(): array
    {
        $response = $this->get('/v2/company/status');
        if (! $response->successful()) {
            return ['available' => false, 'unavailable' => true, 'mount_count' => null, 'runtime_ids' => []];
        }

        return $response->json();
    }

    public function assertCompanyVolumeAvailable(WorkspaceRuntime $runtime): void
    {
        $response = $this->post('/v2/company/assert-available', ['runtime_id' => $runtime->id]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'company_codex_unavailable'), $response->status());
        }
    }

    public function runtimeSessions(WorkspaceRuntime $runtime, WorkspaceProject $project): array
    {
        $response = $this->post('/v2/runtime/sessions', [
            'runtime_id' => $runtime->id,
            'user_id' => $runtime->user_id,
            'generation' => $runtime->generation,
            'project_id' => $project->id,
            'auth_mode' => $runtime->auth_mode,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_sessions_failed'), $response->status());
        }

        return $response->json();
    }

    public function deleteRuntimeSession(
        WorkspaceRuntime $runtime,
        WorkspaceProject $project,
        string $sessionId,
    ): array {
        $response = $this->post('/v2/runtime/session/delete', [
            'runtime_id' => $runtime->id,
            'user_id' => $runtime->user_id,
            'generation' => $runtime->generation,
            'project_id' => $project->id,
            'auth_mode' => $runtime->auth_mode,
            'session_id' => $sessionId,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_session_delete_failed'), $response->status());
        }

        return $response->json();
    }

    public function uploadRuntimeImage(
        WorkspaceRuntime $runtime,
        WorkspaceProject $project,
        string $filename,
        string $mime,
        string $contents,
    ): array {
        $response = $this->post('/v2/runtime/project-media', [
            'runtime_id' => $runtime->id,
            'user_id' => $runtime->user_id,
            'generation' => $runtime->generation,
            'project_id' => $project->id,
            'filename' => $filename,
            'mime' => $mime,
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'content_base64' => base64_encode($contents),
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_media_upload_failed'), $response->status());
        }

        return $response->json();
    }

    public function start(
        Reservation $reservation,
        WorkspaceProfile $profile,
        WorkspaceProject $project,
        string $brokerToken,
        string $authMode = 'personal',
    ): array {
        $response = $this->post('/v1/start', [
            'reservation_id' => $reservation->id,
            'storage_uuid' => $profile->storage_uuid,
            'workspace_root' => $profile->root_directory,
            'project_id' => $project->id,
            'project_directory' => $project->directory_name,
            'deadline_epoch' => $reservation->ends_at->timestamp,
            'broker_token' => $brokerToken,
            'auth_mode' => $authMode,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_manager_start_failed'), $response->status());
        }

        return $response->json();
    }

    public function switchAuthMode(Reservation $reservation, string $authMode): array
    {
        $response = $this->post('/v1/auth-mode', [
            'reservation_id' => $reservation->id,
            'auth_mode' => $authMode,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_manager_auth_mode_failed'), $response->status());
        }

        return $response->json();
    }

    public function sessions(
        Reservation $reservation,
        WorkspaceProfile $profile,
        WorkspaceProject $project,
        string $authMode,
    ): array {
        $response = $this->post('/v1/sessions', [
            'reservation_id' => $reservation->id,
            'workspace_root' => $profile->root_directory,
            'project_id' => $project->id,
            'project_directory' => $project->directory_name,
            'auth_mode' => $authMode,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_sessions_failed'), $response->status());
        }

        return $response->json();
    }

    public function selectSession(
        Reservation $reservation,
        WorkspaceProfile $profile,
        WorkspaceProject $project,
        string $authMode,
        string $action,
        ?string $sessionId,
    ): array {
        $response = $this->post('/v1/session', [
            'reservation_id' => $reservation->id,
            'workspace_root' => $profile->root_directory,
            'project_id' => $project->id,
            'project_directory' => $project->directory_name,
            'auth_mode' => $authMode,
            'action' => $action,
            'session_id' => $sessionId,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_session_switch_failed'), $response->status());
        }

        return $response->json();
    }

    public function refreshProject(
        Reservation $reservation,
        WorkspaceProfile $profile,
        WorkspaceProject $project,
    ): array {
        $response = $this->post('/v1/refresh', [
            'reservation_id' => $reservation->id,
            'workspace_root' => $profile->root_directory,
            'project_id' => $project->id,
            'project_directory' => $project->directory_name,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_manager_refresh_failed'), $response->status());
        }

        return $response->json();
    }

    public function renameProjectDirectory(
        WorkspaceProfile $profile,
        string $oldDirectory,
        string $newDirectory,
    ): void {
        $response = $this->post('/v1/project-directory', [
            'storage_uuid' => $profile->storage_uuid,
            'workspace_root' => $profile->root_directory,
            'old_directory' => $oldDirectory,
            'new_directory' => $newDirectory,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_project_rename_failed'), $response->status());
        }
    }

    public function trashProjectDirectory(WorkspaceProfile $profile, WorkspaceProject $project): array
    {
        $response = $this->post('/v1/project-directory/trash', [
            'storage_uuid' => $profile->storage_uuid,
            'workspace_root' => $profile->root_directory,
            'project_id' => $project->id,
            'project_directory' => $project->directory_name,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_project_delete_failed'), $response->status());
        }

        return $response->json();
    }

    public function restoreProjectDirectory(WorkspaceProfile $profile, WorkspaceProject $project): void
    {
        $response = $this->post('/v1/project-directory/restore', [
            'storage_uuid' => $profile->storage_uuid,
            'workspace_root' => $profile->root_directory,
            'project_id' => $project->id,
            'project_directory' => $project->directory_name,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_project_restore_failed'), $response->status());
        }
    }

    public function uploadMedia(
        Reservation $reservation,
        WorkspaceProfile $profile,
        WorkspaceProject $project,
        string $filename,
        string $mime,
        string $contents,
    ): array {
        $response = $this->post('/v1/project-media', [
            'reservation_id' => $reservation->id,
            'storage_uuid' => $profile->storage_uuid,
            'workspace_root' => $profile->root_directory,
            'project_id' => $project->id,
            'project_directory' => $project->directory_name,
            'filename' => $filename,
            'mime' => $mime,
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'content_base64' => base64_encode($contents),
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_media_upload_failed'), $response->status());
        }

        return $response->json();
    }

    public function stop(Reservation $reservation): void
    {
        $response = $this->post('/v1/stop', ['reservation_id' => $reservation->id]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_manager_stop_failed'), $response->status());
        }
    }

    public function updateDeadline(Reservation $reservation): void
    {
        $response = $this->post('/v1/deadline', [
            'reservation_id' => $reservation->id,
            'deadline_epoch' => $reservation->ends_at->timestamp,
        ]);
        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error') ?: 'workspace_manager_deadline_failed'), $response->status());
        }
    }

    public function status(?Reservation $reservation = null): array
    {
        $path = '/v1/status';
        if ($reservation) {
            $path .= '?reservation_id='.rawurlencode($reservation->id);
        }
        $response = $this->get($path);
        if (! $response->successful()) {
            return ['running' => false, 'unavailable' => true];
        }

        return $response->json();
    }
}
