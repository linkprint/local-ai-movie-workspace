<?php

namespace App\Filament\Resources\ComputeNodes\Schemas;

use App\Enums\ComputeNodeSchedulingState;
use App\Rules\AllowedComputeNodeIp;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ComputeNodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('display_name')
                ->label(__('ui.compute_nodes.fields.display_name'))
                ->required()
                ->maxLength(80)
                ->unique(ignoreRecord: true),
            TextInput::make('host_ip')
                ->label(__('ui.compute_nodes.fields.host_ip'))
                ->required()
                ->rules([new AllowedComputeNodeIp])
                ->unique(ignoreRecord: true)
                ->helperText(__('ui.compute_nodes.ip_help')),
            Toggle::make('visible_in_reservations')
                ->label(__('ui.compute_nodes.fields.visible'))
                ->default(true)
                ->visibleOn('edit'),
            Select::make('scheduling_state')
                ->label(__('ui.compute_nodes.fields.scheduling_state'))
                ->options(ComputeNodeSchedulingState::class)
                ->default(ComputeNodeSchedulingState::Maintenance->value)
                ->required()
                ->visibleOn('edit')
                ->helperText(__('ui.compute_nodes.state_help')),
        ]);
    }
}
