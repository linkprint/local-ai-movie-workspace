<?php

namespace Tests\Feature\Gate2;

use App\Enums\UserRole;
use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class InitialsAvatarTest extends TestCase
{
    use DatabaseMigrations;

    public function test_provider_returns_an_inline_apple_style_monogram(): void
    {
        $user = new User(['name' => 'Ada Lovelace']);

        $url = app(InitialsAvatarProvider::class)->get($user);
        $svg = base64_decode(str($url)->after('data:image/svg+xml;base64,')->toString(), true);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $url);
        $this->assertIsString($svg);
        $this->assertStringContainsString('rx="64"', $svg);
        $this->assertStringContainsString('font-family="-apple-system, BlinkMacSystemFont', $svg);
        $this->assertStringContainsString('>AL</text>', $svg);
        $this->assertStringNotContainsString('ui-avatars.com', $url);
    }

    public function test_provider_keeps_two_character_names_readable(): void
    {
        $user = new User(['name' => '测试']);

        $url = app(InitialsAvatarProvider::class)->get($user);
        $svg = base64_decode(str($url)->after('data:image/svg+xml;base64,')->toString(), true);

        $this->assertIsString($svg);
        $this->assertStringContainsString('>测试</text>', $svg);
    }

    public function test_admin_panel_uses_the_local_provider_only_as_its_default(): void
    {
        $panel = (new AdminPanelProvider(app()))->panel(Panel::make());

        $this->assertSame(InitialsAvatarProvider::class, $panel->getDefaultAvatarProvider());
    }

    public function test_admin_dashboard_embeds_initials_without_an_external_avatar_request(): void
    {
        $admin = User::factory()->create([
            'name' => 'Ada Lovelace',
            'role' => UserRole::Admin,
        ]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk()
            ->assertSee('data:image/svg+xml;base64,', false)
            ->assertDontSee('ui-avatars.com', false)
            ->assertHeader('Content-Security-Policy');

        $this->assertStringContainsString(
            "img-src 'self' data: blob:",
            (string) $response->headers->get('Content-Security-Policy'),
        );
    }
}
