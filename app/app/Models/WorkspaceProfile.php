<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkspaceProfile extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'storage_uuid', 'root_directory', 'selected_project_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function selectedProject()
    {
        return $this->belongsTo(WorkspaceProject::class, 'selected_project_id');
    }
}
