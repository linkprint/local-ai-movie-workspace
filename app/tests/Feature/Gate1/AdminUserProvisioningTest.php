<?php

namespace Tests\Feature\Gate1;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WorkspaceProfile;
use App\Models\WorkspaceProject;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserProvisioningTest extends TestCase
{
    use DatabaseMigrations;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($this->admin);
        Notification::fake();
    }

    public function test_admin_created_user_receives_only_a_password_setup_link(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm($this->userData())
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'new.user@example.test')->sole();

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            fn (ResetPassword $notification): bool => str_contains(
                $notification->toMail($user)->actionUrl,
                $notification->token,
            ) && ! str_contains(
                implode("\n", $notification->toMail($user)->introLines),
                'Password:',
            ),
        );
    }

    public function test_create_form_does_not_expose_an_initial_password_field(): void
    {
        Livewire::test(CreateUser::class)
            ->assertFormFieldHidden('password');
    }

    public function test_edit_still_keeps_the_existing_password_when_left_blank(): void
    {
        $user = User::factory()->create();
        $originalPasswordHash = $user->password;

        Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm([
                'name' => 'Updated User',
                'email' => $user->email,
                'password' => '',
                'role' => 'user',
                'timezone' => 'America/Los_Angeles',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($originalPasswordHash, $user->refresh()->password);
        Notification::assertNothingSentTo($user);
    }

    public function test_production_log_mailer_does_not_create_or_log_user_credentials(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        config(['mail.default' => 'log']);

        try {
            Livewire::test(CreateUser::class)
                ->fillForm($this->userData())
                ->call('create');

            $this->assertDatabaseMissing('users', ['email' => 'new.user@example.test']);
            Notification::assertNothingSent();
        } finally {
            app()->detectEnvironment(fn (): string => 'testing');
        }
    }

    public function test_removed_user_state_columns_and_admin_fields_are_absent(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'email_verified_at'));
        $this->assertFalse(Schema::hasColumn('users', 'disabled_at'));

        $this->get('/admin/users/create')
            ->assertOk()
            ->assertDontSee('Email verified at')
            ->assertDontSee('Disabled at');
    }

    public function test_admin_can_delete_a_user_without_reservation_history(): void
    {
        $user = User::factory()->create();
        $project = WorkspaceProject::create([
            'user_id' => $user->id,
            'name' => 'Deleted User Project',
            'directory_name' => 'deleted-user-project',
        ]);
        $profile = WorkspaceProfile::create([
            'user_id' => $user->id,
            'storage_uuid' => (string) Str::uuid(),
            'root_directory' => 'deleted.user@example.test',
            'selected_project_id' => $project->id,
        ]);

        Livewire::test(ListUsers::class)
            ->assertActionExists(
                TestAction::make('delete')->table($user),
                fn (Action $action): bool => $action->getLivewireClickHandler() === "mountTableAction('delete', '{$user->id}')"
                    && $action->hasModal() === false
                    && str_contains($action->getExtraAttributes()['wire:confirm'] ?? '', "Delete {$user->name}?"),
            )
            ->assertActionVisible(TestAction::make('delete')->table($user))
            ->callAction(TestAction::make('delete')->table($user))
            ->assertNotified('User deleted');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('workspace_profiles', ['id' => $profile->id]);
        $this->assertDatabaseMissing('workspace_projects', ['id' => $project->id]);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $this->admin->id,
            'action' => 'admin.user.deleted',
            'target_id' => $user->id,
        ]);
    }

    public function test_admin_cannot_delete_their_own_account(): void
    {
        Livewire::test(ListUsers::class)
            ->assertActionDisabled(TestAction::make('delete')->table($this->admin));

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }

    public function test_user_with_reservation_history_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $startsAt = now()->addDay()->startOfHour();

        Reservation::create([
            'user_id' => $user->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'lock_starts_at' => $startsAt->subMinutes(15),
            'lock_ends_at' => $startsAt->addHour()->addMinutes(15),
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'end_reason' => 'test',
        ]);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('delete')->table($user))
            ->assertActionHalted()
            ->assertNotified('User cannot be deleted');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('audit_events', [
            'action' => 'admin.user.deleted',
            'target_id' => $user->id,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function userData(): array
    {
        return [
            'name' => 'New User',
            'email' => 'new.user@example.test',
            'role' => 'user',
            'timezone' => 'America/Los_Angeles',
        ];
    }
}
