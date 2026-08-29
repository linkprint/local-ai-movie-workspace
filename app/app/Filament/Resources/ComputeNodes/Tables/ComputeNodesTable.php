<?php

namespace App\Filament\Resources\ComputeNodes\Tables;

use App\Models\ComputeNode;
use App\Services\ComputeNodeStatusService;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ComputeNodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('ui.compute_nodes.fields.display_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('host_ip')
                    ->label(__('ui.compute_nodes.fields.host_ip'))
                    ->copyable(),
                TextColumn::make('availability')
                    ->label(__('ui.compute_nodes.fields.availability'))
                    ->state(fn (ComputeNode $record) => app(ComputeNodeStatusService::class)->stateFor($record))
                    ->badge(),
                TextColumn::make('scheduling_state')
                    ->label(__('ui.compute_nodes.fields.scheduling_state'))
                    ->badge(),
                IconColumn::make('visible_in_reservations')
                    ->label(__('ui.compute_nodes.fields.visible'))
                    ->boolean(),
                TextColumn::make('last_heartbeat_at')
                    ->label(__('ui.compute_nodes.fields.last_heartbeat'))
                    ->since()
                    ->placeholder('—'),
                TextColumn::make('last_error_code')
                    ->label(__('ui.compute_nodes.fields.last_error'))
                    ->placeholder('—'),
            ])
            ->defaultSort('sort_order')
            ->recordActions([EditAction::make()]);
    }
}
