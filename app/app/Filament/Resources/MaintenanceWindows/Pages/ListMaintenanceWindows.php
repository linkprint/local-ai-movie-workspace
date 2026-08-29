<?php

namespace App\Filament\Resources\MaintenanceWindows\Pages;

use App\Filament\Resources\MaintenanceWindows\MaintenanceWindowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaintenanceWindows extends ListRecords
{
    protected static string $resource = MaintenanceWindowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
