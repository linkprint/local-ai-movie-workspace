<?php

namespace Tests\Feature\Gate2;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__gate2-proxy-test', static fn (Request $request) => response()->json([
            'host' => $request->getHost(),
            'scheme' => $request->getScheme(),
        ]));
    }

    public function test_configured_proxy_can_supply_public_scheme_and_host(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '192.168.88.30',
            'HTTP_HOST' => 'movie-gateway',
            'HTTP_X_FORWARDED_HOST' => 'movie.example.com',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('/__gate2-proxy-test')
            ->assertOk()
            ->assertExactJson([
                'host' => 'movie.example.com',
                'scheme' => 'https',
            ]);
    }

    public function test_untrusted_source_cannot_spoof_forwarded_scheme_or_host(): void
    {
        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.2',
            'HTTP_HOST' => 'movie-gateway',
            'HTTP_X_FORWARDED_HOST' => 'attacker.example',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            ])->get('/__gate2-proxy-test')
            ->assertOk()
            ->assertExactJson([
                'host' => 'movie.example.com',
                'scheme' => 'https',
            ]);
    }
}
