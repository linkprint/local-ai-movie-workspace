<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasLabel
{
    case User = 'user';
    case Operator = 'operator';
    case Admin = 'admin';

    public function getLabel(): ?string
    {
        return __('ui.roles.'.$this->value);
    }
}
