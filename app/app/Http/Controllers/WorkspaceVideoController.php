<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\WorkspaceProject;
use App\Services\WorkspaceProjectService;
use App\Services\WorkspaceVideoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class WorkspaceVideoController extends Controller
{
    public function __construct(
        private readonly WorkspaceProjectService $projects,
        private readonly WorkspaceVideoService $videos,
    ) {}

    public function index(Request $request): View
    {
        $profile = $this->projects->profileFor($request->user());
        $projects = $request->user()->workspaceProjects()->orderBy('name')->get();
        $entries = $projects->map(fn (WorkspaceProject $project): array => [
            'project' => $project,
            'videos' => $this->videos->videosFor($profile, $project),
        ]);
        $legacyVideos = $this->videos->videosFor($profile, WorkspaceVideoService::LEGACY_SCOPE);

        return view('workspace-videos', [
            'projects' => $entries,
            'legacyVideos' => $legacyVideos,
            'totalVideos' => $entries->sum(fn (array $entry): int => count($entry['videos'])) + count($legacyVideos),
        ]);
    }

    public function show(Request $request, WorkspaceProject $project, string $video): BinaryFileResponse
    {
        $this->authorizeProject($request, $project);

        return $this->videoResponse($request, $project, $video);
    }

    public function showLegacy(Request $request, string $video): BinaryFileResponse
    {
        return $this->videoResponse($request, WorkspaceVideoService::LEGACY_SCOPE, $video);
    }

    public function update(Request $request, WorkspaceProject $project, string $video): RedirectResponse
    {
        $this->authorizeProject($request, $project);
        $validated = $request->validate(['new_name' => ['required', 'string', 'max:200']]);
        $profile = $this->projects->profileFor($request->user());
        $this->videos->rename($profile, $project, $video, $validated['new_name']);

        return redirect()->route('workspace.videos.index')->with('status', __('ui.messages.video_renamed'));
    }

    public function updateLegacy(Request $request, string $video): RedirectResponse
    {
        $validated = $request->validate(['new_name' => ['required', 'string', 'max:200']]);
        $profile = $this->projects->profileFor($request->user());
        $this->videos->rename(
            $profile,
            WorkspaceVideoService::LEGACY_SCOPE,
            $video,
            $validated['new_name'],
        );

        return redirect()->route('workspace.videos.index')->with('status', __('ui.messages.legacy_video_renamed'));
    }

    public function destroy(Request $request, WorkspaceProject $project, string $video): RedirectResponse
    {
        $this->authorizeProject($request, $project);
        $this->deleteVideo($request, $project, $video);

        return redirect()->route('workspace.videos.index')->with('status', __('ui.messages.video_trashed'));
    }

    public function destroyLegacy(Request $request, string $video): RedirectResponse
    {
        $this->deleteVideo($request, WorkspaceVideoService::LEGACY_SCOPE, $video);

        return redirect()->route('workspace.videos.index')->with('status', __('ui.messages.legacy_video_trashed'));
    }

    public function bulkTrash(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*' => ['required', 'string', 'max:8192', 'distinct'],
            'bulk_confirmation' => ['required', 'string', Rule::in(['move to recovery'])],
        ]);
        $profile = $this->projects->profileFor($request->user());

        try {
            $summary = $this->videos->trashBatch($profile, $validated['items']);
        } catch (RuntimeException) {
            abort(404);
        }
        AuditEvent::record('workspace.videos.trashed_batch', $profile, $summary);

        return redirect()->route('workspace.videos.index')
            ->with('status', __('ui.messages.videos_trashed', ['count' => $summary['count']]));
    }

    private function videoResponse(
        Request $request,
        WorkspaceProject|string $scope,
        string $video,
    ): BinaryFileResponse {
        $profile = $this->projects->profileFor($request->user());
        try {
            $path = $this->videos->resolve($profile, $scope, $video);
        } catch (RuntimeException) {
            abort(404);
        }
        $download = $request->boolean('download');
        $filename = basename($path);
        $fallback = Str::ascii($filename);
        if ($fallback === '' || preg_match('/[^\x20-\x7e]/', $fallback)) {
            $fallback = 'video.'.strtolower(pathinfo($path, PATHINFO_EXTENSION));
        }
        $disposition = HeaderUtils::makeDisposition(
            $download ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE,
            $filename,
            $fallback,
        );

        return response()->file($path, [
            'Content-Type' => $this->videos->contentType($path),
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    private function deleteVideo(
        Request $request,
        WorkspaceProject|string $scope,
        string $video,
    ): void {
        $request->validate([
            'delete_confirmation' => ['required', 'string', 'in:delete'],
        ]);
        $profile = $this->projects->profileFor($request->user());
        try {
            $this->videos->trash($profile, $scope, $video);
        } catch (RuntimeException) {
            abort(404);
        }
    }

    private function authorizeProject(Request $request, WorkspaceProject $project): void
    {
        abort_unless($project->user_id === $request->user()->id, 404);
    }
}
