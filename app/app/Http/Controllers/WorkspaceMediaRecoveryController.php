<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Services\WorkspaceMediaRecoveryService;
use App\Services\WorkspaceProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class WorkspaceMediaRecoveryController extends Controller
{
    public function __construct(
        private readonly WorkspaceProjectService $projects,
        private readonly WorkspaceMediaRecoveryService $recovery,
    ) {}

    public function index(Request $request): View
    {
        $profile = $this->projects->profileFor($request->user());
        $projectNames = $request->user()->workspaceProjects()
            ->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, string $id): array => [strtolower($id) => $name]);
        $items = array_map(function (array $item) use ($projectNames): array {
            $item['scope_label'] = $item['scope'] === '_legacy'
                ? __('ui.recovery.legacy_scope')
                : ($projectNames->get($item['scope']) ?? __('ui.recovery.removed_project'));

            return $item;
        }, $this->recovery->itemsFor($profile));

        return view('workspace-recovery', [
            'items' => $items,
            'imageCount' => count(array_filter($items, fn (array $item): bool => $item['type'] === 'image')),
            'videoCount' => count(array_filter($items, fn (array $item): bool => $item['type'] === 'video')),
            'totalBytes' => array_sum(array_column($items, 'size')),
        ]);
    }

    public function show(Request $request, string $item): BinaryFileResponse
    {
        $profile = $this->projects->profileFor($request->user());
        try {
            $media = $this->recovery->resolve($profile, $item);
        } catch (RuntimeException) {
            abort(404);
        }

        $filename = $media['original_name'];
        $fallback = trim(Str::ascii($filename));
        if ($fallback === '' || preg_match('/[^\x20-\x7e]/', $fallback)) {
            $fallback = $media['type'].'.'.strtolower(pathinfo($media['path'], PATHINFO_EXTENSION));
        }

        $response = response()->file($media['path'], [
            'Content-Type' => $this->recovery->contentType($media['path']),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $filename, $fallback),
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
        ]);
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    public function update(Request $request): RedirectResponse
    {
        $action = $request->input('action');
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['restore', 'purge'])],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*' => ['required', 'string', 'max:1024', 'distinct'],
            'purge_confirmation' => [Rule::requiredIf($action === 'purge'), 'nullable', 'string', Rule::in(['delete'])],
        ]);
        $profile = $this->projects->profileFor($request->user());

        try {
            if ($validated['action'] === 'restore') {
                $summary = $this->recovery->restoreBatch($profile, $validated['items']);
                AuditEvent::record('workspace.media_recovery.restored', $profile, $summary);
                $message = __('ui.messages.media_restored', ['count' => $summary['count']]);
            } else {
                $summary = $this->recovery->purgeBatch($profile, $validated['items']);
                AuditEvent::record('workspace.media_recovery.purged', $profile, $summary);
                $message = __('ui.messages.media_purged', ['count' => $summary['count']]);
            }
        } catch (RuntimeException) {
            abort(404);
        }

        return redirect()->route('workspace.recovery.index')->with('status', $message);
    }
}
