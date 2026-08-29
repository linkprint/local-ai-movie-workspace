<?php

namespace Tests\Feature\Gate2;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Mechanisms\FrontendAssets\FrontendAssets;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class LivewireCspTest extends TestCase
{
    use DatabaseMigrations;

    public function test_livewire_serves_its_standard_bundle_for_filament_compatibility(): void
    {
        config()->set('app.debug', false);

        $this->assertFalse(config('livewire.csp_safe'));

        $response = app(FrontendAssets::class)->returnJavaScriptAsFile();

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame('livewire.min.js', $response->getFile()->getFilename());
    }

    public function test_portal_csp_still_disallows_dynamic_script_evaluation(): void
    {
        $response = $this->get('/login');
        $policy = (string) $response->headers->get('Content-Security-Policy');

        $response->assertOk();
        $this->assertStringContainsString(
            "script-src 'self' 'unsafe-inline' https://static.cloudflareinsights.com",
            $policy,
        );
        $this->assertStringContainsString("connect-src 'self' wss:", $policy);
        $this->assertStringNotContainsString('script-src *', $policy);
        $this->assertStringNotContainsString("'unsafe-eval'", $policy);
    }

    public function test_admin_csp_allows_the_dynamic_evaluation_required_by_filament(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $response = $this->actingAs($admin)->get('/admin');
        $policy = (string) $response->headers->get('Content-Security-Policy');

        $response->assertOk();
        $this->assertStringContainsString(
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://static.cloudflareinsights.com",
            $policy,
        );
        $this->assertStringNotContainsString('script-src *', $policy);
    }
}
