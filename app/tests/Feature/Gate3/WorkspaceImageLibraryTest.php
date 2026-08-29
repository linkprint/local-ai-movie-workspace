<?php

namespace Tests\Feature\Gate3;

use App\Models\User;
use App\Models\WorkspaceProfile;
use App\Models\WorkspaceProject;
use App\Services\WorkspaceImageService;
use App\Services\WorkspaceProjectService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WorkspaceImageLibraryTest extends TestCase
{
    use DatabaseMigrations;

    private string $mediaRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mediaRoot = sys_get_temp_dir().'/movie-image-library-'.bin2hex(random_bytes(8));
        mkdir($this->mediaRoot, 0770, true);
        config()->set('movie.workspace_enabled', true);
        config()->set('movie.video_root', $this->mediaRoot);
        config()->set('app.url', 'https://movie.example.com');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->mediaRoot);
        parent::tearDown();
    }

    public function test_library_groups_images_by_owned_project_and_displays_previews(): void
    {
        [$user, $profile, $project] = $this->project('owner@example.com', '七月流火');
        [$other, $otherProfile, $otherProject] = $this->project('other@example.com', 'Other');
        $this->image($profile, $project, 'stills/SH-003.png', 'project-image');
        $this->image($otherProfile, $otherProject, 'private.png', 'other-image');

        $response = $this->actingAs($user)->get(route('workspace.images.index'));

        $response->assertOk()
            ->assertSee('Image library')
            ->assertSee('七月流火')
            ->assertSee('SH-003.png')
            ->assertSee('<img', false)
            ->assertSee('Open in new tab')
            ->assertSee('target="_blank"', false)
            ->assertSee('Download')
            ->assertSee('data-media-library', false)
            ->assertSee('data-media-async-trash', false)
            ->assertSee('data-media-card', false)
            ->assertSee('data-media-delete-form', false)
            ->assertSee('data-media-select-all', false)
            ->assertSee(route('workspace.images.bulk-trash'))
            ->assertDontSee('private.png')
            ->assertDontSee('Other');
    }

    public function test_image_opens_inline_and_supports_explicit_download(): void
    {
        [$user, $profile, $project] = $this->project('owner@example.com', 'Movie');
        $this->image($profile, $project, 'still.png', 'png-image-bytes');
        $url = route('workspace.images.show', ['project' => $project, 'image' => 'still.png']);

        $this->actingAs($user)->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename=still.png')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $this->actingAs($user)->get($url.'?download=1')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=still.png');
    }

    public function test_other_users_and_symlink_or_traversal_paths_cannot_be_read(): void
    {
        [$owner, $profile, $project] = $this->project('owner@example.com', 'Movie');
        [$other] = $this->project('other@example.com', 'Other');
        $this->image($profile, $project, 'private.png', 'secret');
        $scope = $this->scope($profile, $project);
        file_put_contents($this->mediaRoot.'/outside.png', 'outside');
        symlink($this->mediaRoot.'/outside.png', $scope.'/linked.png');

        $base = '/workspace/projects/'.$project->id.'/images/';
        $this->actingAs($other)->get($base.'private.png')->assertNotFound();
        $this->actingAs($owner)->get($base.'linked.png')->assertNotFound();
        $this->actingAs($owner)->get($base.'../outside.png')->assertNotFound();
        $this->actingAs($owner)->get($base.'%2e%2e/outside.png')->assertNotFound();
    }

    public function test_owner_can_rename_without_overwrite_and_delete_to_recovery_trash(): void
    {
        [$user, $profile, $project] = $this->project('owner@example.com', 'Movie');
        $this->image($profile, $project, 'still.png', 'still');
        $this->image($profile, $project, 'existing.png', 'existing');
        $url = route('workspace.images.update', ['project' => $project, 'image' => 'still.png']);

        $this->actingAs($user)->patch($url, ['new_name' => 'existing.png'])
            ->assertSessionHasErrors('new_name');
        $this->assertFileExists($this->scope($profile, $project).'/still.png');
        $this->assertSame('existing', file_get_contents($this->scope($profile, $project).'/existing.png'));

        $this->actingAs($user)->patch($url, ['new_name' => 'final-still.webp'])
            ->assertRedirect(route('workspace.images.index'));
        $this->assertFileDoesNotExist($this->scope($profile, $project).'/still.png');
        $this->assertFileExists($this->scope($profile, $project).'/final-still.webp');

        $deleteUrl = route('workspace.images.destroy', ['project' => $project, 'image' => 'final-still.webp']);
        $this->actingAs($user)->delete($deleteUrl, ['delete_confirmation' => 'DELETE'])
            ->assertSessionHasErrors('delete_confirmation');
        $this->assertFileExists($this->scope($profile, $project).'/final-still.webp');

        $this->actingAs($user)->delete($deleteUrl, ['delete_confirmation' => 'delete'])
            ->assertRedirect(route('workspace.images.index'));
        $this->assertFileDoesNotExist($this->scope($profile, $project).'/final-still.webp');
        $trashed = glob($this->mediaRoot.'/'.$profile->storage_uuid.'/_trash/'.$project->id.'/*-final-still.webp');
        $this->assertCount(1, $trashed ?: []);
        $this->assertSame('still', file_get_contents($trashed[0]));
    }

    public function test_owner_can_batch_move_project_and_legacy_images_to_private_recovery_only_after_full_preflight(): void
    {
        [$user, $profile, $project] = $this->project('owner@example.com', 'Movie');
        [$other, $otherProfile, $otherProject] = $this->project('other@example.com', 'Other');
        $this->image($profile, $project, 'stills/first.png', 'first');
        $this->legacyImage($profile, 'legacy.webp', 'legacy');
        $this->image($otherProfile, $otherProject, 'private.png', 'other');
        $service = app(WorkspaceImageService::class);
        $projectId = $service->selectionIdentifier($project, 'stills/first.png');
        $legacyId = $service->selectionIdentifier(WorkspaceImageService::LEGACY_SCOPE, 'legacy.webp');
        $otherId = $service->selectionIdentifier($otherProject, 'private.png');
        $url = route('workspace.images.bulk-trash');

        $this->actingAs($user)->post($url, [
            'items' => [$projectId, $legacyId],
            'bulk_confirmation' => 'move',
        ])->assertSessionHasErrors('bulk_confirmation');
        $this->assertFileExists($this->scope($profile, $project).'/stills/first.png');
        $this->assertFileExists($this->legacyScope($profile).'/legacy.webp');

        $this->actingAs($user)->post($url, [
            'items' => [$projectId, $otherId],
            'bulk_confirmation' => 'move to recovery',
        ])->assertNotFound();
        $this->assertFileExists($this->scope($profile, $project).'/stills/first.png');
        $this->assertFileExists($this->scope($otherProfile, $otherProject).'/private.png');

        $this->actingAs($user)->post($url, [
            'items' => [$projectId, $legacyId],
            'bulk_confirmation' => 'move to recovery',
        ])->assertRedirect(route('workspace.images.index'))
            ->assertSessionHas('status', '2 images moved to private recovery trash.');

        $this->assertFileDoesNotExist($this->scope($profile, $project).'/stills/first.png');
        $this->assertFileDoesNotExist($this->legacyScope($profile).'/legacy.webp');
        $this->assertFileExists($this->scope($otherProfile, $otherProject).'/private.png');
        $projectTrash = glob($this->mediaRoot.'/'.$profile->storage_uuid.'/_trash/'.$project->id.'/*-first.png');
        $legacyTrash = glob($this->mediaRoot.'/'.$profile->storage_uuid.'/_trash/_legacy/*-legacy.webp');
        $this->assertCount(1, $projectTrash ?: []);
        $this->assertCount(1, $legacyTrash ?: []);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $user->id,
            'action' => 'workspace.images.trashed_batch',
            'target_id' => $profile->id,
        ]);
    }

    public function test_ajax_delete_and_bulk_trash_return_json_without_redirecting(): void
    {
        [$user, $profile, $project] = $this->project('owner@example.com', 'Movie');
        $this->image($profile, $project, 'single.png', 'single');
        $this->image($profile, $project, 'first.png', 'first');
        $this->image($profile, $project, 'second.webp', 'second');
        $service = app(WorkspaceImageService::class);

        $this->actingAs($user)->deleteJson(
            route('workspace.images.destroy', ['project' => $project, 'image' => 'single.png']),
            ['delete_confirmation' => 'delete'],
        )->assertOk()->assertExactJson([
            'message' => 'Image moved to private recovery trash.',
        ]);

        $this->actingAs($user)->postJson(route('workspace.images.bulk-trash'), [
            'items' => [
                $service->selectionIdentifier($project, 'first.png'),
                $service->selectionIdentifier($project, 'second.webp'),
            ],
            'bulk_confirmation' => 'move to recovery',
        ])->assertOk()->assertExactJson([
            'message' => '2 images moved to private recovery trash.',
            'count' => 2,
        ]);

        $this->assertFileDoesNotExist($this->scope($profile, $project).'/single.png');
        $this->assertFileDoesNotExist($this->scope($profile, $project).'/first.png');
        $this->assertFileDoesNotExist($this->scope($profile, $project).'/second.webp');
    }

    /** @return array{User, WorkspaceProfile, WorkspaceProject} */
    private function project(string $email, string $name): array
    {
        $user = User::factory()->create(['email' => $email]);
        $service = app(WorkspaceProjectService::class);
        $project = $service->create($user, $name, 'project-'.substr(md5($email), 0, 10));
        $profile = $service->profileFor($user);

        return [$user, $profile, $project];
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

    private function legacyImage(WorkspaceProfile $profile, string $name, string $contents): void
    {
        $path = $this->legacyScope($profile).'/'.$name;
        file_put_contents($path, $contents);
        chmod($path, 0660);
    }

    private function legacyScope(WorkspaceProfile $profile): string
    {
        $path = $this->mediaRoot.'/'.$profile->storage_uuid.'/_legacy';
        if (! is_dir($path)) {
            mkdir($path, 0770, true);
        }

        return $path;
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
