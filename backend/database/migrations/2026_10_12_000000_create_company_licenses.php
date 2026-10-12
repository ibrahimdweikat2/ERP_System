<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One LicenseBox license per company. A platform table (see TenantTables::GLOBAL_TABLES):
// the license describes the company, it is not company data, so company backups leave it out
// and a license key can be held by one company only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_licenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique()->constrained('companies')->cascadeOnDelete();
            $t->text('license_key');                  // encrypted
            $t->char('license_hash', 64)->unique();   // sha256 of the key: one company per key
            $t->string('instance_id', 64);
            $t->text('local_key');                    // server-signed token; validity is read from it, never from the columns below
            $t->json('data')->nullable();             // last activation / verification payload
            // active | rejected (server refused for now, re-checked) | invalid (must be activated again)
            $t->string('status', 20);
            $t->string('reason', 60)->nullable();
            $t->string('last_error', 500)->nullable();
            $t->timestamp('activated_at');
            $t->timestamp('last_checked_at')->nullable();
            $t->timestamp('next_check_at')->nullable()->index();
            $t->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_licenses');
    }
};
