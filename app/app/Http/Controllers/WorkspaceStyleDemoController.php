<?php

namespace App\Http\Controllers;

use App\Services\WorkspaceStyleDemoLocator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

class WorkspaceStyleDemoController extends Controller
{
    public function __invoke(string $skill, WorkspaceStyleDemoLocator $demos): BinaryFileResponse
    {
        $style = collect(config('movie.styles', []))->first(
            fn (array $candidate): bool => ($candidate['skill'] ?? null) === $skill,
        );
        if (! is_array($style)) {
            abort(404);
        }

        $real = $demos->pathFor($style);
        if ($real === null) {
            abort(404);
        }

        $filename = basename($real);

        return response()->file($real, [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $filename,
            ),
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
        ]);
    }
}
