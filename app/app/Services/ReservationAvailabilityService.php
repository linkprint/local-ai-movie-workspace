<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\ComputeNode;
use App\Models\MaintenanceWindow;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

class ReservationAvailabilityService
{
    /**
     * @return array<int, array{value: string, label: string, added_duration: string, total_duration: string}>
     */
    public function extensionOptions(Reservation $reservation, string $timezone): array
    {
        if ($reservation->status !== ReservationStatus::Active) {
            return [];
        }

        $timezone = $this->validTimezone($timezone);
        $startsAt = CarbonImmutable::instance($reservation->starts_at)->utc();
        $currentEnd = CarbonImmutable::instance($reservation->ends_at)->utc();
        $latestEnd = $startsAt->addHours(config('movie.max_reservation_hours'));

        if ($currentEnd->greaterThanOrEqualTo($latestEnd)) {
            return [];
        }

        $node = $reservation->computeNode ?? ComputeNode::local();
        $blocks = $this->blockingWindows($currentEnd, $latestEnd, $node, $reservation->id);
        $options = [];

        for ($endsAt = $currentEnd->addHour(); $endsAt->lessThanOrEqualTo($latestEnd); $endsAt = $endsAt->addHour()) {
            if ($this->overlapsBlock($currentEnd, $endsAt, $blocks)) {
                continue;
            }

            $displayEnd = $endsAt->setTimezone($timezone);
            $displayStart = $startsAt->setTimezone($timezone);
            $options[] = [
                'value' => $displayEnd->toIso8601String(),
                'label' => $displayEnd->isSameDay($displayStart)
                    ? $displayEnd->translatedFormat(__('ui.formats.time'))
                    : $displayEnd->translatedFormat(__('ui.formats.date_time_cross_day')),
                'added_duration' => $this->durationLabel($currentEnd->diffInRealSeconds($endsAt)),
                'total_duration' => $this->durationLabel($startsAt->diffInRealSeconds($endsAt)),
            ];
        }

        return $options;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function dateOptions(string $timezone, ?CarbonImmutable $now = null): array
    {
        $timezone = $this->validTimezone($timezone);
        $now = ($now ?? CarbonImmutable::now())->utc();
        $date = $now->setTimezone($timezone)->startOfDay();
        $lastDate = $now->addDays(config('movie.booking_horizon_days'))->setTimezone($timezone)->startOfDay();
        $options = [];

        while ($date->lessThanOrEqualTo($lastDate)) {
            $prefix = match (count($options)) {
                0 => __('ui.reservations.today'),
                1 => __('ui.reservations.tomorrow'),
                default => '',
            };

            $options[] = [
                'value' => $date->format('Y-m-d'),
                'label' => $prefix.$date->translatedFormat(__('ui.formats.date_short')),
            ];
            $date = $date->addDay();
        }

        return $options;
    }

    /**
     * @return array{
     *     date: string,
     *     date_label: string,
     *     timezone: string,
     *     available_count: int,
     *     windows: array<int, array{start_range: string, end_by: string}>,
     *     slots: array<int, array{value: string, label: string, immediate: bool, ends: array<int, array{value: string, label: string, duration: string}>}>
     * }
     */
    public function forDate(string $date, string $timezone, ?CarbonImmutable $now = null, ?ComputeNode $node = null): array
    {
        $timezone = $this->validTimezone($timezone);
        $now = ($now ?? CarbonImmutable::now())->utc();
        $dayStart = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);

        if ($dayStart === false || $dayStart->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['date' => __('ui.errors.valid_reservation_date')]);
        }

        $today = $now->setTimezone($timezone)->startOfDay();
        $lastDate = $now->addDays(config('movie.booking_horizon_days'))->setTimezone($timezone)->startOfDay();

        if ($dayStart->lessThan($today) || $dayStart->greaterThan($lastDate)) {
            throw ValidationException::withMessages(['date' => __('ui.errors.outside_booking_horizon')]);
        }

        $dayEnd = $dayStart->addDay();
        $queryStart = $dayStart->utc();
        $queryEnd = $dayEnd
            ->addHours(config('movie.max_reservation_hours'))
            ->utc();
        $blocks = $this->blockingWindows($queryStart, $queryEnd, $node ?? ComputeNode::local());
        $slots = [];
        $stepSeconds = config('movie.start_step_minutes') * 60;
        $latestStart = $now->addDays(config('movie.booking_horizon_days'));

