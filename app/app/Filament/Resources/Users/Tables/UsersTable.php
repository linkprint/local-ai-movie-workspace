<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Actions\CspSafeTableDeleteAction;
use App\Models\User;
use App\Services\UserDeletionService;
use DomainException;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(__('ui.admin.fields.id')),
                TextColumn::make('name')
                    ->label(__('ui.admin.fields.name'))
                    ->searchable(),
                TextColumn::make('email')
                    ->label(__('ui.admin.email_address'))
                    ->searchable(),
                TextColumn::make('role')
                    ->label(__('ui.admin.fields.role'))
                    ->badge()
                    ->searchable(),
                TextColumn::make('timezone')
                    ->label(__('ui.admin.fields.timezone'))
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
                TextColumn::make('two_factor_confirmed_at')
                    ->label(__('ui.admin.fields.two_factor_confirmed_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                CspSafeTableDeleteAction::make()
                    ->label(__('ui.admin.delete'))
                    ->disabled(fn (User $record): bool => auth()->id() === $record->id)
                    ->tooltip(fn (User $record): ?string => auth()->id() === $record->id ? __('ui.admin.cannot_delete_self') : null)
                    ->requiresConfirmation(false)
                    ->modal(false)
                    ->extraAttributes(fn (User $record): array => [
                        'wire:confirm' => __('ui.admin.delete_user_confirm', ['name' => $record->name]),
                    ])
                    ->successNotificationTitle(__('ui.admin.user_deleted'))
                    ->using(function (User $record): bool {
                        $actor = auth()->user();

                        if (! $actor instanceof User) {
                            throw new Halt;
                        }

                        try {
                            return app(UserDeletionService::class)->delete($record, $actor);
                        } catch (DomainException $exception) {
                            Notification::make()
                                ->title(__('ui.admin.user_cannot_delete'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            throw new Halt;
                        }
                    }),
            ]);
    }
}
