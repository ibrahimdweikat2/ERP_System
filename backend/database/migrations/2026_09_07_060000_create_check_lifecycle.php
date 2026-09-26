<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::create('check_deposit_batches',function(Blueprint $t){$t->id();$t->string('document_no',80)->unique();$t->date('document_date');$t->foreignId('bank_account_id')->constrained()->restrictOnDelete();$t->char('currency',3);$t->decimal('amount',18,4);$t->decimal('base_amount',18,4);$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamp('created_at');});
        Schema::create('checks',function(Blueprint $t){
            $t->id();$t->string('check_no',80);$t->string('bank_name',120);$t->string('bank_branch',120)->nullable();$t->string('payer_name',200);$t->string('account_reference',120);$t->char('identity_hash',64)->unique();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();$t->foreignId('source_payment_id')->unique()->constrained('customer_payments')->restrictOnDelete();
            $t->char('currency',3);$t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();$t->decimal('amount',18,4);$t->decimal('base_amount',18,4);$t->decimal('exchange_rate',18,8);
            $t->date('issue_date');$t->date('due_date');$t->date('received_date');$t->date('last_event_date');$t->string('status',30)->default('received');
            $t->foreignId('deposit_batch_id')->nullable()->constrained('check_deposit_batches')->restrictOnDelete();$t->foreignId('bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $t->date('deposited_on')->nullable();$t->date('cleared_on')->nullable();$t->date('bounced_on')->nullable();$t->string('return_reason',1000)->nullable();$t->string('classification_note',1000)->nullable();
            $t->unsignedBigInteger('replacement_check_id')->nullable();$t->foreign('replacement_check_id')->references('id')->on('checks')->restrictOnDelete();
            $t->foreignId('settlement_payment_id')->nullable()->constrained('customer_payments')->restrictOnDelete();$t->foreignId('return_approval_id')->nullable()->constrained('workflow_approvals')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->text('notes')->nullable();$t->timestamps();$t->index(['status','due_date']);$t->index(['customer_id','status']);
        });
        Schema::create('check_status_history',function(Blueprint $t){$t->id();$t->foreignId('check_id')->constrained('checks')->restrictOnDelete();$t->string('from_status',30)->nullable();$t->string('to_status',30);$t->date('event_date');$t->string('reason',1000);$t->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->timestamp('created_at');});
        Schema::create('check_deposit_batch_items',function(Blueprint $t){$t->id();$t->foreignId('batch_id')->constrained('check_deposit_batches')->restrictOnDelete();$t->foreignId('check_id')->unique()->constrained('checks')->restrictOnDelete();$t->decimal('amount',18,4);$t->decimal('base_amount',18,4);});
        Schema::create('payment_allocation_reversals',function(Blueprint $t){$t->id();$t->foreignId('payment_allocation_id')->unique()->constrained()->restrictOnDelete();$t->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();$t->foreignId('check_id')->constrained('checks')->restrictOnDelete();$t->decimal('amount',18,4);$t->decimal('base_amount',18,4);$t->date('document_date');$t->timestamp('created_at');});
        DB::unprepared("CREATE TRIGGER checks_financial_immutable BEFORE UPDATE ON checks FOR EACH ROW BEGIN IF NOT(OLD.amount <=> NEW.amount) OR NOT(OLD.base_amount <=> NEW.base_amount) OR NOT(OLD.currency <=> NEW.currency) OR NOT(OLD.customer_id <=> NEW.customer_id) OR NOT(OLD.source_payment_id <=> NEW.source_payment_id) OR NOT(OLD.identity_hash <=> NEW.identity_hash) OR NOT(OLD.check_no <=> NEW.check_no) OR NOT(OLD.bank_name <=> NEW.bank_name) OR NOT(OLD.account_reference <=> NEW.account_reference) OR NOT(OLD.payer_name <=> NEW.payer_name) OR NOT(OLD.exchange_rate <=> NEW.exchange_rate) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Check financial identity is immutable'; END IF; END");
        DB::unprepared("CREATE TRIGGER checks_no_delete BEFORE DELETE ON checks FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Retain posted check history'");
        foreach(['check_status_history','check_deposit_batches','check_deposit_batch_items','payment_allocation_reversals'] as $t)foreach(['UPDATE','DELETE'] as $v){$s=strtolower($v);DB::unprepared("CREATE TRIGGER {$t}_immutable_$s BEFORE $v ON $t FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Check history is immutable'");}
    }
    public function down():void{foreach(['payment_allocation_reversals','check_deposit_batch_items','check_status_history','checks','check_deposit_batches'] as $t)Schema::dropIfExists($t);}
};
