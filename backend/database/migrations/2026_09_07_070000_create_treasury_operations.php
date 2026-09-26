<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::create('cashier_sessions',function(Blueprint $t){
            $t->id();$t->foreignId('cashbox_id')->constrained()->restrictOnDelete();$t->foreignId('cashier_id')->constrained('users')->restrictOnDelete();$t->string('status',20)->default('open');$t->date('opened_on');$t->decimal('opening_amount',18,4);$t->decimal('system_opening_amount',18,4);$t->decimal('counted_amount',18,4)->nullable();$t->decimal('expected_amount',18,4)->nullable();$t->decimal('variance',18,4)->nullable();$t->string('reason',1000)->nullable();$t->foreignId('approval_id')->nullable()->constrained('workflow_approvals')->restrictOnDelete();$t->timestamp('closed_at')->nullable();$t->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();$t->timestamps();$t->index(['cashier_id','status']);
        });
        DB::statement("ALTER TABLE cashier_sessions ADD active_cashbox_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status='open' THEN cashbox_id ELSE NULL END) STORED, ADD UNIQUE KEY one_open_cashbox(active_cashbox_id)");
        Schema::create('treasury_movements',function(Blueprint $t){
            $t->id();$t->foreignId('journal_line_id')->unique()->constrained()->restrictOnDelete();$t->foreignId('account_id')->constrained()->restrictOnDelete();$t->foreignId('cashbox_id')->nullable()->constrained()->restrictOnDelete();$t->foreignId('bank_account_id')->nullable()->constrained()->restrictOnDelete();$t->foreignId('session_id')->nullable()->constrained('cashier_sessions')->restrictOnDelete();$t->date('document_date');$t->char('currency',3);$t->decimal('amount',18,4);$t->decimal('base_amount',18,4);$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamp('created_at');$t->index(['bank_account_id','document_date']);$t->index(['cashbox_id','document_date']);
        });
        foreach(['expenses','cash_transfers'] as $table)Schema::create($table,function(Blueprint $t)use($table){
            $t->id();$t->string('document_no',80)->nullable()->unique();$t->date('document_date');$t->string('status',20)->default('draft');$t->char('currency',3);$t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();$t->char('base_currency',3);$t->decimal('exchange_rate',18,8);$t->date('exchange_rate_date');$t->string('exchange_rate_source',160);$t->decimal('amount',18,4);$t->decimal('base_amount',18,4);$t->string('description',1000);$t->json('payload');$t->foreignId('approval_id')->nullable()->constrained('workflow_approvals')->restrictOnDelete();$t->foreignId('posted_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();$t->timestamp('posted_at')->nullable();$t->timestamps();$t->index(['status','document_date']);
        });
        Schema::create('bank_statement_lines',function(Blueprint $t){$t->id();$t->foreignId('bank_account_id')->constrained()->restrictOnDelete();$t->date('document_date');$t->string('reference',160);$t->string('description',1000)->nullable();$t->decimal('amount',18,4);$t->char('fingerprint',64)->unique();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamps();$t->index(['bank_account_id','document_date']);});
        Schema::create('bank_reconciliations',function(Blueprint $t){$t->id();$t->foreignId('statement_line_id')->unique()->constrained('bank_statement_lines')->restrictOnDelete();$t->foreignId('treasury_movement_id')->unique()->constrained()->restrictOnDelete();$t->string('reason',1000);$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamp('created_at');});
        foreach(['treasury_movements','bank_reconciliations'] as $t)foreach(['UPDATE','DELETE'] as $v){$s=strtolower($v);DB::unprepared("CREATE TRIGGER {$t}_immutable_$s BEFORE $v ON $t FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Treasury ledger is immutable'");}
        foreach(['expenses','cash_transfers'] as $t)foreach(['UPDATE','DELETE'] as $v){$s=strtolower($v);DB::unprepared("CREATE TRIGGER {$t}_immutable_$s BEFORE $v ON $t FOR EACH ROW BEGIN IF OLD.status='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted treasury document is immutable'; END IF; END");}
        DB::unprepared("CREATE TRIGGER cashier_session_closed_immutable BEFORE UPDATE ON cashier_sessions FOR EACH ROW BEGIN IF OLD.status='closed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Closed cash session is immutable'; END IF; END");
    }
    public function down():void{foreach(['bank_reconciliations','bank_statement_lines','cash_transfers','expenses','treasury_movements','cashier_sessions'] as $t)Schema::dropIfExists($t);}
};
