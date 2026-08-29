<?php

namespace App\Services;

use App\Models\WorkspaceProfile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SplFileInfo;

class WorkspaceMediaRecoveryService
{
    private const IMAGE_EXTENSIONS = ['gif', 'jpeg', 'jpg', 'png', 'webp'];

    private const VIDEO_EXTENSIONS = ['m4v', 'mov', 'mp4', 'webm'];

    /**
     * @return array<int, array{
     *     id: string,
     *     scope: string,
     *     stored_name: string,
     *     original_name: string,
     *     type: 'image'|'video',
     *     size: int,
     *     removed_at: int,
     *     preview_url: string
     * }>
     */
    public function itemsFor(WorkspaceProfile $profile): array
    {
        $trashRoot = $this->trashRoot($profile);
        if (! is_dir($trashRoot) || is_link($trashRoot)) {
            return [];
        }

        $items = [];
        foreach (new \DirectoryIterator($trashRoot) as $scopeDirectory) {
            if ($scopeDirectory->isDot() || $scopeDirectory->isLink() || ! $scopeDirectory->isDir()) {
                continue;
            }

            $scope = $scopeDirectory->getFilename();
            if (! $this->isScopeName($scope)) {
                continue;
            }

            foreach (new \DirectoryIterator($scopeDirectory->getPathname()) as $file) {
                if ($file->isDot() || $file->isLink() || ! $file->isFile()) {
                    continue;
                }

                $type = $this->mediaType($file->getFilename());
                if ($type === null) {
                    continue;
                }

                $id = $this->encodeIdentifier($scope, $file->getFilename());
                $items[] = [
                    'id' => $id,
                    'scope' => $scope,
                    'stored_name' => $file->getFilename(),
                    'original_name' => $this->originalName($file->getFilename()),
                    'type' => $type,
                    'size' => $file->getSize(),
                    'removed_at' => $this->removedAt($file),
                    'preview_url' => route('workspace.recovery.media.show', ['item' => $id]),
                ];

                if (count($items) >= 1000) {
                    break 2;
                }
            }
        }

        usort($items, fn (array $left, array $right): int => $right['removed_at'] <=> $left['removed_at']);

        return $items;
    }

    /** @return array{path: string, scope: string, stored_name: string, original_name: string, type: 'image'|'video', size: int} */
    public function resolve(WorkspaceProfile $profile, string $identifier): array
    {
        [$scope, $storedName] = $this->decodeIdentifier($identifier);
        $trashRoot = realpath($this->trashRoot($profile));
        if ($trashRoot === false || is_link($this->trashRoot($profile))) {
            throw new RuntimeException('recovery_item_not_found');
        }

        $scopeRoot = $trashRoot.DIRECTORY_SEPARATOR.$scope;
        if (is_link($scopeRoot)) {
            throw new RuntimeException('recovery_item_not_found');
        }
        $realScopeRoot = realpath($scopeRoot);
        if ($realScopeRoot === false
            || ! is_dir($realScopeRoot)
            || ! str_starts_with($realScopeRoot, $trashRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('recovery_item_not_found');
        }

        $candidate = $realScopeRoot.DIRECTORY_SEPARATOR.$storedName;
        if (is_link($candidate)) {
            throw new RuntimeException('recovery_item_not_found');
        }
        $real = realpath($candidate);
        $type = $this->mediaType($storedName);
        if ($real === false
            || ! is_file($real)
            || ! str_starts_with($real, $realScopeRoot.DIRECTORY_SEPARATOR)
            || $type === null) {
            throw new RuntimeException('recovery_item_not_found');
        }

        return [
            'path' => $real,
            'scope' => $scope,
            'stored_name' => $storedName,
            'original_name' => $this->originalName($storedName),
            'type' => $type,
            'size' => filesize($real) ?: 0,
        ];
    }

    /** @param array<int, string> $identifiers
     * @return array{count: int, images: int, videos: int, total_bytes: int, scopes: array<int, string>}
     */
    public function restoreBatch(WorkspaceProfile $profile, array $identifiers): array
    {
        $items = $this->resolveBatch($profile, $identifiers);
        $storageRoot = $this->realStorageRoot($profile);
        $destinations = [];

        foreach ($items as $item) {
            $scopeRoot = $storageRoot.DIRECTORY_SEPARATOR.$item['scope'];
            if (is_link($scopeRoot)) {
                throw new RuntimeException('recovery_scope_unavailable');
            }

            $destination = $scopeRoot.DIRECTORY_SEPARATOR.$item['original_name'];
            $destinationKey = strtolower($destination);
            if (isset($destinations[$destinationKey]) || file_exists($destination) || is_link($destination)) {
                throw ValidationException::withMessages([
                    'items' => __('ui.recovery_errors.restore_collision', ['name' => $item['original_name']]),
                ]);
            }
            $destinations[$destinationKey] = $destination;
        }

        $moved = [];
        try {
            foreach ($items as $item) {
                $scopeRoot = $storageRoot.DIRECTORY_SEPARATOR.$item['scope'];
                if (! is_dir($scopeRoot)) {
                    if (! mkdir($scopeRoot, 0770, true) && ! is_dir($scopeRoot)) {
                        throw new RuntimeException('recovery_scope_unavailable');
                    }
                    chmod($scopeRoot, 0770);
                }

                $destination = $scopeRoot.DIRECTORY_SEPARATOR.$item['original_name'];
                if (! @rename($item['path'], $destination)) {
                    throw new RuntimeException('recovery_restore_failed');
                }
                $moved[] = ['source' => $item['path'], 'destination' => $destination];
            }
        } catch (\Throwable $exception) {
            foreach (array_reverse($moved) as $move) {
                @rename($move['destination'], $move['source']);
            }
            throw $exception;
        }

        $this->pruneEmptyTrashDirectories($profile, $items);

        return $this->summary($items);
    }

    /** @param array<int, string> $identifiers
     * @return array{count: int, images: int, videos: int, total_bytes: int, scopes: array<int, string>}
     */
    public function purgeBatch(WorkspaceProfile $profile, array $identifiers): array
    {
        $items = $this->resolveBatch($profile, $identifiers);

        foreach ($items as $item) {
            if (! @unlink($item['path'])) {
                throw new RuntimeException('recovery_purge_failed');
            }
        }

        $this->pruneEmptyTrashDirectories($profile, $items);

        return $this->summary($items);
    }

    public function contentType(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'gif' => 'image/gif',
            'jpeg', 'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            default => 'application/octet-stream',
        };
    }

