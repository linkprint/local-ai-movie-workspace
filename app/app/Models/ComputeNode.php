<?php

namespace App\Models;

use App\Enums\ComputeNodeSchedulingState;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ComputeNode extends Model
{
    use HasUuids;

    public const LOCAL_NODE_ID = '20000000-0000-4000-8000-000000000020';

    public const SECONDARY_NODE_ID = '20000000-0000-4000-8000-000000000200';

    public const LOCAL_NODE_SLUG = 'ai-server-01';

    public const SECONDARY_NODE_SLUG = 'ai-server-02';

    protected $fillable = [
        'slug', 'display_name', 'host_ip', 'visible_in_reservations',
        'scheduling_state', 'sort_order', 'capabilities', 'worker_revision',
        'workflow_revision', 'model_manifest_sha256', 'last_heartbeat_at',
        'last_health_summary', 'last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'visible_in_reservations' => 'boolean',
            'scheduling_state' => ComputeNodeSchedulingState::class,
            'sort_order' => 'integer',
            'capabilities' => 'array',
            'last_heartbeat_at' => 'immutable_datetime',
            'last_health_summary' => 'array',
        ];
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function maintenanceWindows()
    {
        return $this->hasMany(MaintenanceWindow::class);
    }

    public function scopeVisibleInReservations($query)
    {
        return $query->where('visible_in_reservations', true);
    }

    public static function local(): self
    {
        return self::query()->findOrFail(self::LOCAL_NODE_ID);
    }
}
