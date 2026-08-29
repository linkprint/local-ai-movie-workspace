<?php

namespace Tests\Feature\Gate1;

use App\Enums\ReservationStatus;
use App\Models\ComputeNode;
use App\Models\MaintenanceWindow;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class ReservationAvailabilityTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_create_page_uses_date_start_and_end_selectors_instead_of_iso_text_fields(): void
    {
        CarbonImmutable::setTestNow('2026-08-23T16:00:00Z');
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('reservations.create'))
            ->assertOk()
            ->assertSee('id="reservation-date"', false)
            ->assertSee('id="reservation-start-time"', false)
            ->assertSee('id="reservation-end-time"', false)
            ->assertSee(route('reservations.availability'), false)
            ->assertDontSee('Use ISO 8601');
    }

    public function test_availability_allows_back_to_back_reservations_at_resource_boundaries(): void
    {
        CarbonImmutable::setTestNow('2026-08-23T16:00:00Z');
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $this->reservation($owner, '2026-08-24T10:00:00-07:00', '2026-08-24T12:00:00-07:00');
        MaintenanceWindow::create([
            'starts_at' => CarbonImmutable::parse('2026-08-24T14:00:00-07:00')->utc(),
            'ends_at' => CarbonImmutable::parse('2026-08-24T16:00:00-07:00')->utc(),
            'reason' => 'Planned maintenance',
        ]);

        $response = $this->actingAs($user)->getJson(route('reservations.availability', [
            'date' => '2026-08-24',
            'compute_node_id' => ComputeNode::LOCAL_NODE_ID,
        ]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('timezone', 'America/Los_Angeles')
            ->assertJsonMissing(['purpose' => 'Private production work']);

        $starts = collect($response->json('slots'))->mapWithKeys(fn (array $slot): array => [
            CarbonImmutable::parse($slot['value'])->setTimezone('America/Los_Angeles')->format('H:i') => $slot,
        ]);

        $this->assertTrue($starts->has('08:00'));
        $this->assertTrue($starts->has('09:45'));
        $this->assertTrue($starts->has('12:00'));
        $this->assertTrue($starts->has('12:15'));
        $this->assertTrue($starts->has('16:00'));
        $this->assertTrue($starts->has('16:15'));
        $this->assertNotEmpty($response->json('windows'));
    }

    public function test_today_starts_with_the_current_minute_when_the_resource_is_free(): void
    {
        CarbonImmutable::setTestNow('2026-08-23T16:07:42Z');

        $availability = app(ReservationAvailabilityService::class)->forDate(
            '2026-08-23',
            'America/Los_Angeles',
        );

        $this->assertSame('Now · 9:07 AM PDT', $availability['slots'][0]['label']);
        $this->assertSame('2026-08-23T09:07:00-07:00', $availability['slots'][0]['value']);
        $this->assertTrue($availability['slots'][0]['immediate']);
        $this->assertSame('2026-08-23T09:15:00-07:00', $availability['slots'][1]['value']);
        $this->assertFalse($availability['slots'][1]['immediate']);
    }

    public function test_current_minute_is_not_offered_when_its_lock_window_is_occupied(): void
    {
        CarbonImmutable::setTestNow('2026-08-23T16:07:42Z');
        $this->reservation(
            User::factory()->create(),
            '2026-08-23T09:00:00-07:00',
            '2026-08-23T10:00:00-07:00',
        );

        $availability = app(ReservationAvailabilityService::class)->forDate(
            '2026-08-23',
            'America/Los_Angeles',
        );

        $this->assertFalse($availability['slots'][0]['immediate']);
        $this->assertSame('2026-08-23T10:00:00-07:00', $availability['slots'][0]['value']);
        $this->assertStringNotContainsString('Now', $availability['slots'][0]['label']);
    }

    public function test_fall_dst_day_exposes_both_one_am_offsets_without_ambiguous_values(): void
    {
        CarbonImmutable::setTestNow('2026-01-01T00:00:00Z');
        config()->set('movie.booking_horizon_days', 365);

        $availability = app(ReservationAvailabilityService::class)->forDate(
            '2026-11-01',
            'America/Los_Angeles',
        );
        $oneAmStarts = collect($availability['slots'])
            ->filter(fn (array $slot): bool => str_starts_with($slot['label'], '1:00 AM'))
            ->pluck('value');

        $this->assertCount(2, $oneAmStarts);
        $this->assertTrue($oneAmStarts->contains(fn (string $value): bool => str_ends_with($value, '-07:00')));
        $this->assertTrue($oneAmStarts->contains(fn (string $value): bool => str_ends_with($value, '-08:00')));
    }

    public function test_availability_rejects_dates_outside_the_booking_horizon(): void
    {
        CarbonImmutable::setTestNow('2026-08-23T16:00:00Z');
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('reservations.availability', [
            'date' => '2026-09-30',
            'compute_node_id' => ComputeNode::LOCAL_NODE_ID,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }

    public function test_extension_options_stop_at_the_next_reservation_and_allow_exact_handoff(): void
    {
        CarbonImmutable::setTestNow('2026-08-24T17:00:00-07:00');
        $user = User::factory()->create();
        $nextOwner = User::factory()->create();
        $active = $this->reservation(
            $user,
            '2026-08-24T16:00:00-07:00',
            '2026-08-24T18:00:00-07:00',
            ReservationStatus::Active,
        );
        $this->reservation($nextOwner, '2026-08-24T21:00:00-07:00', '2026-08-24T23:00:00-07:00');

        $options = app(ReservationAvailabilityService::class)->extensionOptions($active, 'America/Los_Angeles');

        $this->assertSame(['19:00', '20:00', '21:00'], collect($options)
            ->map(fn (array $option): string => CarbonImmutable::parse($option['value'])->setTimezone('America/Los_Angeles')->format('H:i'))
            ->all());
        $this->assertSame('5 hr', $options[2]['total_duration']);
    }

    public function test_extension_options_end_on_the_last_whole_hour_before_a_non_grid_queue_start(): void
    {
        CarbonImmutable::setTestNow('2026-08-24T17:00:00-07:00');
        $user = User::factory()->create();
        $nextOwner = User::factory()->create();
        $active = $this->reservation(
            $user,
            '2026-08-24T16:00:00-07:00',
            '2026-08-24T18:00:00-07:00',
            ReservationStatus::Active,
        );
        $this->reservation($nextOwner, '2026-08-24T20:15:00-07:00', '2026-08-24T22:00:00-07:00');

        $options = app(ReservationAvailabilityService::class)->extensionOptions($active, 'America/Los_Angeles');

        $this->assertSame(['19:00', '20:00'], collect($options)
            ->map(fn (array $option): string => CarbonImmutable::parse($option['value'])->setTimezone('America/Los_Angeles')->format('H:i'))
            ->all());
    }

    public function test_extension_is_unavailable_when_the_next_reservation_starts_at_the_current_end(): void
    {
        CarbonImmutable::setTestNow('2026-08-24T17:00:00-07:00');
        $user = User::factory()->create();
        $nextOwner = User::factory()->create();
        $active = $this->reservation(
            $user,
            '2026-08-24T16:00:00-07:00',
            '2026-08-24T18:00:00-07:00',
            ReservationStatus::Active,
        );
        $this->reservation($nextOwner, '2026-08-24T18:00:00-07:00', '2026-08-24T20:00:00-07:00');

        $this->assertSame([], app(ReservationAvailabilityService::class)
            ->extensionOptions($active, 'America/Los_Angeles'));
    }

    private function reservation(
        User $user,
        string $startsAt,
        string $endsAt,
        ReservationStatus $status = ReservationStatus::Confirmed,
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
            'purpose' => 'Private production work',
        ]);
    }
}
