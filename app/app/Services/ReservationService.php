<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\AuditEvent;
use App\Models\ComputeNode;
use App\Models\MaintenanceWindow;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    public function __construct(
        private readonly ComputeNodeStatusService $nodes,
        private readonly LocalAiLeaseService $leases,
    ) {}

    public function create(User $user, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?string $purpose = null, bool $immediate = false, ?ComputeNode $computeNode = null): Reservation
    {
        $computeNode ??= ComputeNode::local();
        $this->nodes->assertAcceptsReservations($computeNode);
        $startsAt = $startsAt->utc();
        $endsAt = $endsAt->utc();
        $this->validateWindow($startsAt, $endsAt, allowImmediateStart: $immediate);
        $this->validateNewReservationLimits($user, $startsAt);

        // Reservation ownership is the exact half-open business window. This
        // permits a safe handoff where one reservation ends exactly when the
        // next begins; runtime reconciliation tears down the former before
        // provisioning the latter.
        $lockStartsAt = $startsAt;
        $lockEndsAt = $endsAt;
        $this->assertNoMaintenance($lockStartsAt, $lockEndsAt, $computeNode);

        try {
            return DB::transaction(function () use ($user, $computeNode, $startsAt, $endsAt, $lockStartsAt, $lockEndsAt, $purpose, $immediate): Reservation {
                ComputeNode::query()->whereKey($computeNode->id)->lockForUpdate()->firstOrFail();
                $occupyingStatuses = array_map(
                    fn (ReservationStatus $status): string => $status->value,
                    array_filter(ReservationStatus::cases(), fn (ReservationStatus $status): bool => $status->occupiesLockWindow()),
                );
                $overlap = Reservation::query()
                    ->whereIn('status', $occupyingStatuses)
                    ->where(fn ($query) => $query
                        ->where('compute_node_id', $computeNode->id)
                        ->orWhere('user_id', $user->id))
                    ->where('lock_starts_at', '<', $lockEndsAt)
                    ->where('lock_ends_at', '>', $lockStartsAt)
                    ->lockForUpdate()
                    ->exists();

                if ($overlap) {
                    throw ValidationException::withMessages(['starts_at' => __('ui.errors.window_just_reserved')]);
                }

                $reservation = Reservation::create([
                    'user_id' => $user->id,
                    'compute_node_id' => $computeNode->id,
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'lock_starts_at' => $lockStartsAt,
                    'lock_ends_at' => $lockEndsAt,
                    'status' => ReservationStatus::Confirmed,
                    'purpose' => $purpose,
                ]);

                AuditEvent::record('reservation.created', $reservation, [
                    'starts_at' => $startsAt->toIso8601String(),
                    'ends_at' => $endsAt->toIso8601String(),
                    'immediate' => $immediate,
                    'compute_node_id' => $computeNode->id,
                ]);

                return $reservation;
            });
        } catch (QueryException $exception) {
            $this->rethrowReservationConflict($exception);
        }
    }

    public function cancel(Reservation $reservation, User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation, $actor): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($locked->status !== ReservationStatus::Confirmed) {
                throw ValidationException::withMessages(['reservation' => __('ui.errors.cancel_confirmed_only')]);
            }

            if (! $actor->isAdmin() && now()->addMinutes(config('movie.cancellation_cutoff_minutes'))->greaterThan($locked->starts_at)) {
                throw ValidationException::withMessages(['reservation' => __('ui.errors.cancellation_deadline')]);
            }

            $locked->update([
                'status' => ReservationStatus::Cancelled,
                'cancelled_at' => now(),
                'end_reason' => $actor->isAdmin() ? 'admin_cancel' : 'user_cancel',
            ]);
            AuditEvent::record('reservation.cancelled', $locked);

            return $locked->refresh();
        });
    }

    public function forceCancel(Reservation $reservation, User $actor): Reservation
    {
        if (! $actor->isAdmin()) {
            throw new AuthorizationException;
        }

        [$current, $previousStatus] = DB::transaction(function () use ($reservation): array {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $previousStatus = $locked->status;
            if (! $locked->status->occupiesLockWindow()) {
                throw ValidationException::withMessages([
                    'reservation' => __('ui.admin.force_cancel_unavailable'),
                ]);
            }

            if ($locked->status === ReservationStatus::Confirmed) {
                $locked->forceFill([
                    'status' => ReservationStatus::Cancelled,
                    'cancelled_at' => now(),
                    'end_reason' => 'admin_cancel',
                ])->save();
            }

            return [$locked->refresh(), $previousStatus];
        });

        if ($current->status !== ReservationStatus::Cancelled) {
            $current = $this->leases->revoke($current, 'admin_cancel');
        }

        if ($current->status !== ReservationStatus::Cancelled) {
            throw ValidationException::withMessages([
                'reservation' => __('ui.admin.force_cancel_failed'),
            ]);
        }

        AuditEvent::record('reservation.force_cancelled', $current, [
            'previous_status' => $previousStatus->value,
            'reservation_user_id' => $current->user_id,
            'compute_node_id' => $current->compute_node_id,
        ]);

        return $current->refresh();
    }

    public function extend(Reservation $reservation, CarbonImmutable $newEndsAt): Reservation
    {
        $newEndsAt = $newEndsAt->utc();

        try {
            return DB::transaction(function () use ($reservation, $newEndsAt): Reservation {
                $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

                if ($locked->status !== ReservationStatus::Active) {
                    throw ValidationException::withMessages(['ends_at' => __('ui.errors.extend_active_only')]);
                }

                $startsAt = CarbonImmutable::instance($locked->starts_at);
                $this->validateWindow($startsAt, $newEndsAt, requireFutureStart: false);

                if ($newEndsAt->lessThanOrEqualTo($locked->ends_at)) {
                    throw ValidationException::withMessages(['ends_at' => __('ui.errors.end_must_be_later')]);
                }

                $newLockEndsAt = $newEndsAt;
                $currentLockEnd = CarbonImmutable::instance($locked->lock_ends_at);
                $computeNode = $locked->computeNode ?? ComputeNode::local();
                $this->assertNoMaintenance($currentLockEnd, $newLockEndsAt, $computeNode, 'ends_at');

                $occupyingStatuses = array_map(
                    fn (ReservationStatus $status): string => $status->value,
                    array_filter(ReservationStatus::cases(), fn (ReservationStatus $status): bool => $status->occupiesLockWindow()),
                );
                $blocked = Reservation::query()
                    ->whereKeyNot($locked->id)
                    ->whereIn('status', $occupyingStatuses)
                    ->where(fn ($query) => $query
                        ->where('compute_node_id', $locked->compute_node_id)
                        ->orWhere('user_id', $locked->user_id))
                    ->where('lock_starts_at', '<', $newLockEndsAt)
                    ->where('lock_ends_at', '>', $currentLockEnd)
                    ->lockForUpdate()
                    ->exists();

                if ($blocked) {
                    throw ValidationException::withMessages(['ends_at' => __('ui.errors.window_just_reserved')]);
                }

                $locked->update(['ends_at' => $newEndsAt, 'lock_ends_at' => $newLockEndsAt]);
                AuditEvent::record('reservation.extended', $locked, ['ends_at' => $newEndsAt->toIso8601String()]);

                return $locked->refresh();
            });
        } catch (QueryException $exception) {
            $this->rethrowReservationConflict($exception, 'ends_at');
        }
    }

    public function transitionToProvisioning(Reservation $reservation, ?CarbonImmutable $activatedAt = null): Reservation
    {
        $activatedAt = $activatedAt?->utc();

        try {
            return DB::transaction(function () use ($reservation, $activatedAt): Reservation {
                $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

                if ($locked->status !== ReservationStatus::Confirmed) {
                    throw new DomainException('Reservation is not confirmed.');
                }

                $locked->update([
                    'status' => ReservationStatus::Provisioning,
                    'activated_at' => $activatedAt ?? CarbonImmutable::now(),
                ]);
                AuditEvent::record('reservation.provisioning', $locked);

                return $locked->refresh();
            });
        } catch (QueryException $exception) {
            $this->rethrowReservationConflict($exception);
        }
    }

    public function markNoShows(?CarbonImmutable $at = null): int
    {
        $at = ($at ?? CarbonImmutable::now())->utc();
        $count = 0;

        Reservation::query()
            ->whereIn('status', [ReservationStatus::Provisioning, ReservationStatus::Active])
            ->whereNull('first_connected_at')
            ->orderBy('starts_at')
            ->each(function (Reservation $reservation) use ($at, &$count): void {
                $startsAt = CarbonImmutable::instance($reservation->starts_at);
                $activatedAt = $reservation->activated_at ? CarbonImmutable::instance($reservation->activated_at) : $startsAt;
                $base = $startsAt->greaterThan($activatedAt) ? $startsAt : $activatedAt;

                if ($at->lessThan($base->addMinutes(config('movie.no_show_minutes')))) {
                    return;
                }

                $transitioned = DB::transaction(function () use ($reservation): bool {
                    $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
                    if ($locked->first_connected_at !== null || ! in_array($locked->status, [ReservationStatus::Provisioning, ReservationStatus::Active], true)) {
                        return false;
                    }
                    $locked->update(['status' => ReservationStatus::Ending, 'end_reason' => 'no_show']);
                    AuditEvent::record('reservation.no_show', $locked);

                    return true;
                });
                if ($transitioned) {
                    $count++;
                }
            });

        return $count;
    }

    private function validateWindow(CarbonImmutable $startsAt, CarbonImmutable $endsAt, bool $requireFutureStart = true, bool $allowImmediateStart = false): void
    {
        $errors = [];
        $displayStart = $startsAt->setTimezone(config('movie.display_timezone'));
        $now = CarbonImmutable::now();
        $isImmediateStart = $allowImmediateStart
            && $startsAt->greaterThanOrEqualTo($now->subMinute())
            && $startsAt->lessThanOrEqualTo($now->addSeconds(5));

        if ($requireFutureStart && ! $isImmediateStart && $startsAt->lessThanOrEqualTo($now)) {
            $errors['starts_at'] = 'The start time must be in the future.';
        }
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            $errors['ends_at'] = 'The end time must be after the start time.';
        }
        if ($allowImmediateStart && $startsAt->diffInRealSeconds($endsAt) < config('movie.start_step_minutes') * 60) {
            $errors['ends_at'] = 'A reservation must be at least 15 minutes.';
        }
        if ($startsAt->diffInRealSeconds($endsAt) > config('movie.max_reservation_hours') * 3600) {
            $errors['ends_at'] = 'A reservation cannot exceed eight absolute hours.';
        }
        if (($endsAt->timestamp % 3600) !== 0) {
            $errors['ends_at'] = 'The end time must be on the hour.';
        }
        if ($requireFutureStart && ! $isImmediateStart && ($displayStart->second !== 0 || ($displayStart->minute % config('movie.start_step_minutes')) !== 0)) {
            $errors['starts_at'] = 'The start time must use a 15-minute step.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function validateNewReservationLimits(User $user, CarbonImmutable $startsAt): void
    {
        if ($startsAt->greaterThan(CarbonImmutable::now()->addDays(config('movie.booking_horizon_days')))) {
            throw ValidationException::withMessages(['starts_at' => __('ui.errors.start_outside_horizon')]);
        }

        $futureCount = $user->reservations()
            ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::Provisioning, ReservationStatus::Active])
            ->where('ends_at', '>', now())
            ->count();

        if ($futureCount >= config('movie.max_future_reservations')) {
            throw ValidationException::withMessages(['starts_at' => __('ui.errors.future_limit')]);
        }
    }

    private function assertNoMaintenance(CarbonImmutable $lockStartsAt, CarbonImmutable $lockEndsAt, ComputeNode $node, string $field = 'starts_at'): void
    {
        $blocked = MaintenanceWindow::query()
            ->where(fn ($query) => $query
                ->whereNull('compute_node_id')
                ->orWhere('compute_node_id', $node->id))
            ->where('starts_at', '<', $lockEndsAt)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $lockStartsAt))
            ->exists();

        if ($blocked) {
            throw ValidationException::withMessages([$field => __('ui.errors.overlaps_maintenance')]);
        }
    }

    private function rethrowReservationConflict(QueryException $exception, string $field = 'starts_at'): never
    {
        if (in_array((string) $exception->getCode(), ['23P01', '23505'], true)) {
            throw ValidationException::withMessages([$field => __('ui.errors.window_just_reserved')]);
        }

        throw $exception;
    }
}
