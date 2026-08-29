<?php

namespace Tests\Feature\Gate2;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class AdminPortalNavigationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_admin_topbar_links_back_to_each_portal_page(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk()
            ->assertSee('Portal navigation', false)
            ->assertSee('Portal Home')
            ->assertSee('Reservations')
            ->assertSee('Workspace')
            ->assertSee('Profile')
            ->assertSee('lp-portal-menu-mobile', false)
            ->assertSee('@media (min-width: 1024px)', false)
            ->assertSee('href="'.route('dashboard').'"', false)
            ->assertSee('href="'.route('reservations.index').'"', false)
            ->assertSee('href="'.route('workspace').'"', false)
            ->assertSee('href="'.route('profile').'"', false);
    }
}
