<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchasing_invoice_policies', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('version')->default(1);
            $t->string('price_variance_mode', 30)->default('block');
            $t->foreignId('price_variance_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $t->string('nonrecoverable_tax_mode', 30)->default('block');
            $t->foreignId('nonrecoverable_tax_account_id')->nullable()->constrained('accounts', 'id', 'pi_policy_nonrecoverable_account_fk')->restrictOnDelete();
            $t->boolean('require_attachment')->default(false);
            $t->timestamps();
        });
        DB::table('purchasing_invoice_policies')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('supplier_invoices', function (Blueprint $t) {
            $t->id();
            $t->string('document_no', 50)->nullable()->unique();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->json('supplier_snapshot');
            $t->string('supplier_invoice_no', 120);
            $t->unique(['supplier_id', 'supplier_invoice_no'], 'supplier_invoice_number_unique');
            $t->date('invoice_date');
            $t->date('posting_date');
            $t->date('due_date');
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('version')->default(1);
            foreach (['currency', 'base_currency'] as $field) {
                $t->char($field, 3);
                $t->foreign($field)->references('code')->on('currencies')->restrictOnDelete();
            }
            $t->decimal('exchange_rate', 18, 8);
            $t->date('exchange_rate_date');
            $t->string('exchange_rate_source', 160);
            foreach (['net_total', 'tax_total', 'foreign_total', 'base_net_total', 'base_tax_total', 'base_total', 'grni_total', 'price_variance_total', 'fx_variance_total'] as $field) {
                $t->decimal($field, 18, 4);
            }
            $t->unsignedInteger('policy_version');
            $t->json('policy_snapshot');
            $t->string('variance_reason', 1000)->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('posted_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            foreach (['created_by', 'updated_by'] as $field) {
                $t->foreignId($field)->constrained('users')->restrictOnDelete();
            }
            $t->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'posting_date']);
            $t->index(['status', 'due_date']);
        });
        Schema::create('supplier_invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_invoice_id')->constrained()->restrictOnDelete();
            $t->foreignId('goods_receipt_line_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->json('product_snapshot');
            $t->decimal('receipt_exchange_rate', 18, 8);
            foreach (['quantity', 'unit_price', 'discount_amount', 'taxable_base', 'tax_amount', 'total', 'base_net', 'base_tax', 'base_total', 'receipt_foreign_value', 'receipt_base_value', 'price_variance', 'fx_variance'] as $field) {
                $t->decimal($field, 18, 4);
            }
            $t->foreignId('tax_code_id')->constrained()->restrictOnDelete();
            $t->json('tax_snapshot');
            $t->decimal('tax_rate', 9, 4);
            $t->boolean('tax_inclusive');
            $t->boolean('tax_recoverable');
            $t->foreignId('tax_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
        });
        Schema::create('supplier_ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->string('source_type', 50);
            $t->unsignedBigInteger('source_id');
            $t->string('event', 30);
            $t->unique(['source_type', 'source_id', 'event'], 'supplier_ledger_source_unique');
            $t->string('document_no', 80);
            $t->date('posting_date');
            $t->date('due_date')->nullable();
            $t->char('currency', 3);
            $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $t->decimal('foreign_amount', 18, 4);
            $t->decimal('base_amount', 18, 4);
            $t->decimal('exchange_rate', 18, 8);
            $t->foreignId('account_id')->constrained()->restrictOnDelete();
            $t->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->index(['supplier_id', 'currency', 'posting_date']);
        });
        Schema::create('tax_transactions', function (Blueprint $t) {
            $t->id();
            $t->string('source_type', 50);
            $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('source_line_id');
            $t->string('event', 30);
            $t->unique(['source_type', 'source_line_id', 'event'], 'tax_source_unique');
            $t->string('direction', 20);
            $t->date('document_date');
            $t->date('posting_date');
            $t->foreignId('tax_code_id')->constrained()->restrictOnDelete();
            $t->json('tax_snapshot');
            $t->char('currency', 3);
            $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $t->decimal('exchange_rate', 18, 8);
            $t->decimal('tax_rate', 9, 4);
            $t->boolean('tax_inclusive');
            $t->boolean('tax_recoverable');
            foreach (['taxable_base', 'tax_amount', 'base_taxable_amount', 'base_tax_amount', 'recoverable_base_amount'] as $field) {
                $t->decimal($field, 18, 4);
            }
            $t->foreignId('account_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->index(['posting_date', 'direction', 'tax_code_id']);
        });
        Schema::create('document_attachments', function (Blueprint $t) {
            $t->id();
            $t->string('entity_type', 50);
            $t->unsignedBigInteger('entity_id');
            $t->string('original_name', 255);
            $t->string('stored_path', 255)->unique();
            $t->string('mime_type', 80);
            $t->unsignedBigInteger('size');
            $t->char('checksum', 64);
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->string('visibility_classification', 50)->default('financial_private');
            $t->timestamp('created_at');
            $t->unique(['entity_type', 'entity_id', 'checksum'], 'attachment_parent_checksum_unique');
        });
        DB::statement('ALTER TABLE supplier_invoices ADD CONSTRAINT pi_totals CHECK (net_total >= 0 AND tax_total >= 0 AND foreign_total = net_total + tax_total AND base_total = base_net_total + base_tax_total AND exchange_rate > 0)');
        DB::statement('ALTER TABLE supplier_invoice_lines ADD CONSTRAINT pi_line_values CHECK (quantity > 0 AND unit_price >= 0 AND discount_amount >= 0 AND taxable_base >= 0 AND tax_amount >= 0 AND total = taxable_base + tax_amount AND base_total = base_net + base_tax AND receipt_foreign_value >= 0 AND receipt_base_value >= 0)');
        DB::unprepared("CREATE TRIGGER supplier_invoices_draft_insert BEFORE INSERT ON supplier_invoices FOR EACH ROW BEGIN IF NEW.status='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Create invoice draft before posting'; END IF; END");
        foreach (['UPDATE', 'DELETE'] as $verb) {
            $suffix = strtolower($verb);
            DB::unprepared("CREATE TRIGGER supplier_invoices_immutable_$suffix BEFORE $verb ON supplier_invoices FOR EACH ROW BEGIN IF OLD.status='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted supplier invoice is immutable'; END IF; END");
        }
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $verb) {
            $suffix = strtolower($verb);
            $ref = $verb === 'DELETE' ? 'OLD' : 'NEW';
            $condition = "EXISTS (SELECT 1 FROM supplier_invoices WHERE id=$ref.supplier_invoice_id AND status='posted')";
            if ($verb === 'UPDATE') {
                $condition .= " OR EXISTS (SELECT 1 FROM supplier_invoices WHERE id=OLD.supplier_invoice_id AND status='posted')";
            }
            DB::unprepared("CREATE TRIGGER pi_lines_immutable_$suffix BEFORE $verb ON supplier_invoice_lines FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted supplier invoice lines are immutable'; END IF; END");
        }
        foreach (['supplier_ledger_entries', 'tax_transactions', 'document_attachments'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $verb) {
                $suffix = strtolower($verb);
                DB::unprepared("CREATE TRIGGER {$table}_immutable_$suffix BEFORE $verb ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Retained source record is immutable'");
            }
        }
    }

    public function down(): void
    {
        foreach (['document_attachments', 'tax_transactions', 'supplier_ledger_entries', 'supplier_invoice_lines', 'supplier_invoices', 'purchasing_invoice_policies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
