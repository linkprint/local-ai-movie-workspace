<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ComputeNodeSchedulingState: string implements HasLabel
{
    case Online = 'online';
    case Draining = 'draining';
    case Maintenance = 'maintenance';
    case Offline = 'offline';

    public function getLabel(): ?string
    {
        return __('ui.compute_nodes.scheduling.'.$this->value);
    }
}
