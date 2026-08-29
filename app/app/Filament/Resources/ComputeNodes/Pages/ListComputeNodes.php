<?php

namespace App\Filament\Resources\ComputeNodes\Pages;

use App\Filament\Resources\ComputeNodes\ComputeNodeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListComputeNodes extends ListRecords
{
    protected static string $resource = ComputeNodeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
