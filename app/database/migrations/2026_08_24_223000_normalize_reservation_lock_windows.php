<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('reservations')->update([
            'lock_starts_at' => DB::raw('starts_at'),
            'lock_ends_at' => DB::raw('ends_at'),
        ]);
    }

    public function down(): void
    {
        // Exact half-open windows are valid under the original schema. Do not
        // recreate implicit buffers: adjacent reservations may now exist, and
        // expanding either row would manufacture a PostgreSQL exclusion clash.
    }
};
