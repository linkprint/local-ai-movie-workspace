<?php

namespace App\Filament\Resources\MaintenanceWindows\Pages;

use App\Filament\Resources\MaintenanceWindows\MaintenanceWindowResource;
use Filament\Resources\Pages\EditRecord;

class EditMaintenanceWindow extends EditRecord
{
    protected static string $resource = MaintenanceWindowResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
