<?php

namespace Tests\Feature\Gate1;

use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PortalAccessTest extends TestCase
{
    use DatabaseMigrations;

    public function test_public_registration_is_disabled_and_guest_is_sent_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
        $this->get('/register')->assertNotFound();
        $this->assertFalse(app('router')->has('register'));
    }

    public function test_unconfirmed_totp_user_is_restricted_to_profile(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => null]);
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('profile'));
        $this->actingAs($user)->get('/profile')->assertOk();
    }

    public function test_ordinary_user_cannot_cancel_another_users_reservation_or_access_admin(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $start = CarbonImmutable::now()->addDay()->startOfHour();
        $reservation = Reservation::create([
            'user_id' => $owner->id,
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            'lock_starts_at' => $start->subMinutes(3),
            'lock_ends_at' => $start->addHour()->addMinutes(7),
            'status' => ReservationStatus::Confirmed,
        ]);

        $this->actingAs($other)->delete(route('reservations.destroy', $reservation))->assertForbidden();
        $this->actingAs($other)->get('/admin')->assertForbidden();
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'confirmed']);
    }

    public function test_admin_can_access_filament_only_after_normal_fortify_authentication(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->get('/admin')->assertRedirect(route('login'));
        $this->actingAs($admin)->get('/admin')->assertOk();
    }

    public function test_passwords_use_argon2id_and_login_is_rate_limited(): void
    {
        $user = User::factory()->create(['email' => 'rate@example.test']);
        $this->assertSame('argon2id', Hash::info($user->password)['algoName']);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertStatus(429);
    }

    public function test_workspace_is_a_gate_one_placeholder_and_security_headers_are_present(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/workspace')
            ->assertOk()
            ->assertSee('Workspace is not enabled')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_health_requires_both_postgres_and_redis(): void
    {
        $this->getJson('/up')->assertOk()->assertJson([
            'status' => 'ok',
            'checks' => ['database' => true, 'redis' => true],
        ]);
    }
}
