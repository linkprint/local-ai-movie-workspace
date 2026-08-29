<?php

namespace App\Policies;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\User;

class ReservationPolicy
{
    public function view(User $user, Reservation $reservation): bool
    {
        return $user->isAdmin() || $reservation->user_id === $user->id;
    }

    public function cancel(User $user, Reservation $reservation): bool
    {
        return ($user->isAdmin() || $reservation->user_id === $user->id)
            && $reservation->status === ReservationStatus::Confirmed;
    }

    public function extend(User $user, Reservation $reservation): bool
    {
        return ($user->isAdmin() || $reservation->user_id === $user->id)
            && $reservation->status === ReservationStatus::Active;
    }
}
