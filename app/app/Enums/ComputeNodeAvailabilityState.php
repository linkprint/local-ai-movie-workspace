<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ComputeNodeAvailabilityState: string implements HasColor, HasLabel
{
    case Idle = 'idle';
    case Busy = 'busy';
    case Abnormal = 'abnormal';

    public function getLabel(): ?string
    {
        return __('ui.compute_nodes.availability.'.$this->value);
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Idle => 'success',
            self::Busy => 'warning',
            self::Abnormal => 'danger',
        };
    }

    public function selectable(): bool
    {
        return $this !== self::Abnormal;
    }
}
