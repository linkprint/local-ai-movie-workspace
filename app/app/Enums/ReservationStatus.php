<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ReservationStatus: string implements HasLabel
{
    case Confirmed = 'confirmed';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Ending = 'ending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    public function getLabel(): ?string
    {
        return __('ui.statuses.'.$this->value);
    }

    public function occupiesLockWindow(): bool
    {
        return in_array($this, [self::Confirmed, self::Provisioning, self::Active, self::Ending], true);
    }
}
