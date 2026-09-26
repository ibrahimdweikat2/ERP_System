<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore(['name' => 'purchasing.receive_without_po']);
        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->id();
            $t->string('document_no', 50)->nullable()->unique();
            $t->date('document_date');
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->json('supplier_snapshot');
            $t->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('delivery_reference', 120);
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->char('currency', 3);
            $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $t->char('base_currency', 3);
            $t->foreign('base_currency')->references('code')->on('currencies')->restrictOnDelete();
            $t->decimal('exchange_rate', 18, 8);
            $t->date('exchange_rate_date');
            $t->string('exchange_rate_source', 160);
            $t->decimal('foreign_total', 18, 4);
            $t->decimal('base_total', 18, 4);
            $t->text('notes')->nullable();
            $t->foreignId('posted_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'document_date']);
            $t->index(['purchase_order_id', 'status']);
        });
        Schema::create('goods_receipt_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('goods_receipt_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_line_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->json('product_snapshot');
            $t->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $t->string('condition', 20);
            $t->string('condition_notes', 500)->nullable();
            $t->decimal('quantity', 18, 4);
            $t->json('serials');
            $t->decimal('unit_cost', 18, 8);
            $t->decimal('foreign_value', 18, 4);
            $t->decimal('base_value', 18, 4);
            $t->foreignId('posted_movement_id')->nullable()->constrained('inventory_movements')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE goods_receipts ADD CONSTRAINT gr_values CHECK (foreign_total >= 0 AND base_total >= 0 AND exchange_rate > 0)');
        DB::statement('ALTER TABLE goods_receipt_lines ADD CONSTRAINT gr_line_values CHECK (quantity > 0 AND unit_cost >= 0 AND foreign_value >= 0 AND base_value >= 0)');
        DB::unprepared("CREATE TRIGGER goods_receipts_posted_insert BEFORE INSERT ON goods_receipts FOR EACH ROW BEGIN IF NEW.status='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Post receipts through the controlled action'; END IF; END");
        foreach (['UPDATE', 'DELETE'] as $verb) {
            $suffix = strtolower($verb);
            DB::unprepared("CREATE TRIGGER goods_receipts_immutable_$suffix BEFORE $verb ON goods_receipts FOR EACH ROW BEGIN IF OLD.status='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted goods receipt is immutable'; END IF; END");
        }
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $verb) {
            $suffix = strtolower($verb);
            $ref = $verb === 'DELETE' ? 'OLD' : 'NEW';
            $condition = "EXISTS (SELECT 1 FROM goods_receipts WHERE id=$ref.goods_receipt_id AND status='posted')";
            if ($verb === 'UPDATE') {
                $condition .= " OR EXISTS (SELECT 1 FROM goods_receipts WHERE id=OLD.goods_receipt_id AND status='posted')";
            }
            DB::unprepared("CREATE TRIGGER gr_lines_immutable_$suffix BEFORE $verb ON goods_receipt_lines FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted receipt lines are immutable'; END IF; END");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
    }
};
