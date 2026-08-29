<?php

namespace App\Filament\Resources\AuditEvents;

use App\Filament\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Filament\Resources\AuditEvents\Pages\ViewAuditEvent;
use App\Filament\Resources\AuditEvents\Schemas\AuditEventInfolist;
use App\Filament\Resources\AuditEvents\Tables\AuditEventsTable;
use App\Models\AuditEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AuditEventResource extends Resource
{
    protected static ?string $model = AuditEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getModelLabel(): string
    {
        return __('ui.admin.audit_event');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.admin.audit_events');
    }

    public static function infolist(Schema $schema): Schema
    {
        return AuditEventInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AuditEventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditEvents::route('/'),
            'view' => ViewAuditEvent::route('/{record}'),
        ];
    }
}
