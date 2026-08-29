<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'email_verified_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('email_verified_at');
            });
        }

        if (Schema::hasColumn('users', 'disabled_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('disabled_at');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'email_verified_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            });
        }

        if (! Schema::hasColumn('users', 'disabled_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestampTz('disabled_at')->nullable()->after('timezone');
            });
        }
    }
};
