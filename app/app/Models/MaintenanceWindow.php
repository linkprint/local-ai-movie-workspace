<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MaintenanceWindow extends Model
{
    use HasUuids;

    protected $fillable = ['compute_node_id', 'starts_at', 'ends_at', 'reason', 'created_by', 'automatic'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'automatic' => 'boolean',
        ];
    }

    public function computeNode()
    {
        return $this->belongsTo(ComputeNode::class);
    }
}
