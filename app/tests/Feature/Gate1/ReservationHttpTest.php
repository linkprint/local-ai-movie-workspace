<?php

namespace Tests\Feature\Gate1;

use App\Enums\ReservationStatus;
use App\Models\ComputeNode;
use App\Models\Reservation;
use App\Models\User;
use App\Services\WorkspaceRuntimeService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class ReservationHttpTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_authenticated_user_can_create_and_cancel_own_future_reservation(): void
    {
        CarbonImmutable::setTestNow('2026-08-23T00:00:00Z');
        $user = User::factory()->create();

        $this->actingAs($user)->post('/reservations', [
            'compute_node_id' => ComputeNode::LOCAL_NODE_ID,
            'starts_at' => '2026-08-24T10:00:00-07:00',
            'ends_at' => '2026-08-24T12:00:00-07:00',
            'purpose' => 'Storyboard review',
        ])->assertRedirect(route('reservations.index'));

        $reservation = $user->reservations()->firstOrFail();
        $this->assertTrue($reservation->lock_starts_at->equalTo($reservation->starts_at));
        $this->assertTrue($reservation->lock_ends_at->equalTo($reservation->ends_at));
        $this->actingAs($user)->delete(route('reservations.destroy', $reservation))->assertRedirect();
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'cancelled', 'end_reason' => 'user_cancel']);
        $this->assertDatabaseHas('audit_events', ['action' => 'reservation.created', 'target_id' => $reservation->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'reservation.cancelled', 'target_id' => $reservation->id]);
    }

    public function test_timestamp_without_explicit_offset_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/reservations', [
            'compute_node_id' => ComputeNode::LOCAL_NODE_ID,
            'starts_at' => '2026-08-24T10:00:00',
            'ends_at' => '2026-08-24T12:00:00',
        ])->assertSessionHasErrors(['starts_at', 'ends_at']);
    }

    public function test_immediate_start_is_current_and_workspace_visible_as_soon_as_booking_completes(): void
    {
        CarbonImmutable::setTestNow('2026-08-23T16:07:42Z');
        $user = User::factory()->create();

        $this->actingAs($user)->post('/reservations', [
            'compute_node_id' => ComputeNode::LOCAL_NODE_ID,
            'starts_at' => '2026-08-23T09:07:00-07:00',
            'start_immediately' => '1',
            'ends_at' => '2026-08-23T11:00:00-07:00',
        ])->assertRedirect(route('reservations.index'));

        $reservation = $user->reservations()->firstOrFail();
        $this->assertSame('2026-08-23T16:07:42+00:00', $reservation->starts_at->toIso8601String());
        $this->assertSame($reservation->id, app(WorkspaceRuntimeService::class)->currentFor($user)?->id);
    }

    public function test_arbitrary_non_grid_start_remains_rejected(): void
    {
        CarbonImmutable::setTestNow('2026-08-23T00:00:00Z');
        $user = User::factory()->create();

        $this->actingAs($user)->post('/reservations', [
            'compute_node_id' => ComputeNode::LOCAL_NODE_ID,
            'starts_at' => '2026-08-24T10:07:00-07:00',
            'ends_at' => '2026-08-24T12:00:00-07:00',
        ])->assertSessionHasErrors('starts_at');

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_active_owner_sees_extension_control_without_queue_identity_and_can_extend(): void
    {
        CarbonImmutable::setTestNow('2026-08-24T17:00:00-07:00');
        $user = User::factory()->create();
        $nextOwner = User::factory()->create(['email' => 'private-next-user@example.com']);
        $active = $this->reservation($user, '2026-08-24T16:00:00-07:00', '2026-08-24T18:00:00-07:00', ReservationStatus::Active);
        $this->reservation($nextOwner, '2026-08-24T20:15:00-07:00', '2026-08-24T22:00:00-07:00', purpose: 'Confidential queued work');

        $this->actingAs($user)->get(route('reservations.index'))
            ->assertOk()
            ->assertSee('Extend reservation')
            ->assertSee('2026-08-24T20:00:00-07:00', false)
            ->assertDontSee('2026-08-24T21:00:00-07:00', false)
            ->assertDontSee('private-next-user@example.com')
            ->assertDontSee('Confidential queued work');

        $this->actingAs($user)->post(route('reservations.extend', $active), [
            'ends_at' => '2026-08-24T20:00:00-07:00',
        ])->assertRedirect()->assertSessionHas('status', 'Reservation extended.');

        $this->assertSame('2026-08-25T03:00:00+00:00', $active->refresh()->ends_at->toIso8601String());
        $this->assertDatabaseHas('audit_events', ['action' => 'reservation.extended', 'target_id' => $active->id]);
    }

    public function test_extension_cannot_overlap_a_queued_reservation_or_exceed_eight_hours(): void
    {
        CarbonImmutable::setTestNow('2026-08-24T17:00:00-07:00');
        $user = User::factory()->create();
        $nextOwner = User::factory()->create();
        $active = $this->reservation($user, '2026-08-24T16:00:00-07:00', '2026-08-24T18:00:00-07:00', ReservationStatus::Active);
        $this->reservation($nextOwner, '2026-08-24T20:15:00-07:00', '2026-08-24T22:00:00-07:00');

        $this->actingAs($user)->post(route('reservations.extend', $active), [
            'ends_at' => '2026-08-24T21:00:00-07:00',
        ])->assertSessionHasErrors('ends_at');
        $this->assertSame('2026-08-25T01:00:00+00:00', $active->refresh()->ends_at->toIso8601String());

        $nextOwner->reservations()->update(['status' => ReservationStatus::Cancelled]);
        $this->actingAs($user)->post(route('reservations.extend', $active), [
            'ends_at' => '2026-08-25T01:00:00-07:00',
        ])->assertSessionHasErrors('ends_at');
        $this->assertSame('2026-08-25T01:00:00+00:00', $active->refresh()->ends_at->toIso8601String());
    }

    public function test_user_cannot_extend_another_users_active_reservation(): void
    {
        CarbonImmutable::setTestNow('2026-08-24T17:00:00-07:00');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $active = $this->reservation($owner, '2026-08-24T16:00:00-07:00', '2026-08-24T18:00:00-07:00', ReservationStatus::Active);

        $this->actingAs($other)->post(route('reservations.extend', $active), [
            'ends_at' => '2026-08-24T19:00:00-07:00',
        ])->assertForbidden();
    }

    private function reservation(
        User $user,
        string $startsAt,
        string $endsAt,
        ReservationStatus $status = ReservationStatus::Confirmed,
        ?string $purpose = null,
    ): Reservation {
        $start = CarbonImmutable::parse($startsAt)->utc();
        $end = CarbonImmutable::parse($endsAt)->utc();

        return Reservation::create([
            'user_id' => $user->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'lock_starts_at' => $start,
            'lock_ends_at' => $end,
            'status' => $status,
            'purpose' => $purpose,
            'broker_token' => $status === ReservationStatus::Active ? str_repeat('a', 96) : null,
        ]);
    }
}
