<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_profiles', function (Blueprint $table): void {
            $table->string('root_directory', 254)->nullable()->after('storage_uuid');
        });

        DB::table('workspace_profiles')->orderBy('id')->each(function (object $profile): void {
            $email = DB::table('users')->where('id', $profile->user_id)->value('email');
            if (is_string($email) && $email !== '') {
                DB::table('workspace_profiles')->where('id', $profile->id)->update([
                    'root_directory' => mb_strtolower(trim($email)),
                ]);
            }
        });

        Schema::create('workspace_projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('directory_name', 64);
            $table->timestampsTz();
            $table->unique(['user_id', 'directory_name']);
        });

        Schema::table('workspace_profiles', function (Blueprint $table): void {
            $table->foreignUuid('selected_project_id')
                ->nullable()
                ->after('root_directory')
                ->constrained('workspace_projects')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('selected_project_id');
        });
        Schema::dropIfExists('workspace_projects');
        Schema::table('workspace_profiles', function (Blueprint $table): void {
            $table->dropColumn('root_directory');
        });
    }
};
