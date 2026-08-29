<?php

namespace Tests\Feature\Gate3;

use App\Models\User;
use App\Models\WorkspaceProfile;
use App\Models\WorkspaceProject;
use App\Services\WorkspaceMediaRecoveryService;
use App\Services\WorkspaceProjectService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WorkspaceMediaRecoveryTest extends TestCase
{
    use DatabaseMigrations;

    private string $mediaRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mediaRoot = sys_get_temp_dir().'/movie-media-recovery-'.bin2hex(random_bytes(8));
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

    public function test_image_and_video_libraries_link_to_an_owner_isolated_recovery_area_with_private_previews(): void
    {
        [$owner, $profile, $project] = $this->project('owner@example.com', 'Owner Project');
        [$other, $otherProfile, $otherProject] = $this->project('other@example.com', 'Other Project');
        $this->trash($profile, $project->id, '20260826010203-11111111-1111-4111-8111-111111111111-owner-still.png', 'owner-image');
        $this->trash($profile, $project->id, '20260826010204-22222222-2222-4222-8222-222222222222-owner-clip.mp4', 'owner-video');
        $this->trash($otherProfile, $otherProject->id, '20260826010205-33333333-3333-4333-8333-333333333333-other.png', 'other-image');

        $this->actingAs($owner)->get(route('workspace.images.index'))
            ->assertOk()
            ->assertSee(route('workspace.recovery.index'))
            ->assertSee('Private recovery area');
        $this->actingAs($owner)->get(route('workspace.videos.index'))
            ->assertOk()
            ->assertSee(route('workspace.recovery.index'))
            ->assertSee('Private recovery area');

        $response = $this->actingAs($owner)->get(route('workspace.recovery.index'));
        $response->assertOk()
            ->assertSee('Owner Project')
            ->assertSee('owner-still.png')
            ->assertSee('owner-clip.mp4')
            ->assertSee('Permanently delete selected')
            ->assertSee('Restore selected')
            ->assertSee('data-recovery-purge-confirmation', false)
            ->assertSee('data-recovery-purge-action', false)
            ->assertDontSee('Other Project')
            ->assertDontSee('other.png');

        $ownerItems = app(WorkspaceMediaRecoveryService::class)->itemsFor($profile);
        $image = collect($ownerItems)->firstWhere('type', 'image');
        $this->actingAs($owner)->get($image['preview_url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        $this->actingAs($other)->get($image['preview_url'])->assertNotFound();
    }

    public function test_owner_can_batch_restore_image_and_video_without_overwriting_existing_media(): void
    {
        [$owner, $profile, $project] = $this->project('owner@example.com', 'Movie');
        $this->trash($profile, $project->id, '20260826010203-11111111-1111-4111-8111-111111111111-still.png', 'still');
        $this->trash($profile, $project->id, '20260826010204-22222222-2222-4222-8222-222222222222-clip.mp4', 'clip');
        $items = app(WorkspaceMediaRecoveryService::class)->itemsFor($profile);
        $scope = $this->scope($profile, $project->id);
        mkdir($scope, 0770, true);
        chmod($scope, 0750);

        $this->actingAs($owner)->post(route('workspace.recovery.update'), [
            'action' => 'restore',
            'items' => array_column($items, 'id'),
        ])->assertRedirect(route('workspace.recovery.index'))
            ->assertSessionHas('status', '2 media files restored.');

        $this->assertSame('still', file_get_contents($scope.'/still.png'));
        $this->assertSame('clip', file_get_contents($scope.'/clip.mp4'));
        $this->assertSame(0750, fileperms($scope) & 0777);
        $this->assertSame([], app(WorkspaceMediaRecoveryService::class)->itemsFor($profile));
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $owner->id,
            'action' => 'workspace.media_recovery.restored',
            'target_id' => $profile->id,
        ]);

        $this->trash($profile, $project->id, '20260826010205-33333333-3333-4333-8333-333333333333-still.png', 'replacement');
        $this->trash($profile, $project->id, '20260826010206-44444444-4444-4444-8444-444444444444-new-clip.mp4', 'new-clip');
        $items = app(WorkspaceMediaRecoveryService::class)->itemsFor($profile);
        $this->actingAs($owner)->from(route('workspace.recovery.index'))->post(route('workspace.recovery.update'), [
            'action' => 'restore',
            'items' => array_column($items, 'id'),
        ])->assertRedirect(route('workspace.recovery.index'))
            ->assertSessionHasErrors('items');

        $this->assertSame('still', file_get_contents($scope.'/still.png'));
        $this->assertFileDoesNotExist($scope.'/new-clip.mp4');
        $this->assertCount(2, app(WorkspaceMediaRecoveryService::class)->itemsFor($profile));
    }

    public function test_owner_can_batch_permanently_delete_selected_media_only_after_exact_confirmation(): void
    {
        [$owner, $profile, $project] = $this->project('owner@example.com', 'Movie');
        [$other, $otherProfile, $otherProject] = $this->project('other@example.com', 'Other');
        $first = $this->trash($profile, $project->id, '20260826010203-11111111-1111-4111-8111-111111111111-still.png', 'still');
        $second = $this->trash($profile, $project->id, '20260826010204-22222222-2222-4222-8222-222222222222-clip.mp4', 'clip');
        $otherPath = $this->trash($otherProfile, $otherProject->id, '20260826010205-33333333-3333-4333-8333-333333333333-other.png', 'other');
        $items = app(WorkspaceMediaRecoveryService::class)->itemsFor($profile);

        $this->actingAs($owner)->from(route('workspace.recovery.index'))->post(route('workspace.recovery.update'), [
            'action' => 'purge',
            'items' => array_column($items, 'id'),
            'purge_confirmation' => 'DELETE',
        ])->assertRedirect(route('workspace.recovery.index'))
            ->assertSessionHasErrors('purge_confirmation');
        $this->assertFileExists($first);
        $this->assertFileExists($second);

        $this->actingAs($owner)->post(route('workspace.recovery.update'), [
            'action' => 'purge',
            'items' => array_column($items, 'id'),
            'purge_confirmation' => 'delete',
        ])->assertRedirect(route('workspace.recovery.index'))
            ->assertSessionHas('status', '2 media files permanently deleted.');

        $this->assertFileDoesNotExist($first);
        $this->assertFileDoesNotExist($second);
        $this->assertFileExists($otherPath);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $owner->id,
            'action' => 'workspace.media_recovery.purged',
            'target_id' => $profile->id,
        ]);
    }

    public function test_tampered_identifiers_symlinks_and_other_users_media_are_rejected(): void
    {
        [$owner, $profile, $project] = $this->project('owner@example.com', 'Movie');
        [$other, $otherProfile, $otherProject] = $this->project('other@example.com', 'Other');
        $this->trash($otherProfile, $otherProject->id, '20260826010205-33333333-3333-4333-8333-333333333333-other.png', 'other');
        $otherItem = app(WorkspaceMediaRecoveryService::class)->itemsFor($otherProfile)[0];

        $scope = $this->trashScope($profile, $project->id);
        $outside = $this->mediaRoot.'/outside.png';
        file_put_contents($outside, 'outside');
        symlink($outside, $scope.'/20260826010203-11111111-1111-4111-8111-111111111111-linked.png');

        $this->assertSame([], app(WorkspaceMediaRecoveryService::class)->itemsFor($profile));
        $this->actingAs($owner)->get($otherItem['preview_url'])->assertNotFound();
        $this->actingAs($owner)->post(route('workspace.recovery.update'), [
            'action' => 'purge',
            'items' => ['not-a-valid-item'],
            'purge_confirmation' => 'delete',
        ])->assertNotFound();
        $this->assertFileExists($outside);
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

    private function trash(WorkspaceProfile $profile, string $scope, string $name, string $contents): string
    {
        $path = $this->trashScope($profile, $scope).'/'.$name;
        file_put_contents($path, $contents);
        chmod($path, 0660);

        return $path;
    }

    private function trashScope(WorkspaceProfile $profile, string $scope): string
    {
        $path = $this->mediaRoot.'/'.$profile->storage_uuid.'/_trash/'.$scope;
        if (! is_dir($path)) {
            mkdir($path, 0770, true);
        }

        return $path;
    }

    private function scope(WorkspaceProfile $profile, string $scope): string
    {
        return $this->mediaRoot.'/'.$profile->storage_uuid.'/'.$scope;
    }
}
