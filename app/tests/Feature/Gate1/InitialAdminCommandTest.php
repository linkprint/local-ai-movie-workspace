<?php

namespace Tests\Feature\Gate1;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class InitialAdminCommandTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_command_creates_only_the_first_admin_and_sends_a_setup_link(): void
    {
        $this->artisan('movie:create-admin', [
            '--name' => 'Initial Administrator',
            '--email' => 'initial.admin@example.test',
            '--timezone' => 'UTC',
        ])->assertSuccessful();

        $admin = User::query()->where('email', 'initial.admin@example.test')->sole();
        $this->assertSame(UserRole::Admin, $admin->role);
        Notification::assertSentTo($admin, ResetPassword::class);

        $this->artisan('movie:create-admin', [
            '--name' => 'Second Administrator',
            '--email' => 'second.admin@example.test',
            '--timezone' => 'UTC',
        ])->assertFailed();
        $this->assertSame(1, User::query()->where('role', UserRole::Admin)->count());
    }

    public function test_production_log_mailer_refuses_to_create_an_admin(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        config(['mail.default' => 'log']);

        try {
            $this->artisan('movie:create-admin', [
                '--name' => 'Initial Administrator',
                '--email' => 'initial.admin@example.test',
                '--timezone' => 'UTC',
            ])->assertFailed();

            $this->assertDatabaseMissing('users', ['email' => 'initial.admin@example.test']);
            Notification::assertNothingSent();
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }
    }
}
