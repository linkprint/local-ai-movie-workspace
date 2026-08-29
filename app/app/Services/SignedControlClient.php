<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class SignedControlClient
{
    abstract protected function baseUrl(): string;

    abstract protected function secretFile(): string;

    protected function get(string $path): Response
    {
        return $this->request('GET', $path, null);
    }

    protected function post(string $path, array $payload): Response
    {
        return $this->request('POST', $path, $payload);
    }

    private function request(string $method, string $path, ?array $payload): Response
    {
        $body = $payload === null
            ? ''
            : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) now()->timestamp;
        $message = implode("\n", [$timestamp, $method, $path, $body]);
        $signature = hash_hmac('sha256', $message, $this->secret());

        $request = $this->http()->withHeaders([
            'X-Movie-Timestamp' => $timestamp,
            'X-Movie-Signature' => $signature,
        ]);

        return $method === 'GET'
            ? $request->get($this->baseUrl().$path)
            : $request->withBody($body, 'application/json')->post($this->baseUrl().$path);
    }

    protected function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(15)->connectTimeout(3)->retry(2, 200, throw: false);
    }

    private function secret(): string
    {
        $secret = @file_get_contents($this->secretFile());
        if (! is_string($secret) || strlen(trim($secret)) < 32) {
            throw new RuntimeException('Workspace control secret is unavailable.');
        }

        return trim($secret);
    }
}
