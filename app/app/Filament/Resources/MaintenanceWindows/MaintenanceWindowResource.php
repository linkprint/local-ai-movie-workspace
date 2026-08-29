<?php

namespace App\Filament\Resources\MaintenanceWindows;

use App\Filament\Resources\MaintenanceWindows\Pages\CreateMaintenanceWindow;
use App\Filament\Resources\MaintenanceWindows\Pages\EditMaintenanceWindow;
use App\Filament\Resources\MaintenanceWindows\Pages\ListMaintenanceWindows;
use App\Filament\Resources\MaintenanceWindows\Schemas\MaintenanceWindowForm;
use App\Filament\Resources\MaintenanceWindows\Tables\MaintenanceWindowsTable;
use App\Models\MaintenanceWindow;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MaintenanceWindowResource extends Resource
{
    protected static ?string $model = MaintenanceWindow::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function getModelLabel(): string
    {
        return __('ui.admin.maintenance_window');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.admin.maintenance_windows');
    }

    public static function form(Schema $schema): Schema
    {
        return MaintenanceWindowForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaintenanceWindowsTable::configure($table);
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
            'index' => ListMaintenanceWindows::route('/'),
            'create' => CreateMaintenanceWindow::route('/create'),
            'edit' => EditMaintenanceWindow::route('/{record}/edit'),
        ];
    }
}
