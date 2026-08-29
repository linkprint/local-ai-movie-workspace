<?php

namespace App\Filament\Resources\AuditEvents\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AuditEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('actor_id')->label(__('ui.admin.fields.actor_id')),
                TextColumn::make('action')
                    ->label(__('ui.admin.fields.action'))
                    ->searchable(),
                TextColumn::make('target_type')
                    ->label(__('ui.admin.fields.target_type'))
                    ->searchable(),
                TextColumn::make('target_id')
                    ->label(__('ui.admin.fields.target_id'))
                    ->searchable(),
                TextColumn::make('ip_address')
                    ->label(__('ui.admin.fields.ip_address'))
                    ->searchable(),
                TextColumn::make('request_id')->label(__('ui.admin.fields.request_id')),
                TextColumn::make('created_at')
                    ->label(__('ui.admin.fields.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
