<?php

namespace Tests\Feature\Gate3;

use App\Models\User;
use App\Models\WorkspaceProfile;
use App\Models\WorkspaceProject;
use App\Services\WorkspaceProjectService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WorkspaceImageLinkTest extends TestCase
{
    use DatabaseMigrations;

    private string $mediaRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mediaRoot = sys_get_temp_dir().'/movie-image-links-'.bin2hex(random_bytes(8));
        mkdir($this->mediaRoot, 0770, true);
        config()->set('movie.video_root', $this->mediaRoot);
        config()->set('app.url', 'https://movie.example.com');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->mediaRoot);
        parent::tearDown();
    }

    public function test_owner_can_open_a_published_image_inline(): void
    {
        [$user, $profile, $project] = $this->project('owner@example.com');
        $this->image($profile, $project, 'stills/角色 1.png', 'png-bytes');
        $url = route('workspace.images.show', ['project' => $project, 'image' => 'stills/角色 1.png']);

        $this->actingAs($user)->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', "inline; filename=1.png; filename*=utf-8''%E8%A7%92%E8%89%B2%201.png")
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_other_users_traversal_symlinks_and_unsupported_files_are_rejected(): void
    {
        [$owner, $profile, $project] = $this->project('owner@example.com');
        [$other] = $this->project('other@example.com');
        $this->image($profile, $project, 'private.webp', 'private');
        $scope = $this->scope($profile, $project);
        file_put_contents($this->mediaRoot.'/outside.png', 'outside');
        symlink($this->mediaRoot.'/outside.png', $scope.'/linked.png');
        file_put_contents($scope.'/payload.svg', '<svg/>');
        $base = '/workspace/projects/'.$project->id.'/images/';

        $this->actingAs($other)->get($base.'private.webp')->assertNotFound();
        $this->actingAs($owner)->get($base.'linked.png')->assertNotFound();
        $this->actingAs($owner)->get($base.'../outside.png')->assertNotFound();
        $this->actingAs($owner)->get($base.'%2e%2e/outside.png')->assertNotFound();
        $this->actingAs($owner)->get($base.'payload.svg')->assertNotFound();
    }

    /** @return array{User, WorkspaceProfile, WorkspaceProject} */
    private function project(string $email): array
    {
        $user = User::factory()->create(['email' => $email]);
        $service = app(WorkspaceProjectService::class);
        $project = $service->create($user, 'Project', 'project-'.substr(md5($email), 0, 10));

        return [$user, $service->profileFor($user), $project];
    }

    private function image(
        WorkspaceProfile $profile,
        WorkspaceProject $project,
        string $relative,
        string $contents,
    ): void {
        $path = $this->scope($profile, $project).'/'.$relative;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0770, true);
        }
        file_put_contents($path, $contents);
        chmod($path, 0660);
    }

    private function scope(WorkspaceProfile $profile, WorkspaceProject $project): string
    {
        $path = $this->mediaRoot.'/'.$profile->storage_uuid.'/'.$project->id;
        if (! is_dir($path)) {
            mkdir($path, 0770, true);
        }

        return $path;
    }
}
