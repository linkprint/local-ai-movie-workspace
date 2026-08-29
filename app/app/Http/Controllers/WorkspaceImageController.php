<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\WorkspaceProject;
use App\Services\WorkspaceImageService;
use App\Services\WorkspaceProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class WorkspaceImageController extends Controller
{
    public function __construct(
        private readonly WorkspaceProjectService $projects,
        private readonly WorkspaceImageService $images,
    ) {}

    public function index(Request $request): View
    {
        $profile = $this->projects->profileFor($request->user());
        $projects = $request->user()->workspaceProjects()->orderBy('name')->get();
        $entries = $projects->map(fn (WorkspaceProject $project): array => [
            'project' => $project,
            'images' => $this->images->imagesFor($profile, $project),
        ]);
        $legacyImages = $this->images->imagesFor($profile, WorkspaceImageService::LEGACY_SCOPE);

        return view('workspace-images', [
            'projects' => $entries,
            'legacyImages' => $legacyImages,
            'totalImages' => $entries->sum(fn (array $entry): int => count($entry['images'])) + count($legacyImages),
        ]);
    }

    public function show(Request $request, WorkspaceProject $project, string $image): BinaryFileResponse
    {
        $this->authorizeProject($request, $project);

        return $this->imageResponse($request, $project, $image);
    }

    public function showLegacy(Request $request, string $image): BinaryFileResponse
    {
        return $this->imageResponse($request, WorkspaceImageService::LEGACY_SCOPE, $image);
    }

    public function update(Request $request, WorkspaceProject $project, string $image): RedirectResponse
    {
        $this->authorizeProject($request, $project);
        $validated = $request->validate(['new_name' => ['required', 'string', 'max:200']]);
        $profile = $this->projects->profileFor($request->user());
        $this->images->rename($profile, $project, $image, $validated['new_name']);

        return redirect()->route('workspace.images.index')->with('status', __('ui.messages.image_renamed'));
    }

    public function updateLegacy(Request $request, string $image): RedirectResponse
    {
        $validated = $request->validate(['new_name' => ['required', 'string', 'max:200']]);
        $profile = $this->projects->profileFor($request->user());
        $this->images->rename(
            $profile,
            WorkspaceImageService::LEGACY_SCOPE,
            $image,
            $validated['new_name'],
        );

        return redirect()->route('workspace.images.index')->with('status', __('ui.messages.legacy_image_renamed'));
    }

    public function destroy(Request $request, WorkspaceProject $project, string $image): JsonResponse|RedirectResponse
    {
        $this->authorizeProject($request, $project);
        $this->deleteImage($request, $project, $image);

        return $this->trashResponse($request, __('ui.messages.image_trashed'));
    }

    public function destroyLegacy(Request $request, string $image): JsonResponse|RedirectResponse
    {
        $this->deleteImage($request, WorkspaceImageService::LEGACY_SCOPE, $image);

        return $this->trashResponse($request, __('ui.messages.legacy_image_trashed'));
    }

    public function bulkTrash(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*' => ['required', 'string', 'max:8192', 'distinct'],
            'bulk_confirmation' => ['required', 'string', Rule::in(['move to recovery'])],
        ]);
        $profile = $this->projects->profileFor($request->user());

        try {
            $summary = $this->images->trashBatch($profile, $validated['items']);
        } catch (RuntimeException) {
            abort(404);
        }
        AuditEvent::record('workspace.images.trashed_batch', $profile, $summary);

        $message = __('ui.messages.images_trashed', ['count' => $summary['count']]);
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'count' => $summary['count'],
            ]);
        }

        return redirect()->route('workspace.images.index')
            ->with('status', $message);
    }

    private function imageResponse(
        Request $request,
        WorkspaceProject|string $scope,
        string $image,
    ): BinaryFileResponse {
        $profile = $this->projects->profileFor($request->user());
        try {
            $path = $this->images->resolve($profile, $scope, $image);
        } catch (RuntimeException) {
            abort(404);
        }

        $filename = basename($path);
        $fallback = trim(Str::ascii($filename));
        if ($fallback === '' || preg_match('/[^\x20-\x7e]/', $fallback)) {
            $fallback = 'image.'.strtolower(pathinfo($path, PATHINFO_EXTENSION));
        }
        $disposition = HeaderUtils::makeDisposition(
            $request->boolean('download') ? HeaderUtils::DISPOSITION_ATTACHMENT : HeaderUtils::DISPOSITION_INLINE,
            $filename,
            $fallback,
        );

        $response = response()->file($path, [
            'Content-Type' => $this->images->contentType($path),
            'Content-Disposition' => $disposition,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    private function deleteImage(
        Request $request,
        WorkspaceProject|string $scope,
        string $image,
    ): void {
        $request->validate([
            'delete_confirmation' => ['required', 'string', 'in:delete'],
        ]);
        $profile = $this->projects->profileFor($request->user());
        try {
            $this->images->trash($profile, $scope, $image);
        } catch (RuntimeException) {
            abort(404);
        }
    }

    private function trashResponse(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        return redirect()->route('workspace.images.index')->with('status', $message);
    }

    private function authorizeProject(Request $request, WorkspaceProject $project): void
    {
        abort_unless($project->user_id === $request->user()->id, 404);
    }
}
