<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40)->unique();
            $t->string('legal_name', 180);
            $t->string('trade_name', 180)->nullable();
            $t->string('tax_number', 80)->nullable()->index();
            $t->string('address', 500)->nullable();
            $t->json('contacts');
            $t->string('currency', 3);
            $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $t->unsignedSmallInteger('payment_terms_days')->default(0);
            $t->decimal('credit_limit', 18, 4)->default(0);
            $t->text('bank_info')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['active', 'legal_name']);
        });
        DB::statement('ALTER TABLE suppliers ADD CONSTRAINT suppliers_nonnegative_credit CHECK (credit_limit >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
