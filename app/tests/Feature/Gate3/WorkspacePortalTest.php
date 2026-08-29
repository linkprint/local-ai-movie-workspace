<?php

namespace Tests\Feature\Gate3;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WorkspaceProfile;
use App\Models\WorkspaceProject;
use App\Models\WorkspaceRuntime;
use App\Services\MockBrokerControlClient;
use App\Services\TerminalRouteClaimService;
use App\Services\WorkspaceManagerClient;
use App\Services\WorkspaceProjectService;
use App\Services\WorkspaceRuntimeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class WorkspacePortalTest extends TestCase
{
    use DatabaseMigrations;

    private string $mediaRoot;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('movie.workspace_enabled', true);
        config()->set('movie.company_codex_enabled', true);
        $this->mediaRoot = sys_get_temp_dir().'/movie-workspace-upload-'.bin2hex(random_bytes(8));
        mkdir($this->mediaRoot, 0770, true);
        config()->set('movie.video_root', $this->mediaRoot);
        CarbonImmutable::setTestNow('2026-08-24T17:00:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        File::deleteDirectory($this->mediaRoot);
        parent::tearDown();
    }

    public function test_first_workspace_visit_requires_project_creation_before_terminal_entry(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);

        $this->actingAs($user)->get('/workspace')
            ->assertOk()
            ->assertSee('Choose a project folder')
            ->assertSee('Create your first project to continue')
            ->assertSee('Directory name')
            ->assertSee('admin@example.com')
            ->assertDontSee('Your projects')
            ->assertDontSee('workspace-terminal-window');

        $this->actingAs($user)->get('/workspace/terminal')
            ->assertRedirect(route('workspace'));
    }

    public function test_project_creation_selects_a_safe_folder_under_the_immutable_email_root(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);

        $this->actingAs($user)->post('/workspace/projects', [
            'name' => '七月流火',
            'directory_name' => 'qi-yue-liu-huo',
        ])
            ->assertRedirect(route('workspace.terminal'));

        $profile = $user->workspaceProfile()->firstOrFail();
        $project = $user->workspaceProjects()->firstOrFail();
        $this->assertSame('admin@example.com', $profile->root_directory);
        $this->assertSame($project->id, $profile->selected_project_id);
        $this->assertSame('七月流火', $project->name);
        $this->assertSame('qi-yue-liu-huo', $project->directory_name);
        $this->assertSame($project->id, session(WorkspaceProjectService::SESSION_KEY));

        $this->actingAs($user)->withSession([
            WorkspaceProjectService::SESSION_KEY => $project->id,
        ])->get('/workspace/terminal')
            ->assertOk()
            ->assertSee('Isolated Codex terminal')
            ->assertSee('/workspace/'.$project->directory_name)
            ->assertSee('No active reservation window');
    }

    public function test_future_reservation_shows_a_server_timed_local_ai_countdown(): void
    {
        $user = User::factory()->create();
        $reservation = $this->reservation(
            $user,
            ReservationStatus::Confirmed,
            startsAt: now()->addDays(2)->addHours(3)->addMinutes(18)->addSeconds(42),
        );
        $project = $this->project($user);

        $this->actingAs($user)->withSession([
            ...$this->entrySession($project),
        ])->get(route('workspace.terminal', ['entry' => 'test-entry']))
            ->assertOk()
            ->assertSee('data-local-ai-countdown', false)
            ->assertSee('data-status-url="'.route('workspace.runtime-status').'"', false)
            ->assertSee('data-starts-at="'.$reservation->starts_at->utc()->toIso8601String().'"', false)
            ->assertSee('data-server-now="'.now()->utc()->toIso8601String().'"', false)
            ->assertSee('data-phase="countdown"', false)
            ->assertSee('border-red-400/60', false)
            ->assertSee('Local AI starts in --:--:--');
    }

    public function test_no_reservation_page_offers_direct_workspace_entry_for_the_selected_codex_account(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $this->actingAs($user)->withSession([
            ...$this->entrySession($project),
        ])->get(route('workspace.terminal', ['entry' => 'test-entry']))
            ->assertOk()
            ->assertSee('No active reservation window')
            ->assertSee('Book a time')
            ->assertSee('Enter workspace without reservation')
            ->assertSee('action="'.route('workspace.auth-mode').'"', false)
            ->assertSee('name="auth_mode" value="personal"', false)
            ->assertSee('data-testid="enter-workspace-without-reservation"', false);

        $this->actingAs($user)->withSession([
            ...$this->entrySession($project),
            'locale' => 'zh_CN',
        ])->get(route('workspace.terminal', ['entry' => 'test-entry']))
            ->assertOk()
            ->assertSee('不预约直接进入工作区');
    }

    public function test_runtime_status_uses_server_time_for_a_future_reservation(): void
    {
        $user = User::factory()->create();
        $reservation = $this->reservation($user, ReservationStatus::Confirmed, startsAt: now()->addHour());
        $project = $this->project($user);

        $this->actingAs($user)->withSession([
            WorkspaceProjectService::SESSION_KEY => $project->id,
        ])->getJson(route('workspace.runtime-status'))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('phase', 'countdown')
            ->assertJsonPath('local_ai_enabled', false)
            ->assertJsonPath('starts_at', $reservation->starts_at->utc()->toIso8601String())
            ->assertJsonPath('server_now', now()->utc()->toIso8601String());
    }

    public function test_runtime_status_turns_ready_only_for_the_healthy_owned_runtime(): void
    {
        $user = User::factory()->create();
        $reservation = $this->reservation($user, ReservationStatus::Active, startsAt: now()->subMinute());
        $project = $this->project($user);
        $runtime = $this->runningRuntime($user, $project);
        $runtime->forceFill(['idle_expires_at' => $reservation->ends_at->addMinutes(10)])->save();
        $reservation = $this->bindLocalAi($reservation, $runtime);

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->once()->with(Mockery::on(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime),
        ))->andReturn($this->runtimeStatus($runtime, $project, ['ai_network_connected' => true]));
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->withSession([
            WorkspaceProjectService::SESSION_KEY => $project->id,
        ])->getJson(route('workspace.runtime-status'))
            ->assertOk()
            ->assertJsonPath('phase', 'ready')
            ->assertJsonPath('local_ai_enabled', true)
            ->assertJsonPath('starts_at', $reservation->starts_at->utc()->toIso8601String());
    }

    public function test_project_directory_name_is_explicit_strict_and_unique_per_user(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        foreach (['七月流火', 'Movie', '.hidden', 'trailing-', 'two..dots', 'movie/other'] as $invalid) {
            $this->actingAs($user)->post('/workspace/projects', [
                'name' => '七月流火',
                'directory_name' => $invalid,
            ])->assertSessionHasErrors('directory_name');
        }
        $this->assertSame(0, $user->workspaceProjects()->count());

        $this->actingAs($user)->post('/workspace/projects', [
            'name' => '七月流火',
            'directory_name' => 'qiyueliuhuo',
        ])->assertRedirect(route('workspace.terminal'));
        $this->actingAs($user)->post('/workspace/projects', [
            'name' => 'Another display name',
            'directory_name' => 'qiyueliuhuo',
        ])->assertSessionHasErrors('directory_name');
        $this->assertSame(1, $user->workspaceProjects()->count());
    }

    public function test_stopped_project_can_atomically_change_its_display_and_directory_names(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $project = $this->project($user, 'project-2');

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('renameProjectDirectory')->once()->withArgs(fn (
            WorkspaceProfile $profile,
            string $oldDirectory,
            string $newDirectory,
        ): bool => $profile->user_id === $user->id
            && $oldDirectory === 'project-2'
            && $newDirectory === 'qi-yue-liu-huo');
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->put(route('workspace.projects.update', $project), [
            'name' => '七月流火（电影）',
            'directory_name' => 'qi-yue-liu-huo',
        ])->assertRedirect(route('workspace'));

        $project->refresh();
        $this->assertSame('七月流火（电影）', $project->name);
        $this->assertSame('qi-yue-liu-huo', $project->directory_name);
    }

    public function test_active_project_directory_change_fails_without_calling_manager(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $project = $this->project($user, 'project-2');
        $this->runningRuntime($user, $project);

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldNotReceive('renameProjectDirectory');
        $manager->shouldNotReceive('trashProjectDirectory');
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->get(route('workspace'))
            ->assertOk()
            ->assertSee('Workspace is active in /workspace/project-2')
            ->assertSee('Directory rename is locked while the Workspace is active')
            ->assertSee('Project deletion is locked while the Workspace is active')
            ->assertSee('Stop workspace')
            ->assertSee('Confirm stop workspace')
            ->assertSee('readonly', false);

        $this->actingAs($user)->put(route('workspace.projects.update', $project), [
            'name' => '七月流火',
            'directory_name' => 'qi-yue-liu-huo',
        ])->assertSessionHasErrors('directory_name');

        $this->assertSame('project-2', $project->refresh()->directory_name);

        $this->actingAs($user)->delete(route('workspace.projects.destroy', $project), [
            'delete_confirmation' => 'delete',
        ])->assertSessionHasErrors('delete_confirmation');
        $this->assertDatabaseHas('workspace_projects', ['id' => $project->id]);
    }

    public function test_project_deletion_requires_exact_lowercase_delete_confirmation(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $project = $this->project($user, 'movie-project');

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldNotReceive('trashProjectDirectory');
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->delete(route('workspace.projects.destroy', $project), [
            'delete_confirmation' => 'DELETE',
        ])->assertSessionHasErrors('delete_confirmation');
        $this->assertDatabaseHas('workspace_projects', ['id' => $project->id]);
    }

    public function test_confirmed_project_deletion_moves_directory_to_private_trash_and_clears_selection(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $project = $this->project($user, 'movie-project');

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('trashProjectDirectory')->once()->withArgs(fn (
            WorkspaceProfile $profile,
            WorkspaceProject $selected,
        ): bool => $profile->user_id === $user->id && $selected->is($project))->andReturn([
            'completed' => true,
            'disposition' => 'private_trash',
        ]);
        $manager->shouldNotReceive('restoreProjectDirectory');
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $response = $this->actingAs($user)->withSession([
            WorkspaceProjectService::SESSION_KEY => $project->id,
        ])->delete(route('workspace.projects.destroy', $project), [
            'delete_confirmation' => 'delete',
        ]);

        $response->assertRedirect(route('workspace'))
            ->assertSessionMissing(WorkspaceProjectService::SESSION_KEY);
        $this->assertDatabaseMissing('workspace_projects', ['id' => $project->id]);
        $this->assertNull($user->workspaceProfile()->value('selected_project_id'));
    }

    public function test_start_uses_fixed_control_clients_and_creates_an_opaque_user_profile(): void
    {
        $user = User::factory()->create();
        $reservation = $this->reservation($user, ReservationStatus::Confirmed, startsAt: now());
        $project = $this->project($user);

        $broker = Mockery::mock(MockBrokerControlClient::class);
        $broker->shouldReceive('register')->once()->withArgs(function (
            Reservation $actual,
            string $token,
            WorkspaceRuntime $runtime,
        ) use ($reservation, $user): bool {
            return $actual->is($reservation)
                && $runtime->user_id === $user->id
                && strlen($token) === 96;
        });
        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('ensureRuntime')->once()->withArgs(function (
            WorkspaceRuntime $runtime,
            WorkspaceProfile $profile,
            WorkspaceProject $selected,
        ) use ($user, $project): bool {
            return $runtime->user_id === $user->id
                && $runtime->auth_mode === 'personal'
                && $profile->user_id === $user->id
                && $selected->is($project)
                && $runtime->generation === 1;
        })->andReturnUsing(fn (WorkspaceRuntime $runtime): array => $this->runtimeStatus(
            $runtime,
            $project,
            ['container_id' => '0123456789ab', 'container_name' => 'movie-ws-'.$runtime->id],
        ));
        $manager->shouldReceive('runtimeStatus')->once()->andReturnUsing(
            fn (WorkspaceRuntime $runtime): array => $this->runtimeStatus($runtime, $project),
        );
        $manager->shouldReceive('updateRuntimeDeadline')->once();
        $manager->shouldReceive('grantLocalAi')->once()->withArgs(fn (
            WorkspaceRuntime $runtime,
            Reservation $actual,
            string $token,
        ): bool => $runtime->user_id === $user->id
            && $actual->is($reservation)
            && strlen($token) === 96)->andReturn(['granted' => true]);
        $this->app->instance(MockBrokerControlClient::class, $broker);
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->withSession([
            ...$this->entrySession($project),
        ])->post('/workspace/start')->assertRedirect(route('workspace.terminal', ['entry' => 'test-entry']));

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Active, $reservation->status);
        $this->assertNotEmpty($reservation->broker_token);
        $this->assertDatabaseHas('workspace_profiles', ['user_id' => $user->id]);
        $profile = $user->workspaceProfile()->firstOrFail();
        $this->assertNotSame($user->id, $profile->storage_uuid);
        $this->assertNotSame($reservation->broker_token, DB::table('reservations')->where('id', $reservation->id)->value('broker_token'));
        $this->assertArrayNotHasKey('broker_token', $reservation->toArray());
    }

    public function test_active_workspace_can_be_stopped_without_ending_its_reservation_and_restarted_manually(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $reservation = $this->reservation($user, ReservationStatus::Active, startsAt: now()->subMinute());
        $project = $this->project($user, 'movie-project');
        $runtime = $this->runningRuntime($user, $project);
        $reservation = $this->bindLocalAi($reservation, $runtime);

        $broker = Mockery::mock(MockBrokerControlClient::class);
        $broker->shouldReceive('revoke')->once()->withArgs(
            fn (Reservation $actual, WorkspaceRuntime $bound): bool => $actual->is($reservation)
                && $bound->is($runtime),
        );
        $broker->shouldReceive('register')->once()->withArgs(
            fn (Reservation $actual, string $token, WorkspaceRuntime $bound): bool => $actual->is($reservation)
                && $bound->user_id === $user->id
                && $bound->generation === 2
                && strlen($token) === 96,
        );
        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->times(4)->andReturnUsing(
            fn (WorkspaceRuntime $actual): array => $actual->status === 'stopped'
                ? ['running' => false]
                : $this->runtimeStatus($actual, $project),
        );
        $manager->shouldReceive('revokeLocalAi')->once()->withArgs(
            fn (WorkspaceRuntime $bound, Reservation $actual, int $generation): bool => $bound->is($runtime)
                && $actual->is($reservation)
                && $generation === 1,
        )->andReturn(['revoked' => true]);
        $manager->shouldReceive('stopRuntime')->once()->withArgs(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime),
        )->andReturn(['stopped' => true]);
        $manager->shouldReceive('ensureRuntime')->once()->withArgs(fn (
            WorkspaceRuntime $bound,
            WorkspaceProfile $profile,
            WorkspaceProject $selected,
        ): bool => $bound->user_id === $user->id
            && $bound->generation === 2
            && $profile->user_id === $user->id
            && $selected->is($project)
            && $bound->auth_mode === 'personal')->andReturnUsing(
                fn (WorkspaceRuntime $bound): array => $this->runtimeStatus(
                    $bound,
                    $project,
                    ['container_id' => '0123456789ab', 'container_name' => 'movie-ws-'.$bound->id],
                ),
            );
        $manager->shouldReceive('updateRuntimeDeadline')->once();
        $manager->shouldReceive('grantLocalAi')->once()->andReturn(['granted' => true]);
        $this->app->instance(MockBrokerControlClient::class, $broker);
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->withSession([
            WorkspaceProjectService::SESSION_KEY => $project->id,
        ])->post(route('workspace.stop'), [
            'stop_confirmation' => 'stop',
        ])->assertRedirect(route('workspace'));

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertNotNull($reservation->workspace_stopped_at);
        $this->assertNull($reservation->broker_token);
        $this->assertNull($reservation->end_reason);
        $this->assertSame('stopped', $runtime->refresh()->status);

        $this->actingAs($user)->withSession([
            WorkspaceProjectService::SESSION_KEY => $project->id,
        ])->get(route('workspace.terminal'))
            ->assertOk()
            ->assertSee('data-testid="codex-account-modal"', false);

        $reconciled = app(WorkspaceRuntimeService::class)->reconcile();
        $this->assertSame(0, $reconciled['started']);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->refresh()->status);

        $this->actingAs($user)->withSession([
            ...$this->entrySession($project),
        ])->post(route('workspace.start'))->assertRedirect(route('workspace.terminal', ['entry' => 'test-entry']));

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Active, $reservation->status);
        $this->assertNull($reservation->workspace_stopped_at);
        $this->assertNotNull($reservation->broker_token);
    }

    public function test_workspace_stop_requires_exact_confirmation_and_an_owned_active_runtime(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $reservation = $this->reservation($owner, ReservationStatus::Active, startsAt: now()->subMinute());

        $broker = Mockery::mock(MockBrokerControlClient::class);
        $broker->shouldNotReceive('revoke');
        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldNotReceive('stop');
        $this->app->instance(MockBrokerControlClient::class, $broker);
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($owner)->post(route('workspace.stop'), [
            'stop_confirmation' => 'STOP',
        ])->assertSessionHasErrors('stop_confirmation');
        $this->assertSame(ReservationStatus::Active, $reservation->refresh()->status);

        $this->actingAs($other)->post(route('workspace.stop'), [
            'stop_confirmation' => 'stop',
        ])->assertSessionHasErrors('workspace');
        $this->assertSame(ReservationStatus::Active, $reservation->refresh()->status);
    }

    public function test_owner_can_abandon_the_current_active_reservation_after_runtime_cleanup(): void
    {
        $user = User::factory()->create();
        $reservation = $this->reservation($user, ReservationStatus::Active, startsAt: now()->subMinute());
        $project = $this->project($user);
        $runtime = $this->runningRuntime($user, $project);
        $runtime->forceFill(['idle_expires_at' => $reservation->ends_at->addMinutes(10)])->save();
        $reservation = $this->bindLocalAi($reservation, $runtime);

        $broker = Mockery::mock(MockBrokerControlClient::class);
        $broker->shouldReceive('revoke')->once()->withArgs(
            fn (Reservation $actual, WorkspaceRuntime $bound): bool => $actual->is($reservation)
                && $bound->is($runtime),
        );
        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->twice()->andReturnUsing(
            fn (WorkspaceRuntime $bound): array => $this->runtimeStatus(
                $bound,
                $project,
                ['ai_network_connected' => true],
            ),
        );
        $manager->shouldReceive('revokeLocalAi')->once()->withArgs(
            fn (WorkspaceRuntime $bound, Reservation $actual, int $generation): bool => $bound->is($runtime)
                && $actual->is($reservation)
                && $generation === 1,
        )->andReturn(['revoked' => true]);
        $manager->shouldNotReceive('stopRuntime');
        $this->app->instance(MockBrokerControlClient::class, $broker);
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->withSession([
            WorkspaceProjectService::SESSION_KEY => $project->id,
        ])->get(route('workspace.terminal'))
            ->assertOk()
            ->assertSee('Abandon reservation')
            ->assertSee('Confirm abandon reservation');

        $this->actingAs($user)->withSession([
            WorkspaceProjectService::SESSION_KEY => $project->id,
        ])->delete(route('workspace.reservations.abandon', $reservation), [
            'abandon_confirmation' => 'abandon',
        ])->assertRedirect(route('workspace.terminal'));

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Cancelled, $reservation->status);
        $this->assertSame('user_abandon', $reservation->end_reason);
        $this->assertNotNull($reservation->cancelled_at);
        $this->assertNull($reservation->broker_token);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'reservation.abandoned',
            'target_id' => $reservation->id,
        ]);
    }

    public function test_abandon_force_releases_a_stale_broker_generation_without_resource_locked(): void
    {
        $user = User::factory()->create();
        $reservation = $this->reservation($user, ReservationStatus::Active, startsAt: now()->subMinute());
        $project = $this->project($user);
        $runtime = $this->runningRuntime($user, $project, generation: 5);
        $reservation = $this->bindLocalAi($reservation, $runtime);
        $runtime->forceFill(['generation' => 6])->save();

        $broker = Mockery::mock(MockBrokerControlClient::class);
        $broker->shouldReceive('revoke')->once()->withArgs(
            fn (Reservation $actual, WorkspaceRuntime $bound): bool => $actual->is($reservation)
                && $bound->is($runtime),
        )->andThrow(new \RuntimeException('runtime_binding_mismatch'));
        $broker->shouldReceive('forceRevoke')->once()->withArgs(
            fn (Reservation $actual): bool => $actual->is($reservation),
        );
        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->once()->withArgs(
            fn (WorkspaceRuntime $bound): bool => $bound->is($runtime),
        )->andReturn($this->runtimeStatus($runtime, $project, ['ai_network_connected' => true]));
        $manager->shouldReceive('revokeLocalAi')->once()->withArgs(
            fn (WorkspaceRuntime $bound, Reservation $actual, int $generation): bool => $bound->is($runtime)
                && $actual->is($reservation)
                && $generation === 6,
        )->andReturn(['revoked' => true]);
        $manager->shouldNotReceive('stopRuntime');
        $this->app->instance(MockBrokerControlClient::class, $broker);
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $abandoned = app(WorkspaceRuntimeService::class)->abandon($reservation, $user);

        $this->assertSame(ReservationStatus::Cancelled, $abandoned->status);
        $this->assertSame('user_abandon', $abandoned->end_reason);
        $this->assertNull($abandoned->broker_token);
        $this->assertNull($abandoned->workspace_runtime_id);
        $this->assertNull($abandoned->ai_grant_generation);
        $this->assertFalse(Reservation::query()->where('status', 'resource_locked')->exists());
        $this->assertDatabaseHas('audit_events', [
            'action' => 'local_ai.grant_force_revoked',
            'target_id' => $reservation->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'local_ai.runtime_force_revoked',
            'target_id' => $reservation->id,
        ]);
    }

    public function test_abandoning_a_stopped_current_reservation_never_calls_runtime_cleanup_again(): void
    {
        $user = User::factory()->create();
        $reservation = $this->reservation($user, ReservationStatus::Confirmed, startsAt: now()->subMinute());
        $reservation->update(['workspace_stopped_at' => now()]);

        $broker = Mockery::mock(MockBrokerControlClient::class);
        $broker->shouldNotReceive('revoke');
        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldNotReceive('stop');
        $this->app->instance(MockBrokerControlClient::class, $broker);
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->delete(route('workspace.reservations.abandon', $reservation), [
            'abandon_confirmation' => 'abandon',
        ])->assertRedirect(route('workspace.terminal'));

        $this->assertSame(ReservationStatus::Cancelled, $reservation->refresh()->status);
    }

    public function test_abandon_requires_exact_confirmation_and_reservation_ownership(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $reservation = $this->reservation($owner, ReservationStatus::Confirmed, startsAt: now()->subMinute());

        $this->actingAs($owner)->delete(route('workspace.reservations.abandon', $reservation), [
            'abandon_confirmation' => 'ABANDON',
        ])->assertSessionHasErrors('abandon_confirmation');
        $this->assertSame(ReservationStatus::Confirmed, $reservation->refresh()->status);

        $this->actingAs($other)->delete(route('workspace.reservations.abandon', $reservation), [
            'abandon_confirmation' => 'abandon',
        ])->assertNotFound();
        $this->assertSame(ReservationStatus::Confirmed, $reservation->refresh()->status);
    }

    public function test_stopped_workspace_reservation_expires_without_being_automatically_restarted(): void
    {
        $user = User::factory()->create();
        $reservation = $this->reservation(
            $user,
            ReservationStatus::Confirmed,
            startsAt: now()->subHours(3),
        );
        $reservation->update(['workspace_stopped_at' => now()->subHour()]);

        $broker = Mockery::mock(MockBrokerControlClient::class);
        $broker->shouldNotReceive('register');
        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldNotReceive('start');
        $this->app->instance(MockBrokerControlClient::class, $broker);
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $reconciled = app(WorkspaceRuntimeService::class)->reconcile();

        $this->assertSame(0, $reconciled['started']);
        $this->assertSame(1, $reconciled['stopped']);
        $this->assertSame(ReservationStatus::Completed, $reservation->refresh()->status);
        $this->assertSame('expired', $reservation->end_reason);
    }

    public function test_reconcile_stops_the_expired_runtime_before_starting_an_adjacent_reservation(): void
    {
        $handoff = CarbonImmutable::now();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $first = $this->reservation($firstUser, ReservationStatus::Active, startsAt: $handoff->subHour());
        $first->update([
            'ends_at' => $handoff,
            'lock_ends_at' => $handoff,
        ]);
        $second = $this->reservation($secondUser, ReservationStatus::Confirmed, startsAt: $handoff);
        $second->update([
            'ends_at' => $handoff->addHour(),
            'lock_ends_at' => $handoff->addHour(),
        ]);
        $firstProject = $this->project($firstUser, 'current-project');
        $firstRuntime = $this->runningRuntime($firstUser, $firstProject);
        $first = $this->bindLocalAi($first, $firstRuntime);
        $project = $this->project($secondUser, 'handoff-project');
        $secondRuntime = $this->runningRuntime($secondUser, $project);
        $events = [];

        $broker = Mockery::mock(MockBrokerControlClient::class);
        $broker->shouldReceive('revoke')->once()->withArgs(
            fn (Reservation $actual, WorkspaceRuntime $runtime): bool => $actual->is($first)
                && $runtime->is($firstRuntime),
        )->andReturnUsing(function () use (&$events): void {
            $events[] = 'broker.revoke';
        });
        $broker->shouldReceive('register')->once()->withArgs(
            fn (Reservation $actual, string $token, WorkspaceRuntime $runtime): bool => $actual->is($second)
                && $runtime->is($secondRuntime)
                && strlen($token) === 96,
        )->andReturnUsing(function () use (&$events): void {
            $events[] = 'broker.register';
        });
        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->times(4)->andReturnUsing(
            fn (WorkspaceRuntime $runtime): array => $runtime->is($firstRuntime)
                ? $this->runtimeStatus($firstRuntime, $firstProject, ['ai_network_connected' => true])
                : $this->runtimeStatus($secondRuntime, $project),
        );
        $manager->shouldReceive('revokeLocalAi')->once()->withArgs(
            fn (WorkspaceRuntime $runtime, Reservation $actual, int $generation): bool => $runtime->is($firstRuntime)
                && $actual->is($first)
                && $generation === 1,
        )->andReturnUsing(function () use (&$events): array {
            $events[] = 'manager.revoke';

            return ['revoked' => true];
        });
        $manager->shouldReceive('updateRuntimeDeadline')->once();
        $manager->shouldReceive('grantLocalAi')->once()->withArgs(
            fn (WorkspaceRuntime $runtime, Reservation $actual, string $token): bool => $runtime->is($secondRuntime)
                && $actual->is($second)
                && strlen($token) === 96,
        )->andReturnUsing(function () use (&$events): array {
            $events[] = 'manager.grant';

            return ['granted' => true];
        });
        $this->app->instance(MockBrokerControlClient::class, $broker);
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $reconciled = app(WorkspaceRuntimeService::class)->reconcile();

        $this->assertSame(['started' => 1, 'stopped' => 1, 'errors' => 0], $reconciled);
        $this->assertSame(ReservationStatus::Completed, $first->refresh()->status);
        $this->assertSame(ReservationStatus::Active, $second->refresh()->status);
        $this->assertSame(['broker.revoke', 'manager.revoke', 'broker.register', 'manager.grant'], $events);
        $this->assertSame('running', $firstRuntime->refresh()->status);
        $this->assertSame('running', $secondRuntime->refresh()->status);
    }

    public function test_terminal_authorization_is_bound_to_the_logged_in_reservation_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $reservation = $this->reservation($owner, ReservationStatus::Active, startsAt: now()->subMinute());
        $project = $this->project($owner);
        $runtime = $this->runningRuntime($owner, $project);
        $reservation = $this->bindLocalAi($reservation, $runtime);

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->once()->with(Mockery::on(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime),
        ))->andReturn($this->runtimeStatus($runtime, $project));
        $this->app->instance(WorkspaceManagerClient::class, $manager);
        $claims = Mockery::mock(TerminalRouteClaimService::class);
        $claims->shouldReceive('issue')->once()->with(Mockery::on(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime),
        ))->andReturn('test-route-claim');
        $this->app->instance(TerminalRouteClaimService::class, $claims);

        $this->actingAs($other)->withSession([
            WorkspaceProjectService::SESSION_KEY => $project->id,
        ])->get('/internal/terminal-authorize')->assertForbidden();
        $this->actingAs($owner)->withSession([
            ...$this->entrySession($project),
        ])->get('/internal/terminal-authorize')
            ->assertNoContent()
            ->assertHeader('X-Movie-Route', 'test-route-claim');
        $this->assertNotNull($reservation->refresh()->first_connected_at);
    }

    public function test_terminal_authorization_before_a_reservation_does_not_require_an_active_reservation(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $runtime = $this->runningRuntime($user, $project);

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->once()->with(Mockery::on(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime),
        ))->andReturn($this->runtimeStatus($runtime, $project));
        $this->app->instance(WorkspaceManagerClient::class, $manager);
        $claims = Mockery::mock(TerminalRouteClaimService::class);
        $claims->shouldReceive('issue')->once()->with(Mockery::on(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime),
        ))->andReturn('test-route-claim');
        $this->app->instance(TerminalRouteClaimService::class, $claims);

        $this->actingAs($user)->withSession([
            ...$this->entrySession($project),
        ])->get('/internal/terminal-authorize')
            ->assertNoContent()
            ->assertHeader('X-Movie-Route', 'test-route-claim');
        $this->assertSame(0, $user->reservations()->count());
    }

    public function test_active_workspace_owner_can_upload_an_image_into_the_selected_project(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $reservation = $this->reservation($user, ReservationStatus::Active, startsAt: now()->subMinute());
        $project = $this->project($user, 'movie-project');
        $runtime = $this->runningRuntime($user, $project);
        $reservation = $this->bindLocalAi($reservation, $runtime);
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $upload = UploadedFile::fake()->createWithContent('reference.png', $contents);

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->once()->with(Mockery::on(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime),
        ))->andReturn($this->runtimeStatus($runtime, $project));
        $manager->shouldReceive('uploadRuntimeImage')->once()->withArgs(function (
            WorkspaceRuntime $actual,
            WorkspaceProject $selected,
            string $filename,
            string $mime,
            string $bytes,
        ) use ($runtime, $project, $contents): bool {
            return $actual->is($runtime)
                && $selected->is($project)
                && preg_match('/\A[0-9a-f-]{36}\.png\z/', $filename) === 1
                && $mime === 'image/png'
                && $bytes === $contents;
        })->andReturnUsing(function (
            WorkspaceRuntime $actual,
            WorkspaceProject $selected,
            string $filename,
            string $mime,
            string $bytes,
        ): array {
            $relative = 'uploads/'.$filename;

            return [
                'path' => '/workspace/'.$selected->directory_name.'/'.$relative,
                'relative_path' => $relative,
                'filename' => $filename,
                'mime' => $mime,
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
            ];
        });
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $response = $this->actingAs($user)->withHeader('Accept', 'application/json')->withSession([
            ...$this->entrySession($project),
        ])->post(route('workspace.media.store'), ['media' => $upload]);

        $response->assertCreated()
            ->assertJsonPath('mime', 'image/png')
            ->assertJsonPath('media_type', 'image')
            ->assertJsonPath('relative_path', fn (string $path): bool => str_starts_with($path, 'uploads/'))
            ->assertJsonPath('library_url', fn (string $url): bool => str_contains($url, '/images/uploads/'))
            ->assertJsonPath('mention_command', fn (string $command): bool => str_starts_with(
                $command,
                '/mention /workspace/movie-project/uploads/',
            ));
        $this->assertDatabaseHas('audit_events', [
            'action' => 'workspace.image_uploaded',
            'target_id' => $project->id,
        ]);
        $profile = $user->workspaceProfile()->firstOrFail();
        $this->assertFileExists(
            $this->mediaRoot.'/'.$profile->storage_uuid.'/'.$project->id.'/'.$response->json('library_relative_path'),
        );
        $this->actingAs($user)->get(route('workspace.images.index'))
            ->assertOk()
            ->assertSee($response->json('filename'));
    }

    public function test_workspace_media_upload_rejects_unsupported_files_and_inactive_users_before_manager_write(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldNotReceive('runtimeStatus');
        $manager->shouldNotReceive('uploadRuntimeImage');
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->withHeader('Accept', 'application/json')->withSession([
            ...$this->entrySession($project),
        ])->post(route('workspace.media.store'), [
            'media' => UploadedFile::fake()->createWithContent('notes.txt', 'not media'),
        ])->assertUnprocessable()->assertJsonValidationErrors('media');

        $validPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $this->actingAs($user)->withHeader('Accept', 'application/json')->withSession([
            ...$this->entrySession($project),
        ])->post(route('workspace.media.store'), [
            'media' => UploadedFile::fake()->createWithContent('reference.png', $validPng),
        ])->assertForbidden();
    }

    public function test_active_workspace_owner_can_upload_a_video_into_the_selected_project_library(): void
    {
        $user = User::factory()->create(['email' => 'video.owner@example.com']);
        $reservation = $this->reservation($user, ReservationStatus::Active, startsAt: now()->subMinute());
        $project = $this->project($user, 'video-project');
        $runtime = $this->runningRuntime($user, $project);
        $reservation = $this->bindLocalAi($reservation, $runtime);
        $video = base64_decode(
            'AAAAIGZ0eXBpc29tAAACAGlzb21pc28yYXZjMW1wNDEAAANcbW9vdgAAAGxtdmhkAAAAAAAAAAAAAAAAAAAD6AAAAHgAAQAAAQAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgAAAod0cmFrAAAAXHRraGQAAAADAAAAAAAAAAAAAAABAAAAAAAAAHgAAAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAABAAAAAABAAAAAQAAAAAAAkZWR0cwAAABxlbHN0AAAAAAAAAAEAAAB4AAAEAAABAAAAAAH/bWRpYQAAACBtZGhkAAAAAAAAAAAAAAAAAAAyAAAABgBVxAAAAAAALWhkbHIAAAAAAAAAAHZpZGUAAAAAAAAAAAAAAABWaWRlb0hhbmRsZXIAAAABqm1pbmYAAAAUdm1oZAAAAAEAAAAAAAAAAAAAACRkaW5mAAAAHGRyZWYAAAAAAAAAAQAAAAx1cmwgAAAAAQAAAWpzdGJsAAAAvnN0c2QAAAAAAAAAAQAAAK5hdmMxAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAABAAEABIAAAASAAAAAAAAAABFExhdmM2My4xLjEwMSBsaWJ4MjY0AAAAAAAAAAAAAAAAGP//AAAANGF2Y0MBZAAK/+EAF2dkAAqs2V7ARAAAAwAEAAADAMg8SJZYAQAGaOvjyyLA/fj4AAAAABBwYXNwAAAAAQAAAAEAAAAUYnRydAAAAAAAAL7iAAAAAAAAABhzdHRzAAAAAAAAAAEAAAADAAACAAAAABRzdHNzAAAAAAAAAAEAAAABAAAAKGN0dHMAAAAAAAAAAwAAAAEAAAQAAAAAAQAABgAAAAABAAACAAAAABxzdHNjAAAAAAAAAAEAAAABAAAAAwAAAAEAAAAgc3RzegAAAAAAAAAAAAAAAwAAAsUAAAAMAAAADAAAABRzdGNvAAAAAAAAAAEAAAOMAAAAYXVkdGEAAABZbWV0YQAAAAAAAAAhaGRscgAAAAAAAAAAbWRpcmFwcGwAAAAAAAAAAAAAAAAsaWxzdAAAACSpdG9vAAAAHGRhdGEAAAABAAAAAExhdmY2My4xLjEwMQAAAAhmcmVlAAAC5W1kYXQAAAKuBgX//6rcRem95tlIt5Ys2CDZI+7veDI2NCAtIGNvcmUgMTY1IHIzMjIyIGIzNTYwNWEgLSBILjI2NC9NUEVHLTQgQVZDIGNvZGVjIC0gQ29weWxlZnQgMjAwMy0yMDI1IC0gaHR0cDovL3d3dy52aWRlb2xhbi5vcmcveDI2NC5odG1sIC0gb3B0aW9uczogY2FiYWM9MSByZWY9MyBkZWJsb2NrPTE6MDowIGFuYWx5c2U9MHgzOjB4MTEzIG1lPWhleCBzdWJtZT03IHBzeT0xIHBzeV9yZD0xLjAwOjAuMDAgbWl4ZWRfcmVmPTEgbWVfcmFuZ2U9MTYgY2hyb21hX21lPTEgdHJlbGxpcz0xIDh4OGRjdD0xIGNxbT0wIGRlYWR6b25lPTIxLDExIGZhc3RfcHNraXA9MSBjaHJvbWFfcXBfb2Zmc2V0PS0yIHRocmVhZHM9MSBsb29rYWhlYWRfdGhyZWFkcz0xIHNsaWNlZF90aHJlYWRzPTAgbnI9MCBkZWNpbWF0ZT0xIGludGVybGFjZWQ9MCBibHVyYXlfY29tcGF0PTAgY29uc3RyYWluZWRfaW50cmE9MCBiZnJhbWVzPTMgYl9weXJhbWlkPTIgYl9hZGFwdD0xIGJfYmlhcz0wIGRpcmVjdD0xIHdlaWdodGI9MSBvcGVuX2dvcD0wIHdlaWdodHA9MiBrZXlpbnQ9MjUwIGtleWludF9taW49MjUgc2NlbmVjdXQ9NDAgaW50cmFfcmVmcmVzaD0wIHJjX2xvb2thaGVhZD00MCByYz1jcmYgbWJ0cmVlPTEgY3JmPTIzLjAgcWNvbXA9MC42MCBxcG1pbj0wIHFwbWF4PTY5IHFwc3RlcD00IGlwX3JhdGlvPTEuNDAgYXE9MToxLjAwAIAAAAAPZYiEADP//vbsvgU2FMjBAAAACEGaImxCv/7AAAAACAGeQXkK/8SB',
            true,
        );
        $upload = UploadedFile::fake()->createWithContent('reference.mp4', $video)->mimeType('video/mp4');

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->once()->with(Mockery::on(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime),
        ))->andReturn($this->runtimeStatus($runtime, $project));
        $manager->shouldReceive('uploadRuntimeImage')->once()->andReturnUsing(function (
            WorkspaceRuntime $actual,
            WorkspaceProject $selected,
            string $filename,
            string $mime,
            string $bytes,
        ) use ($runtime): array {
            $this->assertTrue($actual->is($runtime));
            $this->assertSame('video/mp4', $mime);
            $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\.mp4\z/', $filename);
            $relative = 'uploads/'.$filename;

            return [
                'path' => '/workspace/'.$selected->directory_name.'/'.$relative,
                'relative_path' => $relative,
                'filename' => $filename,
                'mime' => $mime,
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
            ];
        });
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $response = $this->actingAs($user)->withHeader('Accept', 'application/json')->withSession([
            ...$this->entrySession($project),
        ])->post(route('workspace.media.store'), ['media' => $upload]);

        $response->assertCreated()
            ->assertJsonPath('media_type', 'video')
            ->assertJsonPath('mime', 'video/mp4')
            ->assertJsonPath('library_url', fn (string $url): bool => str_contains($url, '/videos/uploads/'));
        $profile = $user->workspaceProfile()->firstOrFail();
        $this->assertFileExists(
            $this->mediaRoot.'/'.$profile->storage_uuid.'/'.$project->id.'/'.$response->json('library_relative_path'),
        );
        $this->actingAs($user)->get(route('workspace.videos.index'))
            ->assertOk()
            ->assertSee($response->json('filename'));
        $this->assertDatabaseHas('audit_events', [
            'action' => 'workspace.video_uploaded',
            'target_id' => $project->id,
        ]);
    }

    public function test_active_workspace_uses_a_viewport_sized_terminal_window(): void
    {
        $user = User::factory()->create();
        $reservation = $this->reservation($user, ReservationStatus::Active, startsAt: now()->subMinute());
        $project = $this->project($user);
        $runtime = $this->runningRuntime($user, $project);
        $reservation = $this->bindLocalAi($reservation, $runtime);

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->once()->with(Mockery::on(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime),
        ))->andReturn($this->runtimeStatus($runtime, $project, ['ai_network_connected' => true]));
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $response = $this->actingAs($user)->withSession([
            ...$this->entrySession($project),
        ])->get(route('workspace.terminal', ['entry' => 'test-entry']))
            ->assertOk()
            ->assertSeeText('Qwen · H3 video · Z-Image-Turbo')
            ->assertSee('Extend reservation')
            ->assertSee('data-testid="reservation-extend-control"', false)
            ->assertSee(route('reservations.extend', $reservation), false)
            ->assertSee('Stop workspace')
            ->assertSee('Confirm stop workspace')
            ->assertSee('Style library')
            ->assertSee('data-testid="style-library-modal"', false)
            ->assertSee('data-style-library-close', false)
            ->assertSee('data-style-card', false)
            ->assertSee('$h3-editorial-fashion-motion')
            ->assertSee('$h3-surreal-miniature-absurdism')
            ->assertSee('$h3-chibi-live-action-sticker')
            ->assertSee('$h3-creature-motion-replacement')
            ->assertSee('$h3-multiverse-portal-ensemble')
            ->assertSee('$h3-deadpan-mockumentary-interview')
            ->assertSee('$h3-soft-body-physics-comedy')
            ->assertSee('$h3-retro-pixel-sprite-loop')
            ->assertSee('$h3-japanese-craft-commercial')
            ->assertSee('$h3-micro-fpv-impossible-one-take')
            ->assertSee('$h3-occlusion-orbit-ensemble')
            ->assertSee('$h3-character-intro-motion-card')
            ->assertSee('$h3-ancient-title-sequence')
            ->assertSee('$h3-interactive-creature-encyclopedia')
            ->assertSee('$h3-anime-character-showcase-pv')
            ->assertSee('$h3-material-carving-asmr')
            ->assertSee('$h3-pop-art-split-screen-motion')
            ->assertSee('$h3-dark-sci-fi-motion-poster')
            ->assertSee('$h3-asymmetric-speed-duo-choreography')
            ->assertSee('$h3-layered-windsurfing-fashion-mv')
            ->assertSee('$h3-water-obstacle-variety-show')
            ->assertSee('$h3-two-part-character-reveal')
            ->assertSee('$h3-first-person-finger-controlled-dance')
            ->assertSee('grid-cols-3', false)
            ->assertSee('overflow-y-auto', false)
            ->assertSee('grid-auto-rows: 21rem;', false)
            ->assertDontSee('grid-auto-rows: minmax(', false)
            ->assertDontSee('data-style-page-next', false)
            ->assertSee('workspace-action', false)
            ->assertSee('data-testid="workspace-terminal-window"', false)
            ->assertSee('data-workspace-media-upload', false)
            ->assertSee('Upload image or video to Codex')
            ->assertSee('data-local-ai-countdown', false)
            ->assertSee('data-phase="ready"', false)
            ->assertSee('Local AI available')
            ->assertSee('border-emerald-400/60', false)
            ->assertSee('data-workspace-session-history', false)
            ->assertSee('New blank session')
            ->assertSee('Enter workspace')
            ->assertSee('data-enter-workspace', false)
            ->assertSee('href="#workspace-terminal"', false)
            ->assertSee('id="workspace-terminal"', false)
            ->assertSee('tabindex="-1"', false)
            ->assertSee(route('workspace.sessions.index'), false)
            ->assertSee(route('workspace.sessions.select'), false)
            ->assertSee(url('/workspace/sessions'), false)
            ->assertSee('data-session-toggle', false)
            ->assertSee('aria-expanded="true"', false)
            ->assertSee('max-h-[19.25rem]', false)
            ->assertSee('[grid-auto-rows:9.25rem]', false)
            ->assertSee('overflow-y-auto', false)
            ->assertSee('Upload and add to CLI')
            ->assertSee('data-terminal-copy', false)
            ->assertSee('data-terminal-copy-open', false)
            ->assertSee('data-terminal-copy-screen', false)
            ->assertSee('data-terminal-copy-text', false)
            ->assertSee('data-terminal-readiness', false)
            ->assertSee('data-terminal-loading', false)
            ->assertSee('animate-spin', false)
            ->assertSee('aria-busy="true"', false)
            ->assertSee('Loading Codex CLI')
            ->assertSee('allow="clipboard-read; clipboard-write"', false)
            ->assertSee(route('workspace.media.store'))
            ->assertSeeText('An existing session enters Codex directly')
            ->assertSeeText('You do not need to type a command before entering Codex')
            ->assertSee('class="workspace-terminal-frame bg-black"', false)
            ->assertDontSee('h-[68vh]', false)
            ->assertDontSee('min-h-[520px]', false);

        $this->assertCount(23, config('movie.styles'));
        $this->assertSame(23, substr_count($response->getContent(), 'data-style-card'));
        $this->assertSame(23, substr_count($response->getContent(), 'data-style-media-slot'));
        $this->assertSame(23, substr_count($response->getContent(), 'data-style-demo-unavailable'));
        $response->assertSee('relative min-h-0 w-full flex-1 basis-0 overflow-hidden bg-black', false);
        $response->assertSee('absolute inset-0 flex h-full w-full flex-col items-center justify-center', false);
        $response->assertDontSee('/workspace/styles/h3-editorial-fashion-motion/demo', false);

        $css = preg_replace('/\s+/', ' ', file_get_contents(resource_path('css/app.css')));

        $this->assertStringContainsString('.workspace-terminal-window { position: relative; left: 50%; width: 90vw;', $css);
        $this->assertStringContainsString('.workspace-terminal-frame { display: block; width: 100%; height: 100vh; height: 100dvh;', $css);
        $this->assertStringContainsString(".workspace-action:not(:disabled):not([aria-disabled='true']):hover", $css);
        $this->assertStringContainsString('0 0 18px rgb(232 121 249 / 28%)', $css);

        $javascript = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString('terminal?.buffer?.active', $javascript);
        $this->assertStringContainsString("document.body.dataset.terminalReady = 'false';", $javascript);
        $this->assertStringContainsString("buffer?.type === 'alternate' || buffer === terminal.buffer?.alternate", $javascript);
        $this->assertStringContainsString("finishLoading('ready', terminal);", $javascript);
        $this->assertStringContainsString("workspaceTerminalFrame.style.pointerEvents = 'none';", $javascript);
        $this->assertStringContainsString("redirect.hash = 'workspace-terminal';", $javascript);
        $this->assertStringContainsString('terminal.attachCustomKeyEventHandler', $javascript);
        $this->assertStringContainsString('navigator.clipboard.writeText(value)', $javascript);
        $this->assertStringContainsString("'IntersectionObserver' in window", $javascript);
        $this->assertStringContainsString('scrollRegion.scrollTop = 0;', $javascript);
        $this->assertStringContainsString("closeButton.addEventListener('click', close);", $javascript);
        $this->assertStringContainsString("switchSession('resume', session.id)", $javascript);
        $this->assertStringContainsString("switchSession('new')", $javascript);
        $this->assertStringContainsString('data: { confirmed: true }', $javascript);
        $this->assertStringContainsString("t('confirm_delete_session')", $javascript);
        $this->assertStringContainsString("'workspace-action rounded-lg border border-red-300/25", $javascript);
        $this->assertStringContainsString("sessionBody.classList.toggle('hidden', expanded)", $javascript);
        $this->assertStringContainsString("document.querySelector('[data-local-ai-countdown]')", $javascript);
        $this->assertStringContainsString('serverOffset = nextServerNow - Date.now();', $javascript);
        $this->assertStringContainsString("t('local_ai_countdown'", $javascript);
        $this->assertStringContainsString('window.location.reload();', $javascript);
        $this->assertStringNotContainsString("modal.addEventListener('click', close)", $javascript);
        $this->assertStringNotContainsString("modal.addEventListener('keydown', (event) => {\n        if (event.key === 'Escape')", $javascript);
    }

    public function test_personal_session_history_is_project_scoped_and_can_be_resumed_before_a_reservation(): void
    {
        $user = User::factory()->create(['email' => 'workspace.owner@example.com']);
        $project = $this->project($user, 'movie-project');
        $runtime = $this->runningRuntime($user, $project);
        $sessionId = '12345678-1234-4123-8123-123456789abc';

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->twice()->with(Mockery::on(
            fn (WorkspaceRuntime $actual): bool => $actual->id === $runtime->id,
        ))->andReturnUsing(
            fn (WorkspaceRuntime $actual): array => $this->runtimeStatus($actual, $project),
        );
        $manager->shouldReceive('runtimeSessions')->twice()->withArgs(fn (
            WorkspaceRuntime $actual,
            WorkspaceProject $selected,
        ): bool => $actual->id === $runtime->id && $selected->is($project))->andReturn([
            'available' => true,
            'sessions' => [[
                'id' => $sessionId,
                'title' => 'Continue yesterday\'s edit',
                'started_at' => '2026-08-23T18:00:00Z',
                'updated_at' => '2026-08-24T16:00:00Z',
            ]],
            'current_session_id' => null,
        ]);
        $manager->shouldReceive('ensureRuntime')->once()->withArgs(fn (
            WorkspaceRuntime $actual,
            WorkspaceProfile $profile,
            WorkspaceProject $selected,
        ): bool => $actual->id === $runtime->id
            && $actual->generation === 2
            && $profile->root_directory === 'workspace.owner@example.com'
            && $selected->is($project)
            && $actual->auth_mode === 'personal'
            && $actual->session_mode === 'resume'
            && $actual->session_id === $sessionId)->andReturnUsing(
                fn (WorkspaceRuntime $actual): array => $this->runtimeStatus($actual, $project),
            );
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $session = $this->entrySession($project);
        $this->actingAs($user)->withSession($session)
            ->getJson(route('workspace.sessions.index'))
            ->assertOk()
            ->assertJsonPath('sessions.0.id', $sessionId)
            ->assertJsonPath('sessions.0.title', 'Continue yesterday\'s edit');

        $this->actingAs($user)->withSession($session)
            ->postJson(route('workspace.sessions.select'), [
                'action' => 'resume',
                'session_id' => $sessionId,
            ])
            ->assertOk()
            ->assertJsonPath('session_mode', 'resume')
            ->assertJsonPath('session_id', $sessionId)
            ->assertJsonPath('redirect_url', route('workspace.terminal', ['entry' => 'test-entry']));

        $this->assertDatabaseHas('audit_events', [
            'action' => 'workspace.session_selected',
            'target_id' => $runtime->id,
        ]);
    }

    public function test_active_local_ai_is_safely_rotated_when_resuming_a_codex_session(): void
    {
        $user = User::factory()->create(['email' => 'workspace.owner@example.com']);
        $reservation = $this->reservation($user, ReservationStatus::Active, startsAt: now()->subMinute());
        $project = $this->project($user, 'movie-project');
        $runtime = $this->runningRuntime($user, $project);
        $reservation = $this->bindLocalAi($reservation, $runtime);
        $oldToken = (string) $reservation->broker_token;
        $sessionId = '01a04210-0e05-7b23-a664-61a40b35f11f';

        $broker = Mockery::mock(MockBrokerControlClient::class);
        $broker->shouldReceive('hasActiveJobs')->once()->withArgs(
            fn (Reservation $actual): bool => $actual->is($reservation),
        )->andReturnFalse();
        $broker->shouldReceive('revoke')->once()->withArgs(
            fn (
                Reservation $actual,
                WorkspaceRuntime $bound,
                bool $requireIdle,
                bool $preserveFiles,
            ): bool => $actual->is($reservation)
                && $bound->is($runtime)
                && $actual->workspace_runtime_id === $runtime->id
                && (int) $actual->ai_grant_generation === 1
                && $requireIdle
                && $preserveFiles,
        );
        $broker->shouldReceive('register')->once()->withArgs(fn (
            Reservation $actual,
            string $token,
            WorkspaceRuntime $bound,
        ): bool => $actual->is($reservation)
            && $bound->is($runtime)
            && $bound->generation === 2
            && $token !== $oldToken
            && strlen($token) === 96);

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->times(3)->andReturnUsing(
            fn (WorkspaceRuntime $actual): array => $this->runtimeStatus(
                $actual,
                $project,
                ['ai_network_connected' => true],
            ),
        );
        $manager->shouldReceive('runtimeSessions')->once()->withArgs(fn (
            WorkspaceRuntime $actual,
            WorkspaceProject $selected,
        ): bool => $actual->is($runtime) && $selected->is($project))->andReturn([
            'available' => true,
            'sessions' => [[
                'id' => $sessionId,
                'title' => 'Continue movie edit',
                'started_at' => '2026-08-23T18:00:00Z',
                'updated_at' => '2026-08-24T16:00:00Z',
            ]],
            'current_session_id' => null,
        ]);
        $manager->shouldReceive('revokeLocalAi')->once()->withArgs(fn (
            WorkspaceRuntime $bound,
            Reservation $actual,
            int $generation,
        ): bool => $bound->is($runtime)
            && $actual->is($reservation)
            && $generation === 1)->andReturn(['revoked' => true]);
        $manager->shouldReceive('ensureRuntime')->once()->withArgs(fn (
            WorkspaceRuntime $actual,
            WorkspaceProfile $profile,
            WorkspaceProject $selected,
        ): bool => $actual->is($runtime)
            && $actual->generation === 2
            && $actual->session_mode === 'resume'
            && $actual->session_id === $sessionId
            && $profile->user_id === $user->id
            && $selected->is($project))->andReturnUsing(
                fn (WorkspaceRuntime $actual): array => $this->runtimeStatus($actual, $project),
            );
        $manager->shouldReceive('updateRuntimeDeadline')->once();
        $manager->shouldReceive('grantLocalAi')->once()->withArgs(fn (
            WorkspaceRuntime $bound,
            Reservation $actual,
            string $token,
        ): bool => $bound->is($runtime)
            && $bound->generation === 2
            && $actual->is($reservation)
            && $token !== $oldToken
            && strlen($token) === 96)->andReturn(['granted' => true]);
        $this->app->instance(MockBrokerControlClient::class, $broker);
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->withSession($this->entrySession($project))
            ->postJson(route('workspace.sessions.select'), [
                'action' => 'resume',
                'session_id' => $sessionId,
            ])
            ->assertOk()
            ->assertJsonPath('session_mode', 'resume')
            ->assertJsonPath('session_id', $sessionId);

        $reservation->refresh();
        $runtime->refresh();
        $this->assertSame(ReservationStatus::Active, $reservation->status);
        $this->assertSame($runtime->id, $reservation->workspace_runtime_id);
        $this->assertSame(2, (int) $reservation->ai_grant_generation);
        $this->assertNotSame($oldToken, $reservation->broker_token);
        $this->assertNull($reservation->workspace_stopped_at);
        $this->assertSame(2, $runtime->generation);
        $this->assertSame('resume', $runtime->session_mode);
        $this->assertSame($sessionId, $runtime->session_id);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'local_ai.grant_revoked',
            'target_id' => $reservation->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'workspace.session_selected',
            'target_id' => $runtime->id,
        ]);
    }

    public function test_session_switch_is_blocked_without_mutation_while_local_ai_job_is_active(): void
    {
        $user = User::factory()->create(['email' => 'workspace.owner@example.com']);
        $reservation = $this->reservation($user, ReservationStatus::Active, startsAt: now()->subMinute());
        $project = $this->project($user, 'movie-project');
        $runtime = $this->runningRuntime($user, $project);
        $reservation = $this->bindLocalAi($reservation, $runtime);
        $oldToken = (string) $reservation->broker_token;
        $sessionId = '01a04210-0e05-7b23-a664-61a40b35f11f';

        $broker = Mockery::mock(MockBrokerControlClient::class);
        $broker->shouldReceive('hasActiveJobs')->once()->withArgs(
            fn (Reservation $actual): bool => $actual->is($reservation),
        )->andReturnTrue();
        $broker->shouldNotReceive('revoke');
        $broker->shouldNotReceive('register');
        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->once()->andReturnUsing(
            fn (WorkspaceRuntime $actual): array => $this->runtimeStatus($actual, $project),
        );
        $manager->shouldReceive('runtimeSessions')->once()->andReturn([
            'available' => true,
            'sessions' => [[
                'id' => $sessionId,
                'title' => 'Continue movie edit',
                'started_at' => '2026-08-23T18:00:00Z',
                'updated_at' => '2026-08-24T16:00:00Z',
            ]],
            'current_session_id' => null,
        ]);
        $manager->shouldNotReceive('revokeLocalAi');
        $manager->shouldNotReceive('ensureRuntime');
        $manager->shouldNotReceive('updateRuntimeDeadline');
        $manager->shouldNotReceive('grantLocalAi');
        $this->app->instance(MockBrokerControlClient::class, $broker);
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->withSession($this->entrySession($project))
            ->postJson(route('workspace.sessions.select'), [
                'action' => 'resume',
                'session_id' => $sessionId,
            ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'A local AI image or video job is still running. Wait for it to finish before switching the Codex session.',
            );

        $reservation->refresh();
        $runtime->refresh();
        $this->assertSame(ReservationStatus::Active, $reservation->status);
        $this->assertSame($oldToken, $reservation->broker_token);
        $this->assertSame($runtime->id, $reservation->workspace_runtime_id);
        $this->assertSame(1, (int) $reservation->ai_grant_generation);
        $this->assertSame(1, $runtime->generation);
        $this->assertSame('new', $runtime->session_mode);
        $this->assertNull($runtime->session_id);
    }

    public function test_session_switch_race_restores_the_active_grant_when_a_job_starts_during_revoke(): void
    {
        $user = User::factory()->create(['email' => 'workspace.owner@example.com']);
        $reservation = $this->reservation($user, ReservationStatus::Active, startsAt: now()->subMinute());
        $project = $this->project($user, 'movie-project');
        $runtime = $this->runningRuntime($user, $project);
        $reservation = $this->bindLocalAi($reservation, $runtime);
        $oldToken = (string) $reservation->broker_token;
        $sessionId = '01a04210-0e05-7b23-a664-61a40b35f11f';

        $broker = Mockery::mock(MockBrokerControlClient::class);
        $broker->shouldReceive('hasActiveJobs')->once()->andReturnFalse();
        $broker->shouldReceive('revoke')->once()->withArgs(fn (
            Reservation $actual,
            WorkspaceRuntime $bound,
            bool $requireIdle,
            bool $preserveFiles,
        ): bool => $actual->is($reservation)
            && $bound->is($runtime)
            && $requireIdle
            && $preserveFiles)->andThrow(new \RuntimeException('reservation_has_active_jobs'));
        $broker->shouldNotReceive('register');
        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->once()->andReturnUsing(
            fn (WorkspaceRuntime $actual): array => $this->runtimeStatus($actual, $project),
        );
        $manager->shouldReceive('runtimeSessions')->once()->andReturn([
            'available' => true,
            'sessions' => [[
                'id' => $sessionId,
                'title' => 'Continue movie edit',
                'started_at' => '2026-08-23T18:00:00Z',
                'updated_at' => '2026-08-24T16:00:00Z',
            ]],
            'current_session_id' => null,
        ]);
        $manager->shouldNotReceive('revokeLocalAi');
        $manager->shouldNotReceive('ensureRuntime');
        $manager->shouldNotReceive('updateRuntimeDeadline');
        $manager->shouldNotReceive('grantLocalAi');
        $this->app->instance(MockBrokerControlClient::class, $broker);
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->withSession($this->entrySession($project))
            ->postJson(route('workspace.sessions.select'), [
                'action' => 'resume',
                'session_id' => $sessionId,
            ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'A local AI image or video job is still running. Wait for it to finish before switching the Codex session.',
            );

        $reservation->refresh();
        $runtime->refresh();
        $this->assertSame(ReservationStatus::Active, $reservation->status);
        $this->assertNull($reservation->end_reason);
        $this->assertSame($oldToken, $reservation->broker_token);
        $this->assertSame($runtime->id, $reservation->workspace_runtime_id);
        $this->assertSame(1, (int) $reservation->ai_grant_generation);
        $this->assertSame(1, $runtime->generation);
        $this->assertSame('new', $runtime->session_mode);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'local_ai.session_switch_blocked',
            'target_id' => $reservation->id,
        ]);
    }

    public function test_session_endpoints_require_the_entry_session_and_validate_resume_uuid(): void
    {
        $user = User::factory()->create();
        $this->reservation($user, ReservationStatus::Active, startsAt: now()->subMinute());
        $project = $this->project($user);
        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldNotReceive('sessions');
        $manager->shouldNotReceive('selectSession');
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->withSession([
            WorkspaceProjectService::SESSION_KEY => $project->id,
        ])->getJson(route('workspace.sessions.index'))->assertForbidden();

        $this->actingAs($user)->withSession($this->entrySession($project))
            ->postJson(route('workspace.sessions.select'), [
                'action' => 'resume',
                'session_id' => '../another-project',
            ])->assertUnprocessable()->assertJsonValidationErrors('session_id');
    }

    public function test_history_session_delete_is_confirmed_current_safe_and_delegated_to_codex(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $runtime = $this->runningRuntime($user, $project);
        $currentId = '12345678-1234-4123-8123-123456789abc';
        $historyId = '22345678-1234-4123-8123-123456789abc';
        $runtime->forceFill(['session_mode' => 'resume', 'session_id' => $currentId])->save();
        $listing = [
            'available' => true,
            'sessions' => [
                ['id' => $currentId, 'title' => 'Current', 'started_at' => '2026-08-24T15:00:00Z', 'updated_at' => '2026-08-24T16:00:00Z'],
                ['id' => $historyId, 'title' => 'History', 'started_at' => '2026-08-23T15:00:00Z', 'updated_at' => '2026-08-23T16:00:00Z'],
            ],
            'current_session_id' => $currentId,
        ];

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->twice()->andReturnUsing(
            fn (WorkspaceRuntime $actual): array => $this->runtimeStatus($actual, $project),
        );
        $manager->shouldReceive('runtimeSessions')->twice()->andReturn($listing);
        $manager->shouldReceive('deleteRuntimeSession')->once()->withArgs(fn (
            WorkspaceRuntime $actual,
            WorkspaceProject $selected,
            string $sessionId,
        ): bool => $actual->is($runtime) && $selected->is($project) && $sessionId === $historyId)
            ->andReturn(['deleted' => true, 'session_id' => $historyId]);
        $this->app->instance(WorkspaceManagerClient::class, $manager);
        $session = $this->entrySession($project);

        $this->actingAs($user)->withSession($session)
            ->deleteJson(route('workspace.sessions.destroy', ['sessionId' => $currentId]), [
                'confirmed' => true,
            ])->assertConflict()->assertJsonPath('message', 'The current Codex session cannot be deleted. Switch sessions first.');

        $this->actingAs($user)->withSession($session)
            ->deleteJson(route('workspace.sessions.destroy', ['sessionId' => $historyId]), [
                'confirmed' => true,
            ])->assertOk()
            ->assertJsonPath('deleted', true)
            ->assertJsonPath('session_id', $historyId);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'workspace.session_deleted',
            'target_id' => $runtime->id,
        ]);
    }

    public function test_extending_an_active_workspace_immediately_refreshes_runtime_deadlines(): void
    {
        $user = User::factory()->create();
        $reservation = $this->reservation($user, ReservationStatus::Active, startsAt: now()->subMinute());
        $project = $this->project($user);
        $runtime = $this->runningRuntime($user, $project);
        $runtime->forceFill(['idle_expires_at' => $reservation->ends_at->addMinutes(10)])->save();
        $reservation = $this->bindLocalAi($reservation, $runtime);

        $broker = Mockery::mock(MockBrokerControlClient::class);
        $broker->shouldReceive('register')->once()->withArgs(
            fn (Reservation $actual, string $token, WorkspaceRuntime $bound): bool => $actual->is($reservation)
                && $token === str_repeat('a', 96)
                && $bound->is($runtime)
                && $actual->ends_at->equalTo(CarbonImmutable::parse('2026-08-24T12:00:00-07:00')),
        );
        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('updateRuntimeDeadline')->once()->withArgs(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime)
                && $actual->idle_expires_at->equalTo(CarbonImmutable::parse('2026-08-24T12:10:00-07:00')),
        );
        $manager->shouldReceive('grantLocalAi')->once()->withArgs(
            fn (WorkspaceRuntime $bound, Reservation $actual, string $token): bool => $bound->is($runtime)
                && $actual->is($reservation)
                && $token === str_repeat('a', 96),
        )->andReturn(['granted' => true]);
        $this->app->instance(MockBrokerControlClient::class, $broker);
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->post(route('reservations.extend', $reservation), [
            'ends_at' => '2026-08-24T12:00:00-07:00',
        ])->assertRedirect()->assertSessionHas('status', 'Reservation extended.');

        $this->assertTrue($reservation->refresh()->ends_at->equalTo(CarbonImmutable::parse('2026-08-24T12:00:00-07:00')));
    }

    public function test_every_plain_workspace_entry_requires_a_fresh_company_or_personal_choice(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $response = $this->actingAs($user)->withSession([
            ...$this->entrySession($project, 'company', 'old-entry'),
        ])->get(route('workspace.terminal'))
            ->assertOk()
            ->assertSee('data-testid="codex-account-modal"', false)
            ->assertSee('data-codex-account-choice', false)
            ->assertSee('data-testid="codex-account-loading"', false)
            ->assertSee('data-codex-account-loading', false)
            ->assertSee('Use company Codex account')
            ->assertSee('Use my Codex account')
            ->assertSee('Preparing your Workspace')
            ->assertDontSee('workspace-terminal-frame', false)
            ->assertSessionMissing('workspace.codex_auth_mode')
            ->assertSessionMissing('workspace.entry_token')
            ->assertSessionHas('workspace.auth_mode_attempt', fn ($value): bool => is_string($value) && strlen($value) === 48);

        $this->assertSame(2, substr_count($response->getContent(), 'data-codex-account-form'));
        $this->assertSame(2, substr_count($response->getContent(), 'data-codex-account-submit'));
        $this->assertSame(2, substr_count($response->getContent(), 'name="auth_attempt"'));
        $javascript = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("form.addEventListener('submit', submitOnce)", $javascript);
        $this->assertStringContainsString("codexAccountChoice.dataset.submitting = 'true'", $javascript);
        $this->assertStringContainsString("loading?.classList.remove('hidden')", $javascript);
        $this->assertStringContainsString('button.disabled = submitting || !selectable', $javascript);
    }

    public function test_invalid_entry_token_redirects_once_and_disables_terminal_auto_refresh_until_account_choice(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $reservation = $this->reservation($user, ReservationStatus::Active, startsAt: now()->subMinute());
        $runtime = $this->runningRuntime($user, $project);
        $this->bindLocalAi($reservation, $runtime);

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->once()->with(Mockery::on(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime),
        ))->andReturn($this->runtimeStatus($runtime, $project, ['ai_network_connected' => true]));
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->withSession([
            ...$this->entrySession($project, 'personal', 'valid-entry'),
        ])->get(route('workspace.terminal', ['entry' => 'invalid-test-token']))
            ->assertRedirect(route('workspace.terminal'))
            ->assertSessionMissing('workspace.codex_auth_mode')
            ->assertSessionMissing('workspace.entry_token');

        $this->actingAs($user)->get(route('workspace.terminal'))
            ->assertOk()
            ->assertSee('data-testid="codex-account-modal"', false)
            ->assertSee('data-terminal-refresh-enabled="false"', false)
            ->assertDontSee('workspace-terminal-frame', false);
    }

    public function test_workspace_can_switch_to_the_company_codex_account_before_a_reservation(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $runtime = $this->runningRuntime($user, $project);

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->once()->with(Mockery::on(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime),
        ))->andReturn($this->runtimeStatus($runtime, $project));
        $manager->shouldReceive('assertCompanyVolumeAvailable')->once();
        $manager->shouldReceive('ensureRuntime')->once()->withArgs(fn (
            WorkspaceRuntime $actual,
            WorkspaceProfile $profile,
            WorkspaceProject $selected,
        ): bool => $actual->is($runtime)
            && $actual->generation === 2
            && $actual->auth_mode === 'company'
            && $profile->user_id === $user->id
            && $selected->is($project))->andReturnUsing(
                fn (WorkspaceRuntime $actual): array => $this->runtimeStatus($actual, $project),
            );
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $response = $this->actingAs($user)->withSession([
            WorkspaceProjectService::SESSION_KEY => $project->id,
        ])->post(route('workspace.auth-mode'), ['auth_mode' => 'company']);

        $response->assertRedirectContains('/workspace/terminal?entry=')
            ->assertSessionHas('workspace.codex_auth_mode', 'company')
            ->assertSessionHas('workspace.entry_token');
        $this->assertDatabaseHas('audit_events', [
            'action' => 'workspace.auth_mode_selected',
            'target_id' => $runtime->id,
        ]);
    }

    public function test_duplicate_account_selection_attempt_is_idempotent_and_keeps_one_entry_token(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);
        $runtime = $this->runningRuntime($user, $project);
        $attempt = str_repeat('x', 48);

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->once()->with(Mockery::on(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime),
        ))->andReturn($this->runtimeStatus($runtime, $project));
        $manager->shouldReceive('updateRuntimeDeadline')->once()->with(Mockery::on(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime) && $actual->generation === 1,
        ));
        $manager->shouldNotReceive('ensureRuntime');
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $session = [
            WorkspaceProjectService::SESSION_KEY => $project->id,
            'workspace.auth_mode_attempt' => $attempt,
        ];
        $first = $this->actingAs($user)->withSession($session)
            ->post(route('workspace.auth-mode'), [
                'auth_mode' => 'personal',
                'auth_attempt' => $attempt,
            ]);
        $first->assertRedirectContains('/workspace/terminal?entry=');
        $firstLocation = $first->headers->get('Location');

        $second = $this->actingAs($user)->withSession($session)
            ->post(route('workspace.auth-mode'), [
                'auth_mode' => 'personal',
                'auth_attempt' => $attempt,
            ]);
        $second->assertRedirect($firstLocation)
            ->assertSessionHas('workspace.codex_auth_mode', 'personal');
        $this->assertSame(1, $runtime->refresh()->generation);
    }

    public function test_account_selection_reconciles_a_stale_portal_generation_to_the_healthy_manager_runtime(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $project = $this->project($user);
        $runtime = $this->runningRuntime($user, $project, generation: 47);
        $runtime->forceFill([
            'status' => 'stopped',
            'failure_reason' => 'ai_grant_active',
            'stopped_at' => now(),
        ])->save();
        $reservation = $this->reservation($user, ReservationStatus::Active, startsAt: now()->subMinute());
        $reservation->forceFill([
            'workspace_runtime_id' => $runtime->id,
            'ai_grant_generation' => 34,
            'ai_granted_at' => now()->subMinute(),
        ])->save();
        $managerStatus = [
            ...$this->runtimeStatus($runtime, $project, ['ai_network_connected' => true]),
            'generation' => 34,
        ];

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->once()->with(Mockery::on(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime),
        ))->andReturn($managerStatus);
        $manager->shouldReceive('updateRuntimeDeadline')->once()->with(Mockery::on(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime)
                && $actual->status === 'running'
                && $actual->generation === 34,
        ));
        $manager->shouldNotReceive('ensureRuntime');
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->withSession([
            WorkspaceProjectService::SESSION_KEY => $project->id,
        ])->post(route('workspace.auth-mode'), ['auth_mode' => 'personal'])
            ->assertRedirectContains('/workspace/terminal?entry=')
            ->assertSessionHas('workspace.codex_auth_mode', 'personal');

        $runtime->refresh();
        $reservation->refresh();
        $this->assertSame('running', $runtime->status);
        $this->assertSame(34, $runtime->generation);
        $this->assertNull($runtime->failure_reason);
        $this->assertNull($runtime->stopped_at);
        $this->assertSame(34, (int) $reservation->ai_grant_generation);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'workspace.runtime_reconciled',
            'target_id' => $runtime->id,
        ]);
    }

    public function test_runtime_mutation_lock_is_held_while_manager_provisions_the_container(): void
    {
        $user = User::factory()->create();
        $project = $this->project($user);

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('ensureRuntime')->once()->andReturnUsing(function (
            WorkspaceRuntime $runtime,
            WorkspaceProfile $profile,
            WorkspaceProject $selected,
        ): array {
            $competing = Cache::lock('workspace-runtime-mutation:'.$runtime->user_id, 1);
            $this->assertFalse($competing->get());

            return $this->runtimeStatus($runtime, $selected);
        });
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->withSession([
            WorkspaceProjectService::SESSION_KEY => $project->id,
        ])->post(route('workspace.auth-mode'), ['auth_mode' => 'personal'])
            ->assertRedirectContains('/workspace/terminal?entry=');

        $this->assertSame(1, $user->workspaceRuntime()->value('generation'));
    }

    public function test_project_switch_refreshes_only_the_owned_workspace_runtime_before_local_ai_starts(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $first = $this->project($user, 'first-project');
        $runtime = $this->runningRuntime($user, $first);
        $second = WorkspaceProject::create([
            'user_id' => $user->id,
            'name' => 'Second project',
            'directory_name' => 'second-project',
        ]);

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')->once()->with(Mockery::on(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime),
        ))->andReturn($this->runtimeStatus($runtime, $first));
        $manager->shouldReceive('ensureRuntime')->once()->withArgs(fn (
            WorkspaceRuntime $actual,
            WorkspaceProfile $profile,
            WorkspaceProject $project,
        ): bool => $actual->is($runtime)
            && $actual->generation === 2
            && $actual->workspace_project_id === $second->id
            && $profile->root_directory === 'admin@example.com'
            && $project->is($second))->andReturnUsing(
                fn (WorkspaceRuntime $actual): array => $this->runtimeStatus($actual, $second),
            );
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($user)->post(route('workspace.projects.select', $second))
            ->assertRedirect(route('workspace.terminal'));
        $this->assertSame($second->id, $user->workspaceProfile()->value('selected_project_id'));
    }

    public function test_user_cannot_select_another_users_project(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $project = $this->project($owner);

        $this->actingAs($other)->post(route('workspace.projects.select', $project))->assertNotFound();
        $this->actingAs($other)->put(route('workspace.projects.update', $project), [
            'name' => 'Stolen project',
            'directory_name' => 'stolen-project',
        ])->assertNotFound();
        $this->actingAs($other)->delete(route('workspace.projects.destroy', $project), [
            'delete_confirmation' => 'delete',
        ])->assertNotFound();
        $this->assertSame('movie-project', $project->refresh()->directory_name);
        $this->assertNull($other->workspaceProfile()->first());
    }

    private function reservation(
        User $user,
        ReservationStatus $status,
        mixed $startsAt = null,
    ): Reservation {
        $start = CarbonImmutable::parse($startsAt ?? now()->addMinute());
        $end = $start->addHours(2)->startOfHour();

        return Reservation::create([
            'user_id' => $user->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'lock_starts_at' => $start,
            'lock_ends_at' => $end,
            'status' => $status,
            'activated_at' => $status === ReservationStatus::Active ? now()->subMinutes(2) : null,
            'broker_token' => $status === ReservationStatus::Active ? str_repeat('a', 96) : null,
        ]);
    }

    private function project(User $user, string $directory = 'movie-project'): WorkspaceProject
    {
        $profile = WorkspaceProfile::query()->create([
            'user_id' => $user->id,
            'storage_uuid' => (string) Str::uuid(),
            'root_directory' => mb_strtolower($user->email),
        ]);
        $project = WorkspaceProject::query()->create([
            'user_id' => $user->id,
            'name' => Str::headline($directory),
            'directory_name' => $directory,
        ]);
        $profile->update(['selected_project_id' => $project->id]);

        return $project;
    }

    private function entrySession(
        WorkspaceProject $project,
        string $authMode = 'personal',
        string $entryToken = 'test-entry',
    ): array {
        return [
            WorkspaceProjectService::SESSION_KEY => $project->id,
            'workspace.codex_auth_mode' => $authMode,
            'workspace.entry_token' => $entryToken,
        ];
    }

    private function runningRuntime(
        User $user,
        WorkspaceProject $project,
        string $authMode = 'personal',
        int $generation = 1,
    ): WorkspaceRuntime {
        return WorkspaceRuntime::query()->create([
            'user_id' => $user->id,
            'workspace_project_id' => $project->id,
            'status' => 'running',
            'auth_mode' => $authMode,
            'session_mode' => 'new',
            'generation' => $generation,
            'container_name' => 'movie-ws-'.Str::uuid(),
            'network_name' => 'movie_ws_'.Str::lower(Str::random(12)),
            'last_seen_at' => now(),
            'idle_expires_at' => now()->addHours(4),
            'started_at' => now()->subMinute(),
        ]);
    }

    private function bindLocalAi(Reservation $reservation, WorkspaceRuntime $runtime): Reservation
    {
        $reservation->forceFill([
            'workspace_runtime_id' => $runtime->id,
            'ai_grant_generation' => $runtime->generation,
            'ai_granted_at' => now()->subMinute(),
        ])->save();

        return $reservation->refresh();
    }

    private function runtimeStatus(WorkspaceRuntime $runtime, WorkspaceProject $project, array $extra = []): array
    {
        return [
            'running' => true,
            'healthy' => true,
            'user_id' => $runtime->user_id,
            'runtime_id' => $runtime->id,
            'generation' => $runtime->generation,
            'workspace_root' => mb_strtolower($runtime->user->email),
            'project_id' => $project->id,
            'project_directory' => $project->directory_name,
            'auth_mode' => $runtime->auth_mode,
            'session_mode' => $runtime->session_mode,
            'session_id' => $runtime->session_id,
            ...$extra,
        ];
    }
}
