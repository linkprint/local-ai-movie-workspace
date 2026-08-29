<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkspaceProfile;
use App\Models\WorkspaceProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkspaceProjectService
{
    public const SESSION_KEY = 'workspace_project_id';

    public function profileFor(User $user): WorkspaceProfile
    {
        $rootDirectory = $this->rootDirectoryFor($user);
        $profile = WorkspaceProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'storage_uuid' => (string) Str::uuid(),
                'root_directory' => $rootDirectory,
            ],
        );

        if (! is_string($profile->root_directory) || $profile->root_directory === '') {
            $profile->forceFill(['root_directory' => $rootDirectory])->save();
        }
        $this->validateRootDirectory((string) $profile->root_directory);

        return $profile->refresh();
    }

    public function create(User $user, string $name, string $directoryName): WorkspaceProject
    {
        $attributes = $this->validatedAttributes($user, $name, $directoryName);

        return DB::transaction(function () use ($user, $attributes): WorkspaceProject {
            $this->profileFor($user);

            return WorkspaceProject::query()->create([
                'user_id' => $user->id,
                ...$attributes,
            ]);
        });
    }

    /** @return array{name: string, directory_name: string} */
    public function validatedAttributes(
        User $user,
        string $name,
        string $directoryName,
        ?WorkspaceProject $ignore = null,
    ): array {
        $name = trim($name);
        $directoryName = trim($directoryName);

        if ($name === '' || mb_strlen($name) > 80 || preg_match('/[\x00-\x1f\x7f\/\\\\]/u', $name)) {
            throw ValidationException::withMessages([
                'name' => __('ui.errors.project_name_rules'),
            ]);
        }
        if (strlen($directoryName) > 64
            || str_contains($directoryName, '..')
            || ! preg_match('/\A[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?\z/D', $directoryName)) {
            throw ValidationException::withMessages([
                'directory_name' => __('ui.errors.directory_name_rules'),
            ]);
        }

        $duplicate = WorkspaceProject::query()
            ->where('user_id', $user->id)
            ->where('directory_name', $directoryName)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'directory_name' => __('ui.errors.directory_duplicate'),
            ]);
        }

        return ['name' => $name, 'directory_name' => $directoryName];
    }

    public function ownedProject(User $user, string $projectId): ?WorkspaceProject
    {
        return $user->workspaceProjects()->whereKey($projectId)->first();
    }

    private function rootDirectoryFor(User $user): string
    {
        $email = mb_strtolower(trim((string) $user->email));
        $this->validateRootDirectory($email);

        return $email;
    }

    private function validateRootDirectory(string $value): void
    {
        if (strlen($value) > 254
            || ! preg_match('/\A[a-z0-9._%+\-]+@[a-z0-9.-]+\z/D', $value)
            || str_contains($value, '..')) {
            throw ValidationException::withMessages([
                'workspace' => __('ui.errors.unsafe_workspace_root'),
            ]);
        }
    }
}
