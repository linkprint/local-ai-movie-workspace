<?php

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CspSafeTableAction extends Action
{
    public function getLivewireClickHandler(): ?string
    {
        if (! $this->isLivewireClickHandlerEnabled()) {
            return null;
        }

        $record = $this->getRecord();

        if (! $this->getTable() || ! $record instanceof Model) {
            return parent::getLivewireClickHandler();
        }

        $recordKey = (string) $record->getRouteKey();

        if (! Str::isUuid($recordKey)) {
            return null;
        }

        return "mountTableAction('{$this->getName()}', '{$recordKey}')";
    }
}
