<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\ComputeNodeHealthService;
use App\Services\ReservationService;
use App\Services\WorkspaceRuntimeService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('movie:create-admin {--name=} {--email=} {--timezone=UTC}', function (): int {
    if (app()->isProduction() && in_array(config('mail.default'), ['array', 'log'], true)) {
        $this->error('Configure a real mail transport before creating the first administrator.');

        return Command::FAILURE;
    }
    if (User::query()->where('role', UserRole::Admin->value)->exists()) {
        $this->error('An administrator already exists. Use the authenticated Admin UI for later users.');

        return Command::FAILURE;
    }

    $input = [
        'name' => $this->option('name'),
        'email' => $this->option('email'),
        'timezone' => $this->option('timezone'),
    ];
    $validator = Validator::make($input, [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
        'timezone' => ['required', 'timezone'],
    ]);
    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $message) {
            $this->error($message);
        }

        return Command::INVALID;
    }

    $user = User::create([
        ...$validator->validated(),
        'password' => Str::password(length: 64),
        'role' => UserRole::Admin,
    ]);
    $status = Password::sendResetLink(['email' => $user->email]);
    if ($status !== Password::RESET_LINK_SENT) {
        $user->delete();
        $this->error('The invitation email could not be sent; no administrator was retained.');

        return Command::FAILURE;
    }

    $this->info('Administrator created. A one-time password setup link was sent.');

    return Command::SUCCESS;
})->purpose('Create the first administrator and send a one-time password setup link');

Schedule::call(fn () => app(WorkspaceRuntimeService::class)->reconcile())
    ->name('movie-workspace-reconcile')
    ->everyTenSeconds()
    ->withoutOverlapping(1);

Schedule::call(fn () => app(ComputeNodeHealthService::class)->refreshRegisteredNodes())
    ->name('movie-compute-node-health')
    ->everyTenSeconds()
    ->withoutOverlapping(1);

Schedule::call(fn () => app(ReservationService::class)->markNoShows())
    ->name('movie-reservation-no-shows')
    ->everyMinute()
    ->withoutOverlapping(2);
