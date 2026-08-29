<?php

namespace App\Services;

use App\Models\WorkspaceProfile;
use App\Models\WorkspaceProject;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class WorkspaceVideoService
{
    public const LEGACY_SCOPE = '_legacy';

    private const EXTENSIONS = ['mp4', 'webm', 'mov', 'm4v'];

    /** @return array<int, array{path: string, name: string, size: int, modified_at: int, url: string, download_url: string, selection_id: string}> */
    public function videosFor(WorkspaceProfile $profile, WorkspaceProject|string $scope): array
    {
        $root = $this->scopeRoot($profile, $scope);
        if (! is_dir($root) || is_link($root)) {
            return [];
        }

        $videos = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->isLink() || ! $file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if (! is_string($relative) || ! $this->isVideoName($relative)) {
                continue;
            }
            $videos[] = [
                'path' => $relative,
                'name' => $file->getFilename(),
                'size' => $file->getSize(),
                'modified_at' => $file->getMTime(),
                'url' => $this->urlFor($scope, $relative),
                'download_url' => $this->urlFor($scope, $relative, download: true),
                'selection_id' => $this->selectionIdentifier($scope, $relative),
            ];
            if (count($videos) >= 500) {
                break;
            }
        }

        usort($videos, fn (array $left, array $right): int => $right['modified_at'] <=> $left['modified_at']);

        return $videos;
    }

    public function resolve(WorkspaceProfile $profile, WorkspaceProject|string $scope, string $relative): string
    {
        $parts = $this->pathParts($relative);
        $root = $this->scopeRoot($profile, $scope);
        $realRoot = realpath($root);
        if ($realRoot === false || is_link($root)) {
            throw new RuntimeException('video_not_found');
        }

        $candidate = $realRoot;
        foreach ($parts as $part) {
            $candidate .= DIRECTORY_SEPARATOR.$part;
            if (is_link($candidate)) {
                throw new RuntimeException('video_not_found');
            }
        }
        $real = realpath($candidate);
        if ($real === false
            || ! is_file($real)
            || ! str_starts_with($real, $realRoot.DIRECTORY_SEPARATOR)
            || ! $this->isVideoName($real)) {
            throw new RuntimeException('video_not_found');
        }

        return $real;
    }

    public function rename(
        WorkspaceProfile $profile,
        WorkspaceProject|string $scope,
        string $relative,
        string $newName,
    ): string {
        $source = $this->resolve($profile, $scope, $relative);
        $newName = trim($newName);
        if ($newName === ''
            || strlen($newName) > 200
            || basename($newName) !== $newName
            || preg_match('/[\x00-\x1f\x7f\/\\\\]/u', $newName)
            || ! $this->isVideoName($newName)) {
            throw ValidationException::withMessages([
                'new_name' => __('ui.errors.video_name_rules'),
            ]);
        }

        $destination = dirname($source).DIRECTORY_SEPARATOR.$newName;
        if ($destination === $source) {
            return $newName;
        }
        if (file_exists($destination) || is_link($destination)) {
            throw ValidationException::withMessages(['new_name' => __('ui.errors.video_name_duplicate')]);
        }
        if (! @rename($source, $destination)) {
            throw new RuntimeException('video_rename_failed');
        }

        return $newName;
    }

    public function trash(WorkspaceProfile $profile, WorkspaceProject|string $scope, string $relative): void
    {
        $source = $this->resolve($profile, $scope, $relative);
        $scopeName = $this->scopeName($scope);
        $trash = $this->trashDirectory($profile, $scopeName);
        $destination = $trash.DIRECTORY_SEPARATOR.now()->utc()->format('YmdHis')
            .'-'.Str::uuid().'-'.basename($source);
        if (! @rename($source, $destination)) {
            throw new RuntimeException('video_delete_failed');
        }
    }

    /**
     * @param  array<int, string>  $identifiers
     * @return array{count: int, total_bytes: int, scopes: array<int, string>}
     */
    public function trashBatch(WorkspaceProfile $profile, array $identifiers): array
    {
        $items = [];
        foreach ($identifiers as $identifier) {
            [$scope, $relative] = $this->decodeSelectionIdentifier($identifier);
            $path = $this->resolve($profile, $scope, $relative);
            $items[] = [
                'path' => $path,
                'scope' => $scope,
                'size' => filesize($path) ?: 0,
            ];
        }

        $timestamp = now()->utc()->format('YmdHis');
        $moves = [];
        foreach ($items as $item) {
            $trash = $this->trashDirectory($profile, $item['scope']);
            $destination = $trash.DIRECTORY_SEPARATOR.$timestamp.'-'.Str::uuid().'-'.basename($item['path']);
            if (file_exists($destination) || is_link($destination)) {
                throw new RuntimeException('video_trash_unavailable');
            }
            $moves[] = ['source' => $item['path'], 'destination' => $destination];
        }

        $completed = [];
        try {
            foreach ($moves as $move) {
                if (! @rename($move['source'], $move['destination'])) {
                    throw new RuntimeException('video_delete_failed');
                }
                $completed[] = $move;
            }
        } catch (\Throwable $exception) {
            foreach (array_reverse($completed) as $move) {
                @rename($move['destination'], $move['source']);
            }
            throw $exception;
        }

        return [
            'count' => count($items),
            'total_bytes' => array_sum(array_column($items, 'size')),
            'scopes' => array_values(array_unique(array_column($items, 'scope'))),
        ];
    }

    public function selectionIdentifier(WorkspaceProject|string $scope, string $relative): string
    {
        $scopeName = $this->scopeName($scope);
        $path = implode('/', $this->pathParts($relative));

        return rtrim(strtr(base64_encode($scopeName."\0".$path), '+/', '-_'), '=');
    }

    public function contentType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            default => 'application/octet-stream',
        };
    }

    private function scopeRoot(WorkspaceProfile $profile, WorkspaceProject|string $scope): string
    {
        return $this->storageRoot($profile).DIRECTORY_SEPARATOR.$this->scopeName($scope);
    }

    private function storageRoot(WorkspaceProfile $profile): string
    {
        $storage = strtolower((string) $profile->storage_uuid);
        if (! Str::isUuid($storage)) {
            throw new RuntimeException('invalid_video_storage');
        }

        return rtrim((string) config('movie.video_root'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$storage;
    }

    private function scopeName(WorkspaceProject|string $scope): string
    {
        $value = $scope instanceof WorkspaceProject ? (string) $scope->id : $scope;
        if ($value !== self::LEGACY_SCOPE && ! Str::isUuid($value)) {
            throw new RuntimeException('invalid_video_scope');
        }

        return strtolower($value);
    }

    /** @return array{string, string} */
    private function decodeSelectionIdentifier(string $identifier): array
    {
        if ($identifier === '' || strlen($identifier) > 8192 || preg_match('/[^A-Za-z0-9_-]/', $identifier)) {
            throw new RuntimeException('video_not_found');
        }

        $encoded = strtr($identifier, '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding !== 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($encoded, true);
        if (! is_string($decoded)) {
            throw new RuntimeException('video_not_found');
        }

        $parts = explode("\0", $decoded);
        if (count($parts) !== 2) {
            throw new RuntimeException('video_not_found');
        }
        [$scope, $relative] = $parts;
        $scope = $this->scopeName($scope);
        $relative = implode('/', $this->pathParts($relative));
        if ($this->selectionIdentifier($scope, $relative) !== $identifier) {
            throw new RuntimeException('video_not_found');
        }

        return [$scope, $relative];
    }

    private function trashDirectory(WorkspaceProfile $profile, string $scope): string
    {
        $storageRoot = $this->storageRoot($profile);
        if (is_link($storageRoot) || realpath($storageRoot) === false) {
            throw new RuntimeException('video_trash_unavailable');
        }

        $trashRoot = $storageRoot.DIRECTORY_SEPARATOR.'_trash';
        if (is_link($trashRoot)
            || (! is_dir($trashRoot) && ! mkdir($trashRoot, 0770, true) && ! is_dir($trashRoot))) {
            throw new RuntimeException('video_trash_unavailable');
        }
        chmod($trashRoot, 0770);

        $trash = $trashRoot.DIRECTORY_SEPARATOR.$this->scopeName($scope);
        if (is_link($trash)
            || (! is_dir($trash) && ! mkdir($trash, 0770, true) && ! is_dir($trash))) {
            throw new RuntimeException('video_trash_unavailable');
        }
        chmod($trash, 0770);

        return $trash;
    }

    /** @return array<int, string> */
    private function pathParts(string $relative): array
    {
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\')) {
            throw new RuntimeException('video_not_found');
        }
        $parts = explode('/', $relative);
        if (count($parts) > 20) {
            throw new RuntimeException('video_not_found');
        }
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || strlen($part) > 255) {
                throw new RuntimeException('video_not_found');
            }
        }

        return $parts;
    }

    private function isVideoName(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    private function urlFor(WorkspaceProject|string $scope, string $relative, bool $download = false): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', $relative)));
        $route = $scope instanceof WorkspaceProject
            ? '/workspace/projects/'.$scope->id.'/videos/'.$encoded
            : '/workspace/videos/legacy/'.$encoded;

        return url($route.($download ? '?download=1' : ''));
    }
}
