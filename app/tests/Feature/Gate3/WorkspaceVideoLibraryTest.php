<?php

namespace Tests\Feature\Gate3;

use App\Models\User;
use App\Models\WorkspaceProfile;
use App\Models\WorkspaceProject;
use App\Services\WorkspaceProjectService;
use App\Services\WorkspaceVideoService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WorkspaceVideoLibraryTest extends TestCase
{
    use DatabaseMigrations;

    private string $videoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->videoRoot = sys_get_temp_dir().'/movie-video-library-'.bin2hex(random_bytes(8));
        mkdir($this->videoRoot, 0770, true);
        config()->set('movie.workspace_enabled', true);
        config()->set('movie.video_root', $this->videoRoot);
        config()->set('app.url', 'https://movie.example.com');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->videoRoot);
        parent::tearDown();
    }

    public function test_library_groups_videos_by_owned_project_and_opens_them_in_a_new_tab(): void
    {
        [$user, $profile, $project] = $this->project('owner@example.com', '七月流火');
        [$other, $otherProfile, $otherProject] = $this->project('other@example.com', 'Other');
        $this->video($profile, $project, 'shots/SH-003.mp4', 'project-video');
        $this->video($otherProfile, $otherProject, 'private.mp4', 'other-video');

        $response = $this->actingAs($user)->get(route('workspace.videos.index'));

        $response->assertOk()
            ->assertSee('Video library')
            ->assertSee('Private recovery area')
            ->assertSee('Select all videos')
            ->assertSee('0 videos selected')
            ->assertSee('Move selected to recovery')
            ->assertSee('Select SH-003.mp4')
            ->assertSee('七月流火')
            ->assertSee('SH-003.mp4')
            ->assertSee('Open in new tab')
            ->assertSee('target="_blank"', false)
            ->assertSee('Download')
            ->assertSee('data-media-library', false)
            ->assertSee('data-media-select-all', false)
            ->assertSee(route('workspace.videos.bulk-trash'))
            ->assertDontSee('ui.videos.')
            ->assertDontSee('private.mp4')
            ->assertDontSee('Other');
    }

    public function test_video_library_translation_keys_resolve_in_both_supported_locales(): void
    {
        $keys = [
            'open_recovery',
            'select_all',
            'selected_count',
            'select_media',
            'bulk_trash',
            'bulk_trash_confirm',
        ];

        foreach (['en', 'zh_CN'] as $locale) {
            app()->setLocale($locale);
            foreach ($keys as $key) {
                $translationKey = 'ui.videos.'.$key;
                $this->assertNotSame($translationKey, __($translationKey, [
                    'count' => 0,
                    'name' => 'clip.mp4',
                ]), $locale.' is missing '.$translationKey);
            }
        }
    }

    public function test_video_stream_supports_inline_range_playback_and_explicit_download(): void
    {
        [$user, $profile, $project] = $this->project('owner@example.com', 'Movie');
        $this->video($profile, $project, 'clip.mp4', '0123456789');
        $url = route('workspace.videos.show', ['project' => $project, 'video' => 'clip.mp4']);

        $this->actingAs($user)->withHeader('Range', 'bytes=2-5')->get($url)
            ->assertStatus(206)
            ->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Content-Range', 'bytes 2-5/10')
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Disposition', 'inline; filename=clip.mp4');

        $this->withHeader('Range', '')->actingAs($user)->get($url.'?download=1')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=clip.mp4');
    }

    public function test_other_users_and_symlink_or_traversal_paths_cannot_be_read(): void
    {
        [$owner, $profile, $project] = $this->project('owner@example.com', 'Movie');
        [$other] = $this->project('other@example.com', 'Other');
        $this->video($profile, $project, 'private.mp4', 'secret');
        $scope = $this->scope($profile, $project);
        file_put_contents($this->videoRoot.'/outside.mp4', 'outside');
        symlink($this->videoRoot.'/outside.mp4', $scope.'/linked.mp4');

        $base = '/workspace/projects/'.$project->id.'/videos/';
        $this->actingAs($other)->get($base.'private.mp4')->assertNotFound();
        $this->actingAs($owner)->get($base.'linked.mp4')->assertNotFound();
        $this->actingAs($owner)->get($base.'../outside.mp4')->assertNotFound();
        $this->actingAs($owner)->get($base.'%2e%2e/outside.mp4')->assertNotFound();
    }

    public function test_owner_can_rename_without_overwrite_and_delete_to_recovery_trash(): void
    {
        [$user, $profile, $project] = $this->project('owner@example.com', 'Movie');
        $this->video($profile, $project, 'clip.mp4', 'clip');
        $this->video($profile, $project, 'existing.mp4', 'existing');
        $url = route('workspace.videos.update', ['project' => $project, 'video' => 'clip.mp4']);

        $this->actingAs($user)->patch($url, ['new_name' => 'existing.mp4'])
            ->assertSessionHasErrors('new_name');
        $this->assertFileExists($this->scope($profile, $project).'/clip.mp4');
        $this->assertSame('existing', file_get_contents($this->scope($profile, $project).'/existing.mp4'));

        $this->actingAs($user)->patch($url, ['new_name' => 'final-cut.mp4'])
            ->assertRedirect(route('workspace.videos.index'));
        $this->assertFileDoesNotExist($this->scope($profile, $project).'/clip.mp4');
        $this->assertFileExists($this->scope($profile, $project).'/final-cut.mp4');

        $deleteUrl = route('workspace.videos.destroy', ['project' => $project, 'video' => 'final-cut.mp4']);
        $this->actingAs($user)->delete($deleteUrl, ['delete_confirmation' => 'DELETE'])
            ->assertSessionHasErrors('delete_confirmation');
        $this->assertFileExists($this->scope($profile, $project).'/final-cut.mp4');

        $this->actingAs($user)->delete($deleteUrl, ['delete_confirmation' => 'delete'])
            ->assertRedirect(route('workspace.videos.index'));
        $this->assertFileDoesNotExist($this->scope($profile, $project).'/final-cut.mp4');
        $trashed = glob($this->videoRoot.'/'.$profile->storage_uuid.'/_trash/'.$project->id.'/*-final-cut.mp4');
        $this->assertCount(1, $trashed ?: []);
        $this->assertSame('clip', file_get_contents($trashed[0]));
    }

    public function test_owner_can_batch_move_project_and_legacy_videos_to_private_recovery_only_after_full_preflight(): void
    {
        [$user, $profile, $project] = $this->project('owner@example.com', 'Movie');
        [$other, $otherProfile, $otherProject] = $this->project('other@example.com', 'Other');
        $this->video($profile, $project, 'shots/first.mp4', 'first');
        $this->legacyVideo($profile, 'legacy.webm', 'legacy');
        $this->video($otherProfile, $otherProject, 'private.mp4', 'other');
        $service = app(WorkspaceVideoService::class);
        $projectId = $service->selectionIdentifier($project, 'shots/first.mp4');
        $legacyId = $service->selectionIdentifier(WorkspaceVideoService::LEGACY_SCOPE, 'legacy.webm');
        $otherId = $service->selectionIdentifier($otherProject, 'private.mp4');
        $url = route('workspace.videos.bulk-trash');

        $this->actingAs($user)->post($url, [
            'items' => [$projectId, $legacyId],
            'bulk_confirmation' => 'move',
        ])->assertSessionHasErrors('bulk_confirmation');
        $this->assertFileExists($this->scope($profile, $project).'/shots/first.mp4');
        $this->assertFileExists($this->legacyScope($profile).'/legacy.webm');

        $this->actingAs($user)->post($url, [
            'items' => [$projectId, $otherId],
            'bulk_confirmation' => 'move to recovery',
        ])->assertNotFound();
        $this->assertFileExists($this->scope($profile, $project).'/shots/first.mp4');
        $this->assertFileExists($this->scope($otherProfile, $otherProject).'/private.mp4');

        $this->actingAs($user)->post($url, [
            'items' => [$projectId, $legacyId],
            'bulk_confirmation' => 'move to recovery',
        ])->assertRedirect(route('workspace.videos.index'))
            ->assertSessionHas('status', '2 videos moved to private recovery trash.');

        $this->assertFileDoesNotExist($this->scope($profile, $project).'/shots/first.mp4');
        $this->assertFileDoesNotExist($this->legacyScope($profile).'/legacy.webm');
        $this->assertFileExists($this->scope($otherProfile, $otherProject).'/private.mp4');
        $projectTrash = glob($this->videoRoot.'/'.$profile->storage_uuid.'/_trash/'.$project->id.'/*-first.mp4');
        $legacyTrash = glob($this->videoRoot.'/'.$profile->storage_uuid.'/_trash/_legacy/*-legacy.webm');
        $this->assertCount(1, $projectTrash ?: []);
        $this->assertCount(1, $legacyTrash ?: []);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $user->id,
            'action' => 'workspace.videos.trashed_batch',
            'target_id' => $profile->id,
        ]);
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

    private function video(
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

    private function legacyVideo(WorkspaceProfile $profile, string $name, string $contents): void
    {
        $path = $this->legacyScope($profile).'/'.$name;
        file_put_contents($path, $contents);
        chmod($path, 0660);
    }

    private function legacyScope(WorkspaceProfile $profile): string
    {
        $path = $this->videoRoot.'/'.$profile->storage_uuid.'/_legacy';
        if (! is_dir($path)) {
            mkdir($path, 0770, true);
        }

        return $path;
    }

    private function scope(WorkspaceProfile $profile, WorkspaceProject $project): string
    {
        $path = $this->videoRoot.'/'.$profile->storage_uuid.'/'.$project->id;
        if (! is_dir($path)) {
            mkdir($path, 0770, true);
        }

        return $path;
    }
}
