<?php

namespace App\Filament\Resources\MaintenanceWindows\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class MaintenanceWindowForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('compute_node_id')
                    ->label(__('ui.compute_nodes.singular'))
                    ->relationship('computeNode', 'display_name')
                    ->searchable()
                    ->preload()
                    ->placeholder(__('ui.compute_nodes.all_servers')),
                DateTimePicker::make('starts_at')
                    ->label(__('ui.admin.fields.starts_at'))
                    ->required(),
                DateTimePicker::make('ends_at')->label(__('ui.admin.fields.ends_at')),
                Textarea::make('reason')
                    ->label(__('ui.admin.fields.reason'))
                    ->required()
                    ->columnSpanFull(),
            ]);
    }
}
