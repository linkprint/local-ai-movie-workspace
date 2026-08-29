<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'actor_id', 'action', 'target_type', 'target_id', 'ip_address',
        'request_id', 'metadata', 'created_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public static function record(string $action, ?Model $target = null, array $metadata = []): self
    {
        $request = app()->bound('request') ? request() : null;
        $requestId = $request?->headers->get('X-Request-Id');

        return self::create([
            'actor_id' => auth()->id(),
            'action' => $action,
            'target_type' => $target?->getMorphClass(),
            'target_id' => $target?->getKey(),
            'ip_address' => $request?->ip(),
            'request_id' => is_string($requestId) && Str::isUuid($requestId) ? $requestId : null,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
