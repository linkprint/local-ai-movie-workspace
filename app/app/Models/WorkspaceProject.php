<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkspaceProject extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'name', 'directory_name'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function runtime()
    {
        return $this->hasOne(WorkspaceRuntime::class);
    }
}