    /** @param array<int, string> $identifiers
     * @return array<int, array{path: string, scope: string, stored_name: string, original_name: string, type: 'image'|'video', size: int}>
     */
    private function resolveBatch(WorkspaceProfile $profile, array $identifiers): array
    {
        $items = [];
        foreach ($identifiers as $identifier) {
            $items[] = $this->resolve($profile, $identifier);
        }

        return $items;
    }

    /** @param array<int, array{path: string, scope: string, stored_name: string, original_name: string, type: 'image'|'video', size: int}> $items
     * @return array{count: int, images: int, videos: int, total_bytes: int, scopes: array<int, string>}
     */
    private function summary(array $items): array
    {
        $scopes = array_values(array_unique(array_column($items, 'scope')));

        return [
            'count' => count($items),
            'images' => count(array_filter($items, fn (array $item): bool => $item['type'] === 'image')),
            'videos' => count(array_filter($items, fn (array $item): bool => $item['type'] === 'video')),
            'total_bytes' => array_sum(array_column($items, 'size')),
            'scopes' => $scopes,
        ];
    }

    /** @param array<int, array{scope: string}> $items */
    private function pruneEmptyTrashDirectories(WorkspaceProfile $profile, array $items): void
    {
        $trashRoot = $this->trashRoot($profile);
        foreach (array_unique(array_column($items, 'scope')) as $scope) {
            $scopeRoot = $trashRoot.DIRECTORY_SEPARATOR.$scope;
            if (is_dir($scopeRoot) && ! is_link($scopeRoot)) {
                @rmdir($scopeRoot);
            }
        }
        if (is_dir($trashRoot) && ! is_link($trashRoot)) {
            @rmdir($trashRoot);
        }
    }

    private function realStorageRoot(WorkspaceProfile $profile): string
    {
        $storageRoot = $this->storageRoot($profile);
        $real = realpath($storageRoot);
        if ($real === false || ! is_dir($real) || is_link($storageRoot)) {
            throw new RuntimeException('recovery_storage_unavailable');
        }

        return $real;
    }

    private function trashRoot(WorkspaceProfile $profile): string
    {
        return $this->storageRoot($profile).DIRECTORY_SEPARATOR.'_trash';
    }

    private function storageRoot(WorkspaceProfile $profile): string
    {
        $storage = strtolower((string) $profile->storage_uuid);
        if (! Str::isUuid($storage)) {
            throw new RuntimeException('invalid_recovery_storage');
        }

        return rtrim((string) config('movie.video_root'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.$storage;
    }

    /** @return array{string, string} */
    private function decodeIdentifier(string $identifier): array
    {
        if ($identifier === '' || strlen($identifier) > 1024 || preg_match('/[^A-Za-z0-9_-]/', $identifier)) {
            throw new RuntimeException('recovery_item_not_found');
        }

        $encoded = strtr($identifier, '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding !== 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($encoded, true);
        if (! is_string($decoded)) {
            throw new RuntimeException('recovery_item_not_found');
        }

        $parts = explode("\0", $decoded);
        if (count($parts) !== 2) {
            throw new RuntimeException('recovery_item_not_found');
        }
        [$scope, $storedName] = $parts;
        if (! $this->isScopeName($scope)
            || $storedName === ''
            || strlen($storedName) > 300
            || basename($storedName) !== $storedName
            || str_contains($storedName, "\0")
            || str_contains($storedName, '/')
            || str_contains($storedName, '\\')) {
            throw new RuntimeException('recovery_item_not_found');
        }

        return [$scope, $storedName];
    }

    private function encodeIdentifier(string $scope, string $storedName): string
    {
        return rtrim(strtr(base64_encode($scope."\0".$storedName), '+/', '-_'), '=');
    }

    private function isScopeName(string $scope): bool
    {
        return $scope === WorkspaceVideoService::LEGACY_SCOPE || Str::isUuid($scope);
    }

    /** @return 'image'|'video'|null */
    private function mediaType(string $name): ?string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            return 'image';
        }
        if (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            return 'video';
        }

        return null;
    }

    private function originalName(string $storedName): string
    {
        if (preg_match('/^\d{14}-[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}-(.+)$/i', $storedName, $matches)) {
            return $matches[1];
        }

        return $storedName;
    }

    private function removedAt(SplFileInfo $file): int
    {
        if (preg_match('/^(\d{14})-/', $file->getFilename(), $matches)) {
            $date = \DateTimeImmutable::createFromFormat('!YmdHis', $matches[1], new \DateTimeZone('UTC'));
            if ($date instanceof \DateTimeImmutable) {
                return $date->getTimestamp();
            }
        }

        return $file->getMTime();
    }
}
