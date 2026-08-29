<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('ui.admin.fields.name'))
                    ->required(),
                TextInput::make('email')
                    ->label(__('ui.admin.email_address'))
                    ->email()
                    ->required(),
                TextInput::make('password')
                    ->label(__('ui.admin.fields.password'))
                    ->password()
                    ->helperText(__('ui.admin.password_edit_help'))
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->dehydrated(fn (?string $state, string $operation): bool => $operation === 'edit' && filled($state)),
                Select::make('role')
                    ->label(__('ui.admin.fields.role'))
                    ->options(UserRole::class)
                    ->default('user')
                    ->required(),
                TextInput::make('timezone')
                    ->label(__('ui.admin.fields.timezone'))
                    ->required()
                    ->default('America/Los_Angeles'),
            ]);
    }
}