        if ($dayStart->isSameDay($now->setTimezone($timezone))) {
            $displayNow = $now->setTimezone($timezone)->startOfMinute();
            $effectiveStart = $this->immediateStart($now)->setTimezone($timezone);
            $ends = $this->availableEnds($effectiveStart, $blocks, $timezone);

            if ($ends !== []) {
                $slots[] = [
                    'value' => $displayNow->toIso8601String(),
                    'label' => __('ui.reservations.now').$displayNow->translatedFormat(__('ui.formats.time')),
                    'immediate' => true,
                    'ends' => $ends,
                ];
            }
        }

        for ($timestamp = $dayStart->timestamp; $timestamp < $dayEnd->timestamp; $timestamp += $stepSeconds) {
            $startsAt = CarbonImmutable::createFromTimestamp($timestamp, $timezone);

            if ($startsAt->lessThanOrEqualTo($now) || $startsAt->greaterThan($latestStart)) {
                continue;
            }

            $ends = $this->availableEnds($startsAt, $blocks, $timezone);

            if ($ends === []) {
                continue;
            }

            $slots[] = [
                'value' => $startsAt->toIso8601String(),
                'label' => $startsAt->translatedFormat(__('ui.formats.time')),
                'immediate' => false,
                'ends' => $ends,
            ];
        }

