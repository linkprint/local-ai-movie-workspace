<?php

namespace App\Filament\Resources\Reservations\Tables;

use App\Filament\Actions\CspSafeTableAction;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationService;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReservationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('computeNode.display_name')
                    ->label(__('ui.compute_nodes.singular'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('id')
                    ->label(__('ui.admin.fields.id')),
                TextColumn::make('user_id')->label(__('ui.admin.fields.user_id')),
                TextColumn::make('starts_at')
                    ->label(__('ui.admin.fields.starts_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label(__('ui.admin.fields.ends_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('lock_starts_at')
                    ->label(__('ui.admin.fields.lock_starts_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('lock_ends_at')
                    ->label(__('ui.admin.fields.lock_ends_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('ui.admin.fields.status'))
                    ->badge()
                    ->searchable(),
                TextColumn::make('activated_at')
                    ->label(__('ui.admin.fields.activated_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('first_connected_at')
                    ->label(__('ui.admin.fields.first_connected_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('cancelled_at')
                    ->label(__('ui.admin.fields.cancelled_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('end_reason')
                    ->label(__('ui.admin.fields.end_reason'))
                    ->searchable(),
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
                CspSafeTableAction::make('forceCancel')
                    ->label(__('ui.admin.force_cancel'))
                    ->color('danger')
                    ->requiresConfirmation(false)
                    ->modal(false)
                    ->extraAttributes(fn (Reservation $record): array => [
                        'wire:confirm' => __('ui.admin.force_cancel_confirm', [
                            'user' => $record->user?->name ?: $record->user_id,
                        ]),
                    ])
                    ->successNotificationTitle(__('ui.admin.force_cancelled'))
                    ->visible(fn (Reservation $record): bool => $record->status->occupiesLockWindow())
                    ->action(function (Reservation $record): void {
                        $actor = auth()->user();
                        if (! $actor instanceof User) {
                            throw new Halt;
                        }

                        try {
                            app(ReservationService::class)->forceCancel($record, $actor);
                        } catch (\Throwable $exception) {
                            report($exception);
                            Notification::make()
                                ->title(__('ui.admin.force_cancel_failed'))
                                ->danger()
                                ->send();

                            throw new Halt;
                        }
                    }),
            ]);
    }
}
