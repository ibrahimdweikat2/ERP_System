<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('approval_policies')->insert(['key' => 'purchase_order', 'threshold' => '0', 'segregate_requester' => false, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->string('document_no', 50)->nullable()->unique();
            $t->date('document_date');
            $t->date('expected_on')->nullable();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->json('supplier_snapshot');
            $t->string('supplier_reference', 100)->nullable();
            $t->string('status', 20)->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->char('currency', 3);
            $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $t->char('base_currency', 3);
            $t->foreign('base_currency')->references('code')->on('currencies')->restrictOnDelete();
            $t->decimal('exchange_rate', 18, 8);
            $t->date('exchange_rate_date');
            $t->string('exchange_rate_source', 120);
            $t->decimal('subtotal', 18, 4);
            $t->decimal('tax_total', 18, 4);
            $t->decimal('total', 18, 4);
            $t->decimal('base_total', 18, 4);
            $t->text('notes')->nullable();
            $t->foreignId('approval_id')->nullable()->constrained('approvals')->restrictOnDelete();
            $t->char('approved_payload_hash', 64)->nullable();
            $t->unsignedInteger('approval_policy_version')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('issued_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('issued_at')->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'status', 'document_date']);
        });
        Schema::create('purchase_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->json('product_snapshot');
            $t->decimal('quantity', 18, 4);
            $t->decimal('unit_price', 18, 4);
            $t->decimal('discount_amount', 18, 4);
            $t->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $t->json('tax_snapshot')->nullable();
            $t->decimal('tax_rate', 9, 4);
            $t->boolean('tax_inclusive');
            $t->decimal('taxable_base', 18, 4);
            $t->decimal('tax_amount', 18, 4);
            $t->decimal('total', 18, 4);
            $t->unique(['purchase_order_id', 'product_id'], 'po_product_unique');
        });
        DB::statement('ALTER TABLE purchase_order_lines ADD CONSTRAINT po_line_amounts CHECK (quantity > 0 AND unit_price >= 0 AND discount_amount >= 0 AND taxable_base >= 0 AND tax_amount >= 0 AND total = taxable_base+tax_amount)');
        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT po_header_amounts CHECK (subtotal >= 0 AND tax_total >= 0 AND total = subtotal+tax_total AND base_total >= 0 AND exchange_rate > 0)');
        DB::unprepared("CREATE TRIGGER purchase_orders_issued_insert BEFORE INSERT ON purchase_orders FOR EACH ROW BEGIN IF NEW.status = 'issued' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issue orders through the controlled action'; END IF; END");
        foreach (['UPDATE', 'DELETE'] as $verb) {
            $suffix = strtolower($verb);
            DB::unprepared("CREATE TRIGGER purchase_orders_immutable_$suffix BEFORE $verb ON purchase_orders FOR EACH ROW BEGIN IF OLD.status = 'issued' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued purchase order is immutable'; END IF; END");
        }
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $verb) {
            $suffix = strtolower($verb);
            $ref = $verb === 'DELETE' ? 'OLD' : 'NEW';
            $condition = "EXISTS (SELECT 1 FROM purchase_orders WHERE id=$ref.purchase_order_id AND status='issued')";
            if ($verb === 'UPDATE') {
                $condition .= " OR EXISTS (SELECT 1 FROM purchase_orders WHERE id=OLD.purchase_order_id AND status='issued')";
            }
            DB::unprepared("CREATE TRIGGER po_lines_immutable_$suffix BEFORE $verb ON purchase_order_lines FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued purchase order lines are immutable'; END IF; END");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        DB::table('approval_policies')->where('key','purchase_order')->delete();
    }
};
