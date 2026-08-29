<?php

namespace App\Filament\Resources\MaintenanceWindows\Pages;

use App\Filament\Resources\MaintenanceWindows\MaintenanceWindowResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMaintenanceWindow extends CreateRecord
{
    protected static string $resource = MaintenanceWindowResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['automatic'] = false;

        return $data;
    }
}
