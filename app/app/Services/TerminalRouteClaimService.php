<?php

namespace App\Services;

use App\Models\WorkspaceRuntime;
use Illuminate\Support\Str;
use RuntimeException;

class TerminalRouteClaimService
{
    public function issue(WorkspaceRuntime $runtime): string
    {
        if ($runtime->status !== 'running' || $runtime->generation < 1) {
            throw new RuntimeException('workspace_not_running');
        }

        $payload = json_encode([
            'aud' => 'movie-terminal-router',
            'sub' => $runtime->user_id,
            'runtime_id' => $runtime->id,
            'generation' => $runtime->generation,
            'exp' => now()->addSeconds(25)->timestamp,
            'nonce' => Str::random(48),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $signature = hash_hmac('sha256', $encoded, $this->secret());

        return $encoded.'.'.$signature;
    }

    private function secret(): string
    {
        $secret = @file_get_contents((string) config('movie.terminal_router_secret_file'));
        if (! is_string($secret) || strlen(trim($secret)) < 32) {
            throw new RuntimeException('terminal_router_secret_unavailable');
        }

        return trim($secret);
    }
}
