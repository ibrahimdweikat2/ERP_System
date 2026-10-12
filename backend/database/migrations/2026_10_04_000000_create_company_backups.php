<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Per-company backups: each company's owner backs up only that company's rows.
// The whole-platform backup (backup_runs, daily at 02:00) is unchanged.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_backups', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('status', 20);
            // Relative to config('erp.backup_directory'), e.g. companies/1/company-1-20261004-153000-7.sql
            $t->string('path', 1000)->nullable();
            $t->string('file_name', 255)->nullable();
            $t->unsignedBigInteger('size')->nullable();
            $t->char('checksum', 64)->nullable();
            $t->string('error', 500)->nullable();
            $t->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at');
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->index(['company_id', 'id']);
        });

        // Existing owners hold every permission already (User::isOwner); the row keeps the roles page accurate.
        DB::table('permissions')->insertOrIgnore(['name' => 'backups.manage']);
        $permission = DB::table('permissions')->where('name', 'backups.manage')->value('id');
        foreach (DB::table('roles')->where('name', 'owner')->get(['id', 'company_id']) as $role) {
            DB::table('role_permissions')->insertOrIgnore(['company_id' => $role->company_id, 'role_id' => $role->id, 'permission_id' => $permission]);
        }
    }

    public function down(): void
    {
        $permission = DB::table('permissions')->where('name', 'backups.manage')->value('id');
        if ($permission) {
            DB::table('role_permissions')->where('permission_id', $permission)->delete();
            DB::table('permissions')->where('id', $permission)->delete();
        }
        Schema::dropIfExists('company_backups');
    }
};
