<?php

namespace Tests\Feature\Gate3;

use App\Enums\ReservationStatus;
use App\Models\CompanyCodexLease;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WorkspaceProfile;
use App\Models\WorkspaceProject;
use App\Models\WorkspaceRuntime;
use App\Services\MockBrokerControlClient;
use App\Services\WorkspaceManagerClient;
use App\Services\WorkspaceProjectService;
use App\Services\WorkspaceRuntimeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class WorkspaceMultiUserTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('movie.workspace_enabled', true);
        config()->set('movie.company_codex_enabled', true);
        config()->set('movie.workspace_idle_minutes', 240);
        CarbonImmutable::setTestNow('2026-08-27T03:30:00Z');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_two_users_can_start_distinct_personal_codex_runtimes_without_local_ai(): void
    {
        [$first, $firstProject] = $this->userProject('first@example.com', 'first-project');
        [$second, $secondProject] = $this->userProject('second@example.com', 'second-project');

        $broker = Mockery::mock(MockBrokerControlClient::class);
        $broker->shouldNotReceive('register');
        $broker->shouldNotReceive('revoke');
        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('ensureRuntime')->twice()->andReturnUsing(
            fn (WorkspaceRuntime $runtime, WorkspaceProfile $profile, WorkspaceProject $project): array => $this->managerStatus($runtime, $profile, $project),
        );
        $manager->shouldNotReceive('grantLocalAi');
        $this->app->instance(MockBrokerControlClient::class, $broker);
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($first)->withSession($this->projectSession($firstProject))
            ->post(route('workspace.auth-mode'), ['auth_mode' => 'personal'])
            ->assertRedirectContains('/workspace/terminal?entry=');
        $this->actingAs($second)->withSession($this->projectSession($secondProject))
            ->post(route('workspace.auth-mode'), ['auth_mode' => 'personal'])
            ->assertRedirectContains('/workspace/terminal?entry=');

        $firstRuntime = $first->workspaceRuntime()->firstOrFail();
        $secondRuntime = $second->workspaceRuntime()->firstOrFail();
        $this->assertNotSame($firstRuntime->id, $secondRuntime->id);
        $this->assertSame($firstProject->id, $firstRuntime->workspace_project_id);
        $this->assertSame($secondProject->id, $secondRuntime->workspace_project_id);
        $this->assertSame('running', $firstRuntime->status);
        $this->assertSame('running', $secondRuntime->status);
        $this->assertSame(0, Reservation::query()->whereNotNull('broker_token')->count());
    }

    public function test_company_codex_is_disabled_for_a_second_user_while_personal_codex_stays_available(): void
    {
        [$first, $firstProject] = $this->userProject('company-owner@example.com', 'company-project');
        [$second, $secondProject] = $this->userProject('waiting-user@example.com', 'waiting-project');

        $broker = Mockery::mock(MockBrokerControlClient::class);
        $broker->shouldNotReceive('register');
        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('assertCompanyVolumeAvailable')->once();
        $manager->shouldReceive('runtimeStatus')->once()->andReturn(['running' => false]);
        $manager->shouldReceive('ensureRuntime')->twice()->andReturnUsing(
            fn (WorkspaceRuntime $runtime, WorkspaceProfile $profile, WorkspaceProject $project): array => $this->managerStatus($runtime, $profile, $project),
        );
        $this->app->instance(MockBrokerControlClient::class, $broker);
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $this->actingAs($first)->withSession($this->projectSession($firstProject))
            ->post(route('workspace.auth-mode'), ['auth_mode' => 'company'])
            ->assertRedirectContains('/workspace/terminal?entry=');

        $lease = CompanyCodexLease::query()->findOrFail(CompanyCodexLease::SINGLETON_ID);
        $firstRuntime = $first->workspaceRuntime()->firstOrFail();
        $this->assertSame('active', $lease->status);
        $this->assertSame($firstRuntime->id, $lease->workspace_runtime_id);

        $this->actingAs($second)->withSession($this->projectSession($secondProject))
            ->get(route('workspace.terminal'))
            ->assertOk()
            ->assertSee('data-company-state="occupied"', false)
            ->assertSee('Company Codex is currently occupied')
            ->assertSee('Only one user can use company Codex at a time.');

        $this->actingAs($second)->withSession($this->projectSession($secondProject))
            ->post(route('workspace.auth-mode'), ['auth_mode' => 'company'])
            ->assertSessionHasErrors('auth_mode');

        $this->actingAs($second)->withSession($this->projectSession($secondProject))
            ->post(route('workspace.auth-mode'), ['auth_mode' => 'personal'])
            ->assertRedirectContains('/workspace/terminal?entry=');

        $secondRuntime = $second->workspaceRuntime()->firstOrFail();
        $this->assertSame('personal', $secondRuntime->auth_mode);
        $this->assertSame('running', $secondRuntime->status);
        $this->assertSame($firstRuntime->id, $lease->fresh()->workspace_runtime_id);
        $this->assertSame('running', $firstRuntime->fresh()->status);
    }

    public function test_local_ai_binding_cannot_enable_a_different_users_runtime(): void
    {
        [$reservedUser, $reservedProject] = $this->userProject('reserved@example.com', 'reserved-project');
        [$reviewUser, $reviewProject] = $this->userProject('review@example.com', 'review-project');
        $reservedRuntime = $this->runtime($reservedUser, $reservedProject);
        $reviewRuntime = $this->runtime($reviewUser, $reviewProject);
        $reservation = $this->activeReservation($reservedUser, $reservedRuntime);
        $status = ['running' => true, 'healthy' => true, 'ai_network_connected' => true];

        $service = app(WorkspaceRuntimeService::class);
        $this->assertTrue($service->localAiEnabled($reservation, $reservedRuntime, $status));
        $this->assertFalse($service->localAiEnabled($reservation, $reviewRuntime, $status));
        $this->assertFalse($service->localAiEnabled(null, $reviewRuntime, $status));
    }

    public function test_stopping_one_runtime_never_stops_the_other_users_runtime(): void
    {
        [$first, $firstProject] = $this->userProject('stop-me@example.com', 'stop-project');
        [$second, $secondProject] = $this->userProject('keep-me@example.com', 'keep-project');
        $firstRuntime = $this->runtime($first, $firstProject);
        $secondRuntime = $this->runtime($second, $secondProject);

        $broker = Mockery::mock(MockBrokerControlClient::class);
        $broker->shouldNotReceive('revoke');
        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('stopRuntime')->once()->with(Mockery::on(
            fn (WorkspaceRuntime $runtime): bool => $runtime->is($firstRuntime),
        ))->andReturn(['stopped' => true]);
        $this->app->instance(MockBrokerControlClient::class, $broker);
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        app(WorkspaceRuntimeService::class)->stopRuntime($first);

        $this->assertSame('stopped', $firstRuntime->fresh()->status);
        $this->assertSame('running', $secondRuntime->fresh()->status);
    }

    public function test_runtime_heartbeat_is_throttled_and_never_shortens_a_protected_deadline(): void
    {
        [$user, $project] = $this->userProject('heartbeat@example.com', 'heartbeat-project');
        $runtime = $this->runtime($user, $project);
        $protectedDeadline = now()->addHours(6);
        $runtime->forceFill([
            'last_seen_at' => now()->subMinutes(2),
            'idle_expires_at' => $protectedDeadline,
        ])->save();

        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('updateRuntimeDeadline')->once()->with(Mockery::on(
            fn (WorkspaceRuntime $actual): bool => $actual->is($runtime)
                && $actual->idle_expires_at->equalTo($protectedDeadline),
        ));
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $service = app(WorkspaceRuntimeService::class);
        $service->heartbeatRuntime($user);
        $service->heartbeatRuntime($user);

        $this->assertTrue($runtime->fresh()->idle_expires_at->equalTo($protectedDeadline));
        $this->assertTrue($runtime->fresh()->last_seen_at->equalTo(now()));
    }

    private function userProject(string $email, string $directory): array
    {
        $user = User::factory()->create(['email' => $email]);
        $profile = WorkspaceProfile::query()->create([
            'user_id' => $user->id,
            'storage_uuid' => (string) Str::uuid(),
            'root_directory' => mb_strtolower($email),
        ]);
        $project = WorkspaceProject::query()->create([
            'user_id' => $user->id,
            'name' => Str::headline($directory),
            'directory_name' => $directory,
        ]);
        $profile->forceFill(['selected_project_id' => $project->id])->save();

        return [$user, $project];
    }

    private function projectSession(WorkspaceProject $project): array
    {
        return [WorkspaceProjectService::SESSION_KEY => $project->id];
    }

    private function runtime(User $user, WorkspaceProject $project): WorkspaceRuntime
    {
        return WorkspaceRuntime::query()->create([
            'user_id' => $user->id,
            'workspace_project_id' => $project->id,
            'status' => 'running',
            'auth_mode' => 'personal',
            'session_mode' => 'new',
            'generation' => 1,
            'container_name' => 'movie-ws-'.Str::uuid(),
            'network_name' => 'movie_ws_'.Str::lower(Str::random(12)),
            'last_seen_at' => now(),
            'idle_expires_at' => now()->addHours(4),
            'started_at' => now(),
        ]);
    }

    private function activeReservation(User $user, WorkspaceRuntime $runtime): Reservation
    {
        return Reservation::query()->create([
            'user_id' => $user->id,
            'starts_at' => now()->subMinutes(30),
            'ends_at' => now()->addMinutes(30)->startOfHour(),
            'lock_starts_at' => now()->subMinutes(30),
            'lock_ends_at' => now()->addMinutes(30)->startOfHour(),
            'status' => ReservationStatus::Active,
            'activated_at' => now()->subMinutes(30),
            'broker_token' => str_repeat('a', 96),
            'workspace_runtime_id' => $runtime->id,
            'ai_grant_generation' => $runtime->generation,
            'ai_granted_at' => now()->subMinutes(30),
        ]);
    }

    private function managerStatus(
        WorkspaceRuntime $runtime,
        WorkspaceProfile $profile,
        WorkspaceProject $project,
    ): array {
        return [
            'running' => true,
            'healthy' => true,
            'runtime_id' => $runtime->id,
            'user_id' => $runtime->user_id,
            'generation' => $runtime->generation,
            'workspace_root' => $profile->root_directory,
            'project_id' => $project->id,
            'project_directory' => $project->directory_name,
            'auth_mode' => $runtime->auth_mode,
            'session_mode' => $runtime->session_mode,
            'session_id' => $runtime->session_id,
            'container_name' => 'movie-ws-'.$runtime->id,
            'network_name' => 'movie_ws_'.str_replace('-', '', $runtime->id),
        ];
    }
}
