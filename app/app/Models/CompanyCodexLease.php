<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyCodexLease extends Model
{
    public const SINGLETON_ID = 1;

    public const OCCUPIED_STATUSES = ['acquiring', 'active', 'releasing', 'resource_locked'];

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'workspace_runtime_id',
        'user_id',
        'status',
        'fencing_token',
        'acquired_at',
        'heartbeat_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'acquired_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    public function runtime()
    {
        return $this->belongsTo(WorkspaceRuntime::class, 'workspace_runtime_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
