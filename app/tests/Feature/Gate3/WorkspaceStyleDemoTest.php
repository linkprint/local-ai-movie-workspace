<?php

namespace Tests\Feature\Gate3;

use App\Models\User;
use App\Services\WorkspaceStyleDemoLocator;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WorkspaceStyleDemoTest extends TestCase
{
    use DatabaseMigrations;

    private string $demoRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->demoRoot = sys_get_temp_dir().'/movie-style-demos-'.bin2hex(random_bytes(8));
        mkdir($this->demoRoot, 0770, true);
        config()->set('movie.require_totp', false);
        config()->set('movie.style_demo_root', $this->demoRoot);
        file_put_contents($this->demoRoot.'/h3-editorial-fashion-motion.mp4', '0123456789');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->demoRoot);
        parent::tearDown();
    }

    public function test_authenticated_workspace_user_can_stream_a_registered_style_demo(): void
    {
        $user = User::factory()->create();
        $url = route('workspace.styles.demo', ['skill' => 'h3-editorial-fashion-motion']);

        $this->actingAs($user)->withHeader('Range', 'bytes=2-5')->get($url)
            ->assertStatus(206)
            ->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Content-Range', 'bytes 2-5/10')
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Content-Disposition', 'inline; filename=h3-editorial-fashion-motion.mp4');
    }

    public function test_unregistered_missing_or_linked_style_demos_are_not_exposed(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/workspace/styles/not-a-style/demo')->assertNotFound();

        unlink($this->demoRoot.'/h3-editorial-fashion-motion.mp4');
        symlink(__FILE__, $this->demoRoot.'/h3-editorial-fashion-motion.mp4');
        $this->actingAs($user)->get(route('workspace.styles.demo', [
            'skill' => 'h3-editorial-fashion-motion',
        ]))->assertNotFound();
    }

    public function test_locator_returns_only_a_safe_existing_demo(): void
    {
        $locator = app(WorkspaceStyleDemoLocator::class);
        $style = collect(config('movie.styles'))->firstWhere('skill', 'h3-editorial-fashion-motion');

        $this->assertSame(
            realpath($this->demoRoot.'/h3-editorial-fashion-motion.mp4'),
            $locator->pathFor($style),
        );

        unlink($this->demoRoot.'/h3-editorial-fashion-motion.mp4');

        $this->assertNull($locator->pathFor($style));
    }
}