        return [
            'compute_node_id' => ($node ?? ComputeNode::local())->id,
            'date' => $date,
            'date_label' => $dayStart->translatedFormat(__('ui.formats.date_long')),
            'timezone' => $timezone,
            'available_count' => count($slots),
            'windows' => $this->groupWindows($slots, $timezone),
            'slots' => $slots,
        ];
    }

    public function immediateStart(?CarbonImmutable $now = null): CarbonImmutable
    {
        // The controller replaces the client-provided display minute with this
        // authoritative server timestamp. Keeping the actual submission time
        // makes a successful "Now" reservation current immediately instead of
        // leaving the user outside the activation window until the next minute.
        return ($now ?? CarbonImmutable::now())->utc();
    }

    /**
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable}>  $blocks
     * @return array<int, array{value: string, label: string, duration: string}>
     */
    private function availableEnds(CarbonImmutable $startsAt, array $blocks, string $timezone): array
    {
        $ends = [];
        $maxSeconds = config('movie.max_reservation_hours') * 3600;
        $minimumEndTimestamp = $startsAt->timestamp + (config('movie.start_step_minutes') * 60);
        $firstEndTimestamp = intdiv($minimumEndTimestamp + 3599, 3600) * 3600;
        $latestEndTimestamp = $startsAt->timestamp + $maxSeconds;

        for ($timestamp = $firstEndTimestamp; $timestamp <= $latestEndTimestamp; $timestamp += 3600) {
            $endsAt = CarbonImmutable::createFromTimestamp($timestamp, $timezone);
            $elapsed = $timestamp - $startsAt->timestamp;

            if ($this->overlapsBlock($startsAt, $endsAt, $blocks)) {
                continue;
            }

            $ends[] = [
                'value' => $endsAt->toIso8601String(),
                'label' => $endsAt->isSameDay($startsAt)
                    ? $endsAt->translatedFormat(__('ui.formats.time'))
                    : $endsAt->translatedFormat(__('ui.formats.date_time_cross_day')),
                'duration' => $this->durationLabel($elapsed),
            ];
        }

        return $ends;
    }

    /**
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    private function blockingWindows(CarbonImmutable $queryStart, CarbonImmutable $queryEnd, ComputeNode $node, ?string $excludeReservationId = null): array
    {
        $statuses = array_map(
            fn (ReservationStatus $status): string => $status->value,
            array_filter(ReservationStatus::cases(), fn (ReservationStatus $status): bool => $status->occupiesLockWindow()),
        );

        $reservationQuery = Reservation::query()
            ->where('compute_node_id', $node->id)
            ->whereIn('status', $statuses)
            ->where('lock_starts_at', '<', $queryEnd)
            ->where('lock_ends_at', '>', $queryStart);

        if ($excludeReservationId !== null) {
            $reservationQuery->whereKeyNot($excludeReservationId);
        }

        $reservations = $reservationQuery
            ->get(['lock_starts_at', 'lock_ends_at'])
            ->map(fn (Reservation $reservation): array => [
                'start' => CarbonImmutable::instance($reservation->lock_starts_at)->utc(),
                'end' => CarbonImmutable::instance($reservation->lock_ends_at)->utc(),
            ]);

        $maintenance = MaintenanceWindow::query()
            ->where(fn ($query) => $query
                ->whereNull('compute_node_id')
                ->orWhere('compute_node_id', $node->id))
            ->where('starts_at', '<', $queryEnd)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $queryStart))
            ->get(['starts_at', 'ends_at'])
            ->map(fn (MaintenanceWindow $window): array => [
                'start' => CarbonImmutable::instance($window->starts_at)->utc(),
                'end' => $window->ends_at ? CarbonImmutable::instance($window->ends_at)->utc() : $queryEnd,
            ]);

        return $reservations->concat($maintenance)->sortBy(fn (array $window): int => $window['start']->timestamp)->values()->all();
    }

    /**
     * @param  array<int, array{start: CarbonImmutable, end: CarbonImmutable}>  $blocks
     */
    private function overlapsBlock(CarbonImmutable $startsAt, CarbonImmutable $endsAt, array $blocks): bool
    {
        $lockStartsAt = $startsAt->utc();
        $lockEndsAt = $endsAt->utc();

        foreach ($blocks as $block) {
            if ($lockStartsAt->lessThan($block['end']) && $lockEndsAt->greaterThan($block['start'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{value: string, label: string, immediate: bool, ends: array<int, array{value: string, label: string, duration: string}>}>  $slots
     * @return array<int, array{start_range: string, end_by: string}>
     */
    private function groupWindows(array $slots, string $timezone): array
    {
        $groups = [];
        $group = null;
        $stepSeconds = config('movie.start_step_minutes') * 60;

        foreach ($slots as $slot) {
            $startsAt = CarbonImmutable::parse($slot['value'])->setTimezone($timezone);
            $lastEnd = CarbonImmutable::parse($slot['ends'][array_key_last($slot['ends'])]['value'])->setTimezone($timezone);

            if ($group === null || $startsAt->timestamp !== $group['last_start']->timestamp + $stepSeconds) {
                if ($group !== null) {
                    $groups[] = $this->formatWindowGroup($group);
                }

                $group = [
                    'first_start' => $startsAt,
                    'last_start' => $startsAt,
                    'latest_end' => $lastEnd,
                ];

                continue;
            }

            $group['last_start'] = $startsAt;
            if ($lastEnd->greaterThan($group['latest_end'])) {
                $group['latest_end'] = $lastEnd;
            }
        }

        if ($group !== null) {
            $groups[] = $this->formatWindowGroup($group);
        }

        return $groups;
    }

    /**
     * @param  array{first_start: CarbonImmutable, last_start: CarbonImmutable, latest_end: CarbonImmutable}  $group
     * @return array{start_range: string, end_by: string}
     */
    private function formatWindowGroup(array $group): array
    {
        $first = $group['first_start']->translatedFormat(__('ui.formats.time_short'));
        $last = $group['last_start']->translatedFormat(__('ui.formats.time_short'));

        return [
            'start_range' => $first === $last ? $first : "{$first} – {$last}",
            'end_by' => $group['latest_end']->isSameDay($group['first_start'])
                ? $group['latest_end']->translatedFormat(__('ui.formats.time'))
                : $group['latest_end']->translatedFormat(__('ui.formats.date_time_cross_day')),
        ];
    }

    private function durationLabel(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $parts = [];

        if ($hours > 0) {
            $parts[] = __('ui.reservations.duration_hours', ['count' => $hours]);
        }
        if ($minutes > 0) {
            $parts[] = __('ui.reservations.duration_minutes', ['count' => $minutes]);
        }

        return implode(' ', $parts);
    }

    private function validTimezone(string $timezone): string
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : config('movie.display_timezone');
    }
}
