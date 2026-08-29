<?php

namespace App\Services;

final class WorkspaceStyleDemoLocator
{
    public function pathFor(array $style): ?string
    {
        $filename = (string) ($style['demo'] ?? '');
        if ($filename === '' || basename($filename) !== $filename || ! str_ends_with(strtolower($filename), '.mp4')) {
            return null;
        }

        $root = (string) config('movie.style_demo_root');
        $realRoot = realpath($root);
        $candidate = $root.DIRECTORY_SEPARATOR.$filename;
        $real = realpath($candidate);

        if ($realRoot === false
            || $real === false
            || is_link($root)
            || is_link($candidate)
            || ! is_file($real)
            || ! str_starts_with($real, $realRoot.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }
}
