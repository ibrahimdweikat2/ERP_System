<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('sales_invoices',fn(Blueprint $t)=>$t->foreignId('credit_approval_id')->nullable()->constrained('workflow_approvals')->restrictOnDelete());
        Schema::create('installment_contracts',function(Blueprint $t){
            $t->id(); $t->string('document_no',80)->unique(); $t->foreignId('customer_id')->constrained()->restrictOnDelete(); $t->foreignId('sales_invoice_id')->unique()->constrained()->restrictOnDelete();
            $t->date('document_date'); $t->char('currency',3); $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete(); $t->decimal('exchange_rate',18,8);
            foreach(['original_amount','down_payment','financed_amount','cash_price','markup'] as $f)$t->decimal($f,18,4);
            $t->unsignedInteger('schedule_version')->default(1); $t->string('status',20)->default('active'); $t->json('policy_snapshot'); $t->text('terms')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps(); $t->index(['customer_id','status']);
        });
        Schema::create('installment_schedule',function(Blueprint $t){
            $t->id(); $t->foreignId('contract_id')->constrained('installment_contracts')->restrictOnDelete(); $t->unsignedInteger('version'); $t->unsignedInteger('sequence_no'); $t->date('due_date');
            $t->decimal('amount',18,4); $t->decimal('paid_amount',18,4)->default(0); $t->decimal('adjustment_amount',18,4)->default(0); $t->boolean('superseded')->default(false);
            $t->unsignedBigInteger('reopened_from_id')->nullable(); $t->foreign('reopened_from_id')->references('id')->on('installment_schedule')->restrictOnDelete(); $t->timestamps();
            $t->index(['superseded','due_date']); $t->index(['contract_id','version','sequence_no']);
        });
        Schema::create('installment_allocations',function(Blueprint $t){
            $t->id(); $t->foreignId('payment_allocation_id')->constrained()->restrictOnDelete(); $t->foreignId('schedule_id')->constrained('installment_schedule')->restrictOnDelete(); $t->decimal('amount',18,4); $t->timestamp('created_at');
            $t->unique(['payment_allocation_id','schedule_id'],'installment_allocation_unique');
        });
        Schema::create('installment_reschedules',function(Blueprint $t){
            $t->id(); $t->foreignId('contract_id')->constrained('installment_contracts')->restrictOnDelete(); $t->unsignedInteger('old_version'); $t->json('old_schedule'); $t->json('new_schedule'); $t->string('reason',1000);
            $t->string('status',20)->default('pending'); $t->foreignId('approval_id')->nullable()->constrained('workflow_approvals')->restrictOnDelete(); $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamp('applied_at')->nullable(); $t->timestamps();
        });
        Schema::create('installment_adjustments',function(Blueprint $t){
            $t->id(); $t->foreignId('schedule_id')->constrained('installment_schedule')->restrictOnDelete(); $t->string('source_type',50); $t->unsignedBigInteger('source_id'); $t->decimal('amount',18,4); $t->timestamp('created_at');
            $t->unique(['source_type','source_id','schedule_id'],'installment_adjustment_source_unique');
        });
        DB::statement('ALTER TABLE installment_schedule ADD CONSTRAINT installment_amounts CHECK (amount > 0 AND paid_amount >= 0 AND adjustment_amount >= 0 AND paid_amount + adjustment_amount <= amount)');
        foreach(['installment_allocations','installment_adjustments'] as $table)foreach(['UPDATE','DELETE'] as $verb){$s=strtolower($verb);DB::unprepared("CREATE TRIGGER {$table}_immutable_$s BEFORE $verb ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Installment allocation history is immutable'");}
    }
    public function down():void{
        foreach(['installment_adjustments','installment_reschedules','installment_allocations','installment_schedule','installment_contracts'] as $t)Schema::dropIfExists($t);
        Schema::table('sales_invoices',fn(Blueprint $t)=>$t->dropConstrainedForeignId('credit_approval_id'));
    }
};
