<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->timestampTz('workspace_stopped_at')->nullable();
            $table->index(
                ['status', 'workspace_stopped_at'],
                'reservations_workspace_stopped_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropIndex('reservations_workspace_stopped_index');
            $table->dropColumn('workspace_stopped_at');
        });
    }
};
