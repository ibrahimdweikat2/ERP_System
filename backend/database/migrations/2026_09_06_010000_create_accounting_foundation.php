<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $t) {
            $t->char('code', 3)->primary();
            $t->string('name', 80);
            $t->unsignedTinyInteger('decimal_places')->default(2);
            $t->boolean('is_active')->default(true);
            $t->boolean('is_base')->default(false);
        });
        Schema::create('store_settings', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->string('trade_name', 160)->default('');
            $t->string('legal_name', 160)->nullable();
            $t->string('owner_name', 120)->nullable();
            $t->string('address', 500)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('email')->nullable();
            $t->string('tax_number', 80)->nullable();
            $t->boolean('vat_registered')->default(false);
            $t->char('base_currency', 3)->default('ILS');
            $t->foreign('base_currency')->references('code')->on('currencies')->restrictOnDelete();
            $t->string('timezone', 80)->default('Asia/Hebron');
            $t->string('locale', 5)->default('ar');
            $t->string('price_display', 20)->default('exclusive');
            $t->text('invoice_footer')->nullable();
            $t->timestamp('accounting_configured_at')->nullable();
            $t->timestamp('setup_completed_at')->nullable();
            $t->timestamps();
        });
        DB::statement('ALTER TABLE store_settings ADD CONSTRAINT single_store CHECK (id = 1)');
        Schema::create('exchange_rates', function (Blueprint $t) {
            $t->id();
            $t->char('currency_code', 3);
            $t->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $t->date('rate_date');
            $t->decimal('rate_to_base', 18, 8);
            $t->string('source', 160);
            $t->boolean('locked')->default(true);
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->unique(['currency_code', 'rate_date']);
        });
        Schema::create('fiscal_years', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80)->unique();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 20)->default('open');
            $t->timestamps();
            $t->index(['starts_on', 'ends_on']);
        });
        Schema::create('accounting_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fiscal_year_id')->constrained()->restrictOnDelete();
            $t->unsignedTinyInteger('period_no');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 20)->default('open');
            $t->timestamp('locked_at')->nullable();
            $t->foreignId('locked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unique(['fiscal_year_id', 'period_no']);
            $t->index(['starts_on', 'ends_on', 'status']);
        });
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name_ar', 160);
            $t->string('name_en', 160)->nullable();
            $t->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $t->string('account_type', 20);
            $t->string('normal_balance', 10);
            $t->boolean('is_control_account')->default(false);
            $t->boolean('allow_manual_posting')->default(true);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('journals', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name_ar', 120);
            $t->boolean('active')->default(true);
        });
        Schema::create('account_mappings', function (Blueprint $t) {
            $t->string('key', 60)->primary();
            $t->foreignId('account_id')->constrained()->restrictOnDelete();
        });
        Schema::create('tax_codes', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30);
            $t->string('name_ar', 120);
            $t->string('category', 20);
            $t->decimal('rate', 9, 4);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->foreignId('input_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('output_account_id')->constrained('accounts')->restrictOnDelete();
            $t->unique(['code', 'effective_from']);
            $t->index(['code', 'effective_from', 'effective_to']);
        });
        Schema::create('cashboxes', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->foreignId('account_id')->unique()->constrained('accounts')->restrictOnDelete();
            $t->char('currency_code', 3);
            $t->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('bank_name', 120);
            $t->text('account_number')->nullable();
            $t->text('iban')->nullable();
            $t->foreignId('account_id')->unique()->constrained('accounts')->restrictOnDelete();
            $t->char('currency_code', 3);
            $t->foreign('currency_code')->references('code')->on('currencies')->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        DB::unprepared("CREATE TRIGGER exchange_rates_no_update BEFORE UPDATE ON exchange_rates FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Exchange rates are immutable'");
        DB::unprepared("CREATE TRIGGER exchange_rates_no_delete BEFORE DELETE ON exchange_rates FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Exchange rates are immutable'");
    }

    public function down(): void
    {
        foreach (['bank_accounts', 'cashboxes', 'tax_codes', 'account_mappings', 'journals', 'accounts', 'accounting_periods', 'fiscal_years', 'exchange_rates', 'store_settings', 'currencies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
