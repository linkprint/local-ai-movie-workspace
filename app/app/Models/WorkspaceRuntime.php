<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkspaceRuntime extends Model
{
    use HasUuids;

    public const ACTIVE_STATUSES = ['provisioning', 'running', 'stopping', 'resource_locked'];

    protected $fillable = [
        'user_id',
        'workspace_project_id',
        'status',
        'auth_mode',
        'session_mode',
        'session_id',
        'container_name',
        'network_name',
        'generation',
        'last_seen_at',
        'idle_expires_at',
        'started_at',
        'stopped_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'last_seen_at' => 'immutable_datetime',
            'idle_expires_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'stopped_at' => 'immutable_datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function project()
    {
        return $this->belongsTo(WorkspaceProject::class, 'workspace_project_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function companyCodexLease()
    {
        return $this->hasOne(CompanyCodexLease::class);
    }
}
