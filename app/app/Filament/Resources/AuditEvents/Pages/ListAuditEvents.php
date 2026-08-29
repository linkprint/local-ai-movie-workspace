<?php

namespace App\Filament\Resources\AuditEvents\Pages;

use App\Filament\Resources\AuditEvents\AuditEventResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditEvents extends ListRecords
{
    protected static string $resource = AuditEventResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
