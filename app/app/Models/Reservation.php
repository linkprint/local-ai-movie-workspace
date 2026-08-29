<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id', 'compute_node_id', 'starts_at', 'ends_at', 'lock_starts_at', 'lock_ends_at',
        'status', 'purpose', 'activated_at', 'first_connected_at',
        'workspace_stopped_at', 'cancelled_at', 'end_reason', 'broker_token',
        'workspace_runtime_id', 'ai_grant_generation', 'ai_granted_at', 'ai_revoked_at',
    ];

    protected $hidden = ['broker_token'];

    protected static function booted(): void
    {
        static::creating(function (Reservation $reservation): void {
            $reservation->compute_node_id ??= ComputeNode::LOCAL_NODE_ID;
        });
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'lock_starts_at' => 'immutable_datetime',
            'lock_ends_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'first_connected_at' => 'immutable_datetime',
            'workspace_stopped_at' => 'immutable_datetime',
            'ai_grant_generation' => 'integer',
            'ai_granted_at' => 'immutable_datetime',
            'ai_revoked_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'status' => ReservationStatus::class,
            'broker_token' => 'encrypted',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function computeNode()
    {
        return $this->belongsTo(ComputeNode::class);
    }

    public function workspaceRuntime()
    {
        return $this->belongsTo(WorkspaceRuntime::class);
    }
}
