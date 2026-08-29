<?php

namespace Tests\Feature\Gate2;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WorkspaceProject;
use App\Models\WorkspaceRuntime;
use App\Services\MockBrokerControlClient;
use App\Services\ReservationService;
use App\Services\WorkspaceManagerClient;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class AdminReservationForceCancelTest extends TestCase
{
    use DatabaseMigrations;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-28T18:00:00Z');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_admin_can_force_cancel_another_users_confirmed_reservation(): void
    {
        $reservation = $this->reservation(ReservationStatus::Confirmed);

        Livewire::test(ListReservations::class)
            ->assertActionExists(
                TestAction::make('forceCancel')->table($reservation),
                fn (Action $action): bool => $action->getLivewireClickHandler() === "mountTableAction('forceCancel', '{$reservation->id}')"
                    && $action->hasModal() === false
                    && str_contains($action->getExtraAttributes()['wire:confirm'] ?? '', $reservation->user->name),
            )
            ->assertActionVisible(TestAction::make('forceCancel')->table($reservation))
            ->callAction(TestAction::make('forceCancel')->table($reservation))
            ->assertNotified('Reservation force cancelled');

        $reservation->refresh();
        $this->assertSame(ReservationStatus::Cancelled, $reservation->status);
        $this->assertSame('admin_cancel', $reservation->end_reason);
        $this->assertNotNull($reservation->cancelled_at);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $this->admin->id,
            'action' => 'reservation.force_cancelled',
            'target_id' => $reservation->id,
        ]);
    }

    public function test_admin_force_cancel_revokes_an_active_ai_grant_but_preserves_workspace(): void
    {
        $owner = User::factory()->create();
        $project = WorkspaceProject::create([
            'user_id' => $owner->id,
            'name' => 'Preserved project',
            'directory_name' => 'preserved-project',
        ]);
        $runtime = WorkspaceRuntime::create([
            'user_id' => $owner->id,
            'workspace_project_id' => $project->id,
            'status' => 'running',
            'auth_mode' => 'personal',
            'generation' => 3,
        ]);
        $reservation = $this->reservation(ReservationStatus::Active, $owner, $runtime);

        $broker = Mockery::mock(MockBrokerControlClient::class);
        $broker->shouldReceive('revoke')
            ->once()
            ->with(
                Mockery::on(fn (Reservation $value): bool => $value->id === $reservation->id),
                Mockery::on(fn (WorkspaceRuntime $value): bool => $value->id === $runtime->id),
            );
        $manager = Mockery::mock(WorkspaceManagerClient::class);
        $manager->shouldReceive('runtimeStatus')
            ->once()
            ->andReturn([
                'running' => true,
                'generation' => 3,
                'ai_network_connected' => true,
            ]);
        $manager->shouldReceive('revokeLocalAi')
            ->once()
            ->with(
                Mockery::on(fn (WorkspaceRuntime $value): bool => $value->id === $runtime->id),
                Mockery::on(fn (Reservation $value): bool => $value->id === $reservation->id),
                3,
            );
        $this->app->instance(MockBrokerControlClient::class, $broker);
        $this->app->instance(WorkspaceManagerClient::class, $manager);

        $cancelled = app(ReservationService::class)->forceCancel($reservation, $this->admin);

        $this->assertSame(ReservationStatus::Cancelled, $cancelled->status);
        $this->assertSame('admin_cancel', $cancelled->end_reason);
        $this->assertNull($cancelled->broker_token);
        $this->assertNull($cancelled->workspace_runtime_id);
        $this->assertSame('running', $runtime->refresh()->status);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $this->admin->id,
            'action' => 'reservation.force_cancelled',
            'target_id' => $reservation->id,
        ]);
    }

    public function test_terminal_reservations_have_no_force_cancel_action(): void
    {
        $reservation = $this->reservation(ReservationStatus::Completed);

        Livewire::test(ListReservations::class)
            ->assertActionHidden(TestAction::make('forceCancel')->table($reservation));
    }

    public function test_non_admin_cannot_call_force_cancel_service(): void
    {
        $reservation = $this->reservation(ReservationStatus::Confirmed);
        $user = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        app(ReservationService::class)->forceCancel($reservation, $user);
    }

    private function reservation(
        ReservationStatus $status,
        ?User $owner = null,
        ?WorkspaceRuntime $runtime = null,
    ): Reservation {
        $owner ??= User::factory()->create();
        $startsAt = $status === ReservationStatus::Confirmed
            ? CarbonImmutable::now()->addHour()
            : CarbonImmutable::now()->subHour();

        return Reservation::create([
            'user_id' => $owner->id,
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::now()->addHours(2),
            'lock_starts_at' => $startsAt,
            'lock_ends_at' => CarbonImmutable::now()->addHours(2),
            'status' => $status,
            'activated_at' => $status === ReservationStatus::Active ? CarbonImmutable::now()->subHour() : null,
            'broker_token' => $status === ReservationStatus::Active ? str_repeat('t', 96) : null,
            'workspace_runtime_id' => $runtime?->id,
            'ai_grant_generation' => $runtime?->generation,
            'ai_granted_at' => $status === ReservationStatus::Active ? CarbonImmutable::now()->subHour() : null,
        ]);
    }
}
