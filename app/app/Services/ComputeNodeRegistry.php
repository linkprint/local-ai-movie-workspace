<?php

namespace App\Services;

use App\Models\ComputeNode;
use Illuminate\Support\Str;

class ComputeNodeRegistry
{
    public function newSlug(): string
    {
        do {
            $slug = 'ai-server-'.Str::lower(Str::random(10));
        } while (ComputeNode::query()->where('slug', $slug)->exists());

        return $slug;
    }

    public function brokerUrl(ComputeNode $node): string
    {
        if ($node->slug === (string) config('movie.local_compute_node_slug')) {
            return rtrim((string) config('movie.local_node_broker_url'), '/');
        }

        return sprintf('http://%s:%d', $node->host_ip, (int) config('movie.node_worker_port'));
    }
}
