<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\WorkspaceProject;
use App\Models\WorkspaceRuntime;
use App\Services\ReservationAvailabilityService;
use App\Services\TerminalRouteClaimService;
use App\Services\WorkspaceProjectService;
use App\Services\WorkspaceRuntimeService;
use App\Services\WorkspaceStyleDemoLocator;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class WorkspaceController extends Controller
{
    private const AUTH_MODE_SESSION_KEY = 'workspace.codex_auth_mode';

    private const ENTRY_TOKEN_SESSION_KEY = 'workspace.entry_token';

    private const AUTH_MODE_ATTEMPT_SESSION_KEY = 'workspace.auth_mode_attempt';

    public function __construct(
        private readonly WorkspaceRuntimeService $workspaces,
        private readonly WorkspaceProjectService $projects,
        private readonly ReservationAvailabilityService $availability,
        private readonly TerminalRouteClaimService $routeClaims,
        private readonly WorkspaceStyleDemoLocator $styleDemos,
    ) {}

    public function index(Request $request): View
    {
        if (! $this->workspaces->enabled()) {
            return view('workspace-projects', [
                'enabled' => false,
                'projects' => collect(),
                'profile' => null,
                'activeWorkspace' => null,
                'activeReservation' => null,
            ]);
        }
        $profile = $this->projects->profileFor($request->user());
        $current = $this->workspaces->currentFor($request->user());
        $runtime = $this->workspaces->runtimeFor($request->user());

        return view('workspace-projects', [
            'enabled' => true,
            'projects' => $request->user()->workspaceProjects()->orderBy('name')->get(),
            'profile' => $profile,
            'activeWorkspace' => $runtime && in_array($runtime->status, WorkspaceRuntime::ACTIVE_STATUSES, true)
                ? $runtime
                : null,
            'activeReservation' => $current?->status === ReservationStatus::Active ? $current : null,
        ]);
    }

    public function terminal(Request $request): View|RedirectResponse
    {
        $project = $this->selectedProject($request);
        if (! $project) {
            return redirect()->route('workspace')->withErrors([
                'workspace' => __('ui.errors.choose_project_enter'),
            ]);
        }
        $entrySupplied = $request->query->has('entry');
        $authMode = $this->entryAuthMode($request, true);
        $authModeAttempt = $authMode === null ? $this->authModeAttempt($request) : null;
        if ($entrySupplied && $authMode === null) {
            return redirect()->route('workspace.terminal');
        }
        $now = now();
        $current = $this->workspaces->currentFor($request->user());
        $next = $this->workspaces->nextFor($request->user());
        $workspaceRuntime = $this->workspaces->heartbeatRuntime($request->user());
        $runtime = $workspaceRuntime
            ? $this->workspaces->managerStatus($workspaceRuntime)
            : ['running' => false];
        $localAiReservation = $current ?? $next;
        $localAiEnabled = $this->workspaces->localAiEnabled($current, $workspaceRuntime, $runtime);
        $companyCodex = $this->workspaces->companyCodexState($request->user(), $workspaceRuntime);

        return view('workspace', [
            'enabled' => $this->workspaces->enabled(),
            'current' => $current,
            'extensionOptions' => $current?->status === ReservationStatus::Active
                ? $this->availability->extensionOptions(
                    $current,
                    $request->user()->timezone ?: config('movie.display_timezone'),
                )
                : [],
            'next' => $next,
            'runtime' => $runtime,
            'workspaceRuntime' => $workspaceRuntime,
            'localAiReservation' => $localAiReservation,
            'localAiEnabled' => $localAiEnabled,
            'localAiPhase' => $this->localAiPhase($localAiReservation, $localAiEnabled, $now),
            'serverNow' => $now->utc()->toIso8601String(),
            'canEnterTerminal' => $workspaceRuntime?->status === 'running'
                && ($runtime['running'] ?? false)
                && ($runtime['healthy'] ?? false)
                && ($runtime['project_id'] ?? null) === $project->id
                && $authMode !== null
                && ($runtime['auth_mode'] ?? 'personal') === $authMode,
            'canStart' => false,
            'project' => $project,
            'profile' => $this->projects->profileFor($request->user()),
            'authMode' => $authMode,
            'authModeAttempt' => $authModeAttempt,
            'authModeRequired' => $authMode === null,
            'companyCodexEnabled' => (bool) config('movie.company_codex_enabled'),
            'companyCodex' => $companyCodex,
            'styles' => collect(config('movie.styles', []))->map(function (array $style): array {
                return [
                    'skill' => (string) $style['skill'],
                    'title' => __((string) $style['title_key']),
                    'description' => __((string) $style['description_key']),
                    'demo_url' => $this->styleDemos->pathFor($style) !== null
                        ? route('workspace.styles.demo', ['skill' => $style['skill']])
                        : null,
                ];
            })->values()->all(),
        ]);
    }

    public function runtimeStatus(Request $request): JsonResponse
    {
        if (! $this->selectedProject($request)) {
            abort(403);
        }

        $now = now();
        $current = $this->workspaces->currentFor($request->user());
        $next = $this->workspaces->nextFor($request->user());
        $workspaceRuntime = $this->workspaces->heartbeatRuntime($request->user());
        $runtime = $workspaceRuntime
            ? $this->workspaces->managerStatus($workspaceRuntime)
            : ['running' => false];
        $reservation = $current ?? $next;
        $localAiEnabled = $this->workspaces->localAiEnabled($current, $workspaceRuntime, $runtime);
        $companyCodex = $this->workspaces->companyCodexState($request->user(), $workspaceRuntime);

        return response()->json([
            'phase' => $this->localAiPhase($reservation, $localAiEnabled, $now),
            'local_ai_enabled' => $localAiEnabled,
            'starts_at' => $reservation?->starts_at->utc()->toIso8601String(),
            'server_now' => $now->utc()->toIso8601String(),
            'runtime' => [
                'phase' => $workspaceRuntime?->status ?? 'stopped',
                'healthy' => (bool) ($runtime['healthy'] ?? false),
                'running' => (bool) ($runtime['running'] ?? false),
            ],
            'company_codex' => $companyCodex,
            'local_ai' => [
                'phase' => $this->localAiPhase($reservation, $localAiEnabled, $now),
                'enabled' => $localAiEnabled,
                'starts_at' => $reservation?->starts_at->utc()->toIso8601String(),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function selectAuthMode(Request $request): RedirectResponse
    {
        $project = $this->selectedProject($request);
        if (! $project) {
            return redirect()->route('workspace')->withErrors([
                'workspace' => __('ui.errors.choose_project_account'),
            ]);
        }
        $validated = $request->validate([
            'auth_mode' => ['required', 'string', Rule::in(WorkspaceRuntimeService::AUTH_MODES)],
            'auth_attempt' => ['nullable', 'string', 'size:48'],
        ]);
        $authMode = $validated['auth_mode'];
        if ($authMode === 'company' && ! config('movie.company_codex_enabled')) {
            return back()->withErrors(['auth_mode' => __('ui.errors.company_account_unavailable')]);
        }

        $sessionAttempt = $request->session()->get(self::AUTH_MODE_ATTEMPT_SESSION_KEY);
        $attemptToken = $validated['auth_attempt'] ?? null;
        if (is_string($sessionAttempt) && $sessionAttempt !== '') {
            if ($attemptToken === null) {
                $attemptToken = $sessionAttempt;
            } elseif (! hash_equals($sessionAttempt, $attemptToken)) {
                return back()->withErrors(['auth_mode' => __('ui.errors.account_apply_failed')]);
            }
        } elseif (! is_string($attemptToken) || $attemptToken === '') {
            // Compatibility for a form rendered immediately before this release.
            $attemptToken = Str::random(48);
        }
        $attemptHash = hash('sha256', $request->user()->id.'|'.$attemptToken);
        $resultKey = 'workspace-auth-attempt-result:'.$attemptHash;
        $lockSeconds = max(60, (int) config('movie.workspace_mutation_lock_seconds', 180));
        $waitSeconds = max(1, (int) config('movie.workspace_mutation_lock_wait_seconds', 120));

        try {
            $selection = Cache::lock('workspace-auth-attempt:'.$attemptHash, $lockSeconds)
                ->block($waitSeconds, function () use (
                    $request,
                    $project,
                    $authMode,
                    $attemptToken,
                    $resultKey,
                ): array {
                    $completed = Cache::get($resultKey);
                    if (is_array($completed)) {
                        if (($completed['auth_mode'] ?? null) !== $authMode
                            || ! is_string($completed['entry_token'] ?? null)) {
                            throw new RuntimeException('auth_mode_attempt_conflict');
                        }

                        return $completed;
                    }

                    $this->workspaces->selectAuthMode($request->user(), $project, $authMode);
                    $completed = [
                        'auth_mode' => $authMode,
                        'entry_token' => hash_hmac('sha256', $attemptToken, (string) config('app.key')),
                    ];
                    Cache::put($resultKey, $completed, now()->addMinutes(10));

                    return $completed;
                });
        } catch (Throwable $exception) {
            $message = match ($exception->getMessage()) {
                'company_codex_occupied' => __('ui.errors.company_account_occupied'),
                'company_codex_unavailable', 'company_codex_resource_locked' => __('ui.errors.company_account_unavailable'),
                'workspace_capacity_full' => __('ui.errors.workspace_capacity_full'),
                'local_ai_runtime_change_blocked' => __('ui.errors.local_ai_runtime_change_blocked'),
                default => __('ui.errors.account_apply_failed'),
            };

            return back()->withErrors([
                'auth_mode' => $message,
            ]);
        }

        $entryToken = $selection['entry_token'];
        $request->session()->put(self::AUTH_MODE_SESSION_KEY, $selection['auth_mode']);
        $request->session()->put(self::ENTRY_TOKEN_SESSION_KEY, $entryToken);
        $request->session()->forget(self::AUTH_MODE_ATTEMPT_SESSION_KEY);

        return redirect()->route('workspace.terminal', ['entry' => $entryToken]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $project = $this->selectedProject($request);
        $authMode = $this->entryAuthMode($request);
        if (! $project || $authMode === null) {
            abort(403);
        }

        try {
            return response()->json($this->workspaces->sessions(
                $request->user(),
                $project,
                $authMode,
            ));
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'message' => __('ui.errors.session_history_failed'),
            ], 503);
        }
    }

    public function selectSession(Request $request): JsonResponse
    {
        $project = $this->selectedProject($request);
        $authMode = $this->entryAuthMode($request);
        if (! $project || $authMode === null) {
            abort(403);
        }
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(WorkspaceRuntimeService::SESSION_ACTIONS)],
            'session_id' => ['nullable', 'required_if:action,resume', 'uuid'],
        ]);
        $sessionId = isset($validated['session_id'])
            ? Str::lower((string) $validated['session_id'])
            : null;

        try {
            $result = $this->workspaces->selectSession(
                $request->user(),
                $project,
                $authMode,
                $validated['action'],
                $sessionId,
            );
        } catch (RuntimeException $exception) {
            report($exception);
            $conflict = in_array($exception->getMessage(), [
                'session_history_unavailable',
                'session_not_found',
                'workspace_not_running',
                'workspace_context_mismatch',
                'local_ai_job_active',
                'local_ai_runtime_change_blocked',
            ], true);

            return response()->json([
                'message' => match ($exception->getMessage()) {
                    'session_not_found' => __('ui.errors.session_not_found'),
                    'local_ai_job_active' => __('ui.errors.session_switch_job_active'),
                    'local_ai_runtime_change_blocked' => __('ui.errors.local_ai_runtime_change_blocked'),
                    default => __('ui.errors.session_switch_failed'),
                },
            ], $conflict ? 409 : 503);
        }

        return response()->json([
            ...$result,
            'redirect_url' => route('workspace.terminal', [
                'entry' => $request->session()->get(self::ENTRY_TOKEN_SESSION_KEY),
            ]),
        ]);
    }

    public function destroySession(Request $request, string $sessionId): JsonResponse
    {
        $project = $this->selectedProject($request);
        $authMode = $this->entryAuthMode($request);
        if (! $project || $authMode === null) {
            abort(403);
        }
        $validated = $request->validate([
            'confirmed' => ['required', 'accepted'],
        ]);

        try {
            $result = $this->workspaces->deleteSession(
                $request->user(),
                $project,
                $authMode,
                Str::lower($sessionId),
                (bool) $validated['confirmed'],
            );
        } catch (RuntimeException $exception) {
            report($exception);
            $conflict = in_array($exception->getMessage(), [
                'session_history_unavailable',
                'session_not_found',
                'session_active',
                'workspace_not_running',
                'workspace_context_mismatch',
            ], true);

            return response()->json([
                'message' => match ($exception->getMessage()) {
                    'session_not_found' => __('ui.errors.session_not_found'),
                    'session_active' => __('ui.errors.session_delete_active'),
                    default => __('ui.errors.session_delete_failed'),
                },
            ], $conflict ? 409 : 503);
        }

        return response()->json($result);
    }

    public function uploadMedia(Request $request): JsonResponse
    {
        $project = $this->selectedProject($request);
        if (! $project) {
            abort(403);
        }
        $authMode = $this->entryAuthMode($request);
        if ($authMode === null) {
            abort(403);
        }

        $validated = $request->validate([
            'media' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/x-m4v,video/webm,video/quicktime',
                'max:'.config('movie.workspace_video_max_kb'),
            ],
        ], [
            'media.mimetypes' => __('ui.media_upload.errors.type'),
            'media.max' => __('ui.media_upload.errors.too_large'),
        ]);

        $media = $validated['media'];
        $mime = (string) $media->getMimeType();
        $originalExtension = strtolower((string) $media->getClientOriginalExtension());
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => $originalExtension === 'm4v' ? 'm4v' : 'mp4',
            'video/x-m4v' => 'm4v',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            default => abort(422, __('ui.media_upload.errors.unsupported')),
        };
        $mediaType = str_starts_with($mime, 'video/') ? 'video' : 'image';
        if ($mediaType === 'image' && $media->getSize() > ((int) config('movie.workspace_image_max_kb') * 1024)) {
            abort(422, __('ui.errors.image_too_large'));
        }
        $contents = file_get_contents($media->getRealPath());
        if (! is_string($contents) || $contents === '') {
            abort(422, __('ui.media_upload.errors.unreadable'));
        }

        $filename = Str::uuid().'.'.$extension;
        try {
            $stored = $this->workspaces->uploadMedia(
                $request->user(),
                $project,
                $filename,
                $mime,
                $contents,
                $mediaType,
                $authMode,
            );
        } catch (RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return response()->json([
                'message' => __('ui.media_upload.errors.workspace_failed'),
            ], 503);
        }

        $libraryUrl = $mediaType === 'video'
            ? route('workspace.videos.show', ['project' => $project, 'video' => $stored['library_relative_path']])
            : route('workspace.images.show', ['project' => $project, 'image' => $stored['library_relative_path']]);

        return response()->json([
            ...$stored,
            'original_name' => Str::limit((string) $media->getClientOriginalName(), 120, ''),
            'media_type' => $mediaType,
            'library_url' => $libraryUrl,
            'mention_command' => '/mention '.$stored['path'],
        ], 201);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        if ($request->hasFile('image') && ! $request->hasFile('media')) {
            $request->files->set('media', $request->file('image'));
        }

        return $this->uploadMedia($request);
    }

    public function storeProject(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'directory_name' => ['required', 'string', 'max:64'],
        ]);
        $project = $this->projects->create(
            $request->user(),
            $validated['name'],
            $validated['directory_name'],
        );

        return $this->select($request, $project, __('ui.messages.project_created'));
    }

    public function updateProject(Request $request, WorkspaceProject $project): RedirectResponse
    {
        abort_unless($project->user_id === $request->user()->id, 404);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'directory_name' => ['required', 'string', 'max:64'],
        ]);

        try {
            $this->workspaces->updateProject(
                $request->user(),
                $project,
                $validated['name'],
                $validated['directory_name'],
            );
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage() === 'workspace_active'
                ? __('ui.errors.directory_active')
                : __('ui.errors.directory_rename_failed');

            return back()->withErrors(['directory_name' => $message])->withInput();
        }

        return redirect()->route('workspace')->with('status', __('ui.messages.project_updated'));
    }

    public function destroyProject(Request $request, WorkspaceProject $project): RedirectResponse
    {
        abort_unless($project->user_id === $request->user()->id, 404);
        $request->validate([
            'delete_confirmation' => ['required', 'string', Rule::in(['delete'])],
        ], [
            'delete_confirmation.in' => __('ui.errors.confirm_project_delete'),
        ]);

        try {
            $this->workspaces->deleteProject($request->user(), $project);
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage() === 'workspace_active'
                ? __('ui.errors.project_delete_active')
                : __('ui.errors.project_delete_failed');

            return back()->withErrors(['delete_confirmation' => $message]);
        }

        if ($request->session()->get(WorkspaceProjectService::SESSION_KEY) === $project->id) {
            $request->session()->forget(WorkspaceProjectService::SESSION_KEY);
        }

        return redirect()->route('workspace')->with('status', __('ui.messages.project_removed'));
    }

    public function selectProject(Request $request, WorkspaceProject $project): RedirectResponse
    {
        abort_unless($project->user_id === $request->user()->id, 404);

        return $this->select($request, $project, __('ui.messages.project_opened'));
    }

    public function start(Request $request): RedirectResponse
    {
        $project = $this->selectedProject($request);
        if (! $project) {
            return redirect()->route('workspace')->withErrors([
                'workspace' => __('ui.errors.choose_project_start'),
            ]);
        }
        $reservation = $this->workspaces->currentFor($request->user());
        if (! $reservation) {
            return back()->withErrors(['workspace' => __('ui.errors.no_reservation_start')]);
        }
        $authMode = $this->entryAuthMode($request);
        if ($authMode === null) {
            return redirect()->route('workspace.terminal')->withErrors([
                'auth_mode' => __('ui.errors.choose_account_start'),
            ]);
        }

        try {
            $this->workspaces->start($reservation, $authMode);
        } catch (Throwable) {
            return back()->withErrors(['workspace' => __('ui.errors.workspace_start_failed')]);
        }

        return redirect()->route('workspace.terminal', [
            'entry' => $request->session()->get(self::ENTRY_TOKEN_SESSION_KEY),
        ])->with('status', __('ui.messages.workspace_started'));
    }

    public function stop(Request $request): RedirectResponse
    {
        $request->validate([
            'stop_confirmation' => ['required', 'string', Rule::in(['stop'])],
        ], [
            'stop_confirmation.in' => __('ui.errors.confirm_stop'),
        ]);

        $runtime = $this->workspaces->runtimeFor($request->user());
        if (! $runtime || $runtime->status !== 'running') {
            return back()->withErrors([
                'workspace' => __('ui.errors.no_active_stop'),
            ]);
        }

        try {
            $this->workspaces->stopRuntime($request->user());
        } catch (Throwable) {
            return back()->withErrors([
                'workspace' => __('ui.errors.workspace_stop_failed'),
            ]);
        }

        return redirect()->route('workspace')->with(
            'status',
            __('ui.messages.workspace_stopped_restartable'),
        );
    }

    public function abandon(Request $request, Reservation $reservation): RedirectResponse
    {
        abort_unless($reservation->user_id === $request->user()->id, 404);
        $request->validate([
            'abandon_confirmation' => ['required', 'string', Rule::in(['abandon'])],
        ], [
            'abandon_confirmation.in' => __('ui.errors.confirm_abandon'),
        ]);

        try {
            $this->workspaces->abandon($reservation, $request->user());
        } catch (Throwable) {
            return back()->withErrors([
                'workspace' => __('ui.errors.reservation_abandon_failed'),
            ]);
        }

        return redirect()->route('workspace.terminal')->with(
            'status',
            __('ui.messages.reservation_abandoned'),
        );
    }

    public function authorizeTerminal(Request $request): Response
    {
        $project = $this->selectedProject($request);
        if (! $project) {
            abort(403);
        }
        $authMode = $this->entryAuthMode($request);
        if ($authMode === null) {
            abort(403);
        }
        $runtime = $this->workspaces->authorizeTerminal($request->user(), $project, $authMode);
        $claim = $this->routeClaims->issue($runtime);

        return response('', 204)->header('X-Movie-Route', $claim);
    }

    private function select(Request $request, WorkspaceProject $project, string $message): RedirectResponse
    {
        try {
            $this->workspaces->selectProject($request->user(), $project);
        } catch (Throwable) {
            return back()->withErrors([
                'workspace' => __('ui.errors.project_selection_failed'),
            ]);
        }
        $request->session()->put(WorkspaceProjectService::SESSION_KEY, $project->id);

        return redirect()->route('workspace.terminal')->with('status', $message);
    }

    private function selectedProject(Request $request): ?WorkspaceProject
    {
        $projectId = $request->session()->get(WorkspaceProjectService::SESSION_KEY);
        if (! is_string($projectId)) {
            return null;
        }

        return $this->projects->ownedProject($request->user(), $projectId);
    }

    private function localAiPhase(?Reservation $reservation, bool $localAiEnabled, CarbonInterface $now): string
    {
        if (! $this->workspaces->enabled()) {
            return 'disabled';
        }
        if ($localAiEnabled) {
            return 'ready';
        }
        if (! $reservation) {
            return 'unavailable';
        }
        return $now->lessThan($reservation->starts_at) ? 'countdown' : 'starting';
    }

    private function entryAuthMode(Request $request, bool $checkQuery = false): ?string
    {
        $authMode = $request->session()->get(self::AUTH_MODE_SESSION_KEY);
        $entryToken = $request->session()->get(self::ENTRY_TOKEN_SESSION_KEY);
        $valid = is_string($authMode)
            && in_array($authMode, WorkspaceRuntimeService::AUTH_MODES, true)
            && is_string($entryToken)
            && $entryToken !== '';

        if ($checkQuery) {
            $queryToken = $request->query('entry');
            $valid = $valid
                && is_string($queryToken)
                && hash_equals($entryToken, $queryToken);
        }
        if ($valid && $authMode === 'company' && ! config('movie.company_codex_enabled')) {
            $valid = false;
        }
        if (! $valid) {
            $request->session()->forget([self::AUTH_MODE_SESSION_KEY, self::ENTRY_TOKEN_SESSION_KEY]);

            return null;
        }

        return $authMode;
    }

    private function authModeAttempt(Request $request): string
    {
        $attempt = $request->session()->get(self::AUTH_MODE_ATTEMPT_SESSION_KEY);
        if (! is_string($attempt) || strlen($attempt) !== 48) {
            $attempt = Str::random(48);
            $request->session()->put(self::AUTH_MODE_ATTEMPT_SESSION_KEY, $attempt);
        }

        return $attempt;
    }
}
