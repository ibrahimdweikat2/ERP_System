<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Multi-company step 1: the companies table. The existing store becomes company 1;
// on a fresh install company 1 holds the default rows older migrations inserted.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $t) {
                $t->id();
                $t->string('name', 160)->unique();
                $t->string('status', 20)->default('active')->index();
                $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $t->timestamps();
            });
        }
        if (! DB::table('companies')->where('id', 1)->exists()) {
            $store = DB::table('store_settings')->orderBy('id')->first();
            // The literal fallback keeps this working even under a config cache from before multi-company.
            $name = trim((string) ($store->trade_name ?? '')) ?: (trim((string) ($store->platform_name ?? '')) ?: (config('erp.default_company_name') ?: 'الشركة الأولى'));
            DB::table('companies')->insert(['id' => 1, 'name' => $name, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Multi-company migrations are not reversible; restore the pre-migration backup.');
    }
};
