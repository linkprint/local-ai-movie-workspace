<?php

namespace App\Filament\Resources\MaintenanceWindows\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MaintenanceWindowsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('computeNode.display_name')
                    ->label(__('ui.compute_nodes.singular'))
                    ->placeholder(__('ui.compute_nodes.all_servers')),
                TextColumn::make('id')
                    ->label(__('ui.admin.fields.id')),
                TextColumn::make('starts_at')
                    ->label(__('ui.admin.fields.starts_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label(__('ui.admin.fields.ends_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_by')->label(__('ui.admin.fields.created_by')),
                IconColumn::make('automatic')
                    ->label(__('ui.admin.fields.automatic'))
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label(__('ui.admin.fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('ui.admin.fields.updated_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
