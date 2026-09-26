<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['supplier_payments', 'supplier_credit_notes'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id();
                $t->string('document_no', 80)->nullable()->unique();
                $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
                $t->json('supplier_snapshot');
                $t->date('document_date');
                $t->string('status', 20)->default('draft');
                $t->unsignedInteger('version')->default(1);
                $t->char('currency', 3);
                $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
                $t->char('base_currency', 3);
                $t->foreign('base_currency')->references('code')->on('currencies')->restrictOnDelete();
                $t->decimal('exchange_rate', 18, 8);
                $t->date('exchange_rate_date');
                $t->string('exchange_rate_source', 160);
                $t->decimal('amount', 18, 4)->default(0);
                $t->decimal('base_amount', 18, 4)->default(0);
                $t->string('reason', 1000);
                $t->json('payload');
                if ($table === 'supplier_payments') {
                    $t->string('method', 20);
                    $t->foreignId('cashbox_id')->nullable()->constrained()->restrictOnDelete();
                    $t->foreignId('bank_account_id')->nullable()->constrained()->restrictOnDelete();
                    $t->string('payment_reference', 160)->nullable();
                } else {
                    $t->foreignId('supplier_invoice_id')->constrained()->restrictOnDelete();
                }
                $t->foreignId('posted_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $t->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
                $t->timestamp('posted_at')->nullable();
                $t->timestamps();
                $t->index(['supplier_id', 'currency', 'document_date']);
                $t->index(['status', 'document_date']);
            });
        }
        Schema::create('supplier_payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_payment_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_invoice_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 18, 4);
            $t->decimal('base_amount', 18, 4);
            $t->unique(['supplier_payment_id', 'supplier_invoice_id'], 'supplier_payment_invoice_unique');
        });
        Schema::create('supplier_credit_note_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_credit_note_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_invoice_line_id')->constrained()->restrictOnDelete();
            $t->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $t->json('serials');
            $t->json('product_snapshot');
            foreach (['quantity', 'net', 'tax', 'amount', 'base_net', 'base_tax', 'base_amount', 'stock_cost'] as $f) {
                $t->decimal($f, 18, 4);
            }
            $t->foreignId('inventory_movement_id')->nullable()->constrained()->restrictOnDelete();
        });
        foreach (['supplier_payments', 'supplier_credit_notes'] as $table) {
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_amount_positive CHECK (amount >= 0 AND base_amount >= 0 AND exchange_rate > 0)");
            DB::unprepared("CREATE TRIGGER {$table}_draft_insert BEFORE INSERT ON $table FOR EACH ROW BEGIN IF NEW.status='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Create draft first'; END IF; END");
            foreach (['UPDATE', 'DELETE'] as $verb) {
                $suffix = strtolower($verb);
                DB::unprepared("CREATE TRIGGER {$table}_immutable_$suffix BEFORE $verb ON $table FOR EACH ROW BEGIN IF OLD.status='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted supplier settlement is immutable'; END IF; END");
            }
        }
        foreach (['supplier_payment_allocations' => 'supplier_payments', 'supplier_credit_note_lines' => 'supplier_credit_notes'] as $table => $parent) {
            $fk = $table === 'supplier_payment_allocations' ? 'supplier_payment_id' : 'supplier_credit_note_id';
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $verb) {
                $suffix = strtolower($verb);
                $ref = $verb === 'DELETE' ? 'OLD' : 'NEW';
                $condition = "EXISTS (SELECT 1 FROM $parent WHERE id=$ref.$fk AND status='posted')";
                if ($verb === 'UPDATE') $condition .= " OR EXISTS (SELECT 1 FROM $parent WHERE id=OLD.$fk AND status='posted')";
                DB::unprepared("CREATE TRIGGER {$table}_immutable_$suffix BEFORE $verb ON $table FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted settlement detail is immutable'; END IF; END");
            }
        }
    }

    public function down(): void
    {
        foreach (['supplier_credit_note_lines', 'supplier_payment_allocations', 'supplier_credit_notes', 'supplier_payments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
