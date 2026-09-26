<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up():void{
        Schema::table('checks',function(Blueprint $t){$t->date('followup_on')->nullable();$t->foreignId('followup_by')->nullable()->constrained('users')->restrictOnDelete();});
        Schema::create('check_fees',function(Blueprint $t){$t->id();$t->foreignId('check_id')->constrained()->restrictOnDelete();$t->foreignId('expense_id')->unique()->constrained()->restrictOnDelete();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->timestamp('created_at');});
        Schema::create('sales_return_concessions',function(Blueprint $t){$t->id();$t->foreignId('sales_return_id')->constrained()->restrictOnDelete();$t->foreignId('settlement_id')->constrained('customer_settlements')->restrictOnDelete();$t->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();$t->decimal('amount',18,4);$t->decimal('base_amount',18,4);$t->date('document_date');$t->timestamp('created_at');$t->unique(['sales_return_id','settlement_id'],'return_concession_once');});
        foreach(['check_fees','sales_return_concessions'] as $table)foreach(['UPDATE','DELETE'] as $event)DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($event)." BEFORE $event ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Operational history is immutable'");
    }
    public function down():void{Schema::dropIfExists('sales_return_concessions');Schema::dropIfExists('check_fees');Schema::table('checks',function(Blueprint $t){$t->dropConstrainedForeignId('followup_by');$t->dropColumn('followup_on');});}
};
