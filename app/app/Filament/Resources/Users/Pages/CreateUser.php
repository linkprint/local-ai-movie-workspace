<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function beforeCreate(): void
    {
        if (! app()->isProduction() || ! in_array(config('mail.default'), ['array', 'log'], true)) {
            return;
        }

        Notification::make()
            ->danger()
            ->title('Email delivery is not configured')
            ->body('Configure a real mail transport before creating a user. The account was not created.')
            ->send();

        throw new Halt;
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['password'] = Str::password(length: 64);

        return $data;
    }

    protected function afterCreate(): void
    {
        $status = Password::sendResetLink(['email' => $this->record->email]);
        if ($status === Password::RESET_LINK_SENT) {
            return;
        }

        $this->record->delete();
        Notification::make()
            ->danger()
            ->title('Invitation email could not be sent')
            ->body('The account was removed. Verify the mail transport and try again.')
            ->send();

        throw new Halt;
    }
}
