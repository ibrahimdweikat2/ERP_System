<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_policies',function(Blueprint $t){ $t->string('key',50)->primary(); $t->unsignedInteger('version')->default(1); $t->json('settings'); $t->timestamps(); });
        foreach ([
            'sales'=>['discount_percent'=>'0','return_window_days'=>30,'segregate_requester'=>false],
            'installments'=>['minimum_down_payment_percent'=>'0','markup_recognition'=>'unconfigured','segregate_requester'=>false,'allow_credit_override'=>true,'grace_days'=>0],
            'treasury'=>['enforce_sessions'=>false,'expense_approval_threshold'=>'0','variance_approval_threshold'=>'0','segregate_requester'=>false],
            'checks'=>['ar_recognition'=>'on_receipt','allow_early_deposit'=>false,'segregate_requester'=>false],
        ] as $key=>$settings) DB::table('business_policies')->insert(['key'=>$key,'settings'=>json_encode($settings,JSON_THROW_ON_ERROR),'created_at'=>now(),'updated_at'=>now()]);
        Schema::create('workflow_approvals',function(Blueprint $t){
            $t->id(); $t->string('source_type',50); $t->unsignedBigInteger('source_id'); $t->unsignedInteger('source_version');
            $t->char('payload_hash',64); $t->json('payload'); $t->string('policy_key',50); $t->unsignedInteger('policy_version');
            $t->string('status',20)->default('pending'); $t->string('reason',1000);
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete(); $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable(); $t->string('decision_reason',1000)->nullable(); $t->timestamps(); $t->index(['source_type','source_id','status']);
        });
        Schema::create('customers',function(Blueprint $t){
            $t->id(); $t->string('code',50)->unique(); $t->string('name',200); $t->text('identity_number')->nullable(); $t->string('tax_number',60)->nullable();
            $t->string('phone',60)->nullable()->index(); $t->string('email',200)->nullable(); $t->string('address',1000)->nullable(); $t->string('workplace',200)->nullable();
            $t->char('currency',3); $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $t->decimal('credit_limit',18,4)->default(0); $t->unsignedInteger('max_active_contracts')->default(1); $t->unsignedInteger('max_overdue_days')->default(0);
            $t->string('risk_flag',20)->default('normal'); $t->boolean('is_walk_in')->default(false); $t->boolean('active')->default(true); $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1); $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps();
        });
        Schema::create('customer_followups',function(Blueprint $t){ $t->id(); $t->foreignId('customer_id')->constrained()->restrictOnDelete(); $t->text('note'); $t->date('followup_on')->nullable()->index(); $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamps(); });
        Schema::create('sales_invoices',function(Blueprint $t){
            $this->header($t); $t->foreignId('customer_id')->constrained()->restrictOnDelete(); $t->json('customer_snapshot');
            $t->date('due_date'); $t->string('sale_mode',20); $t->json('checkout'); $t->json('warnings');
            $t->foreignId('approval_id')->nullable()->constrained('workflow_approvals')->restrictOnDelete(); $t->unsignedInteger('policy_version');
            foreach (['net_total','tax_total','foreign_total','base_net_total','base_tax_total','base_total','cogs_total'] as $f) $t->decimal($f,18,4)->default(0);
            $t->string('fulfillment_status',30)->default('pending'); $t->string('delivery_address',1000)->nullable(); $t->date('delivery_date')->nullable();
            $t->index(['customer_id','currency','document_date']);
        });
        Schema::create('sales_invoice_lines',function(Blueprint $t){
            $t->id(); $t->foreignId('sales_invoice_id')->constrained()->restrictOnDelete(); $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete(); $t->json('product_snapshot'); $t->json('serials'); $t->json('warranty_snapshot')->nullable();
            foreach (['quantity','unit_price','list_price','discount_amount','taxable_base','tax_amount','total','base_net','base_tax','base_total','cogs'] as $f) $t->decimal($f,18,4)->default(0);
            $t->foreignId('tax_code_id')->constrained()->restrictOnDelete(); $t->json('tax_snapshot'); $t->decimal('tax_rate',9,4); $t->boolean('tax_inclusive');
            $t->foreignId('tax_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $t->foreignId('inventory_movement_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::create('customer_payments',function(Blueprint $t){
            $this->header($t); $t->foreignId('customer_id')->constrained()->restrictOnDelete(); $t->json('customer_snapshot');
            $t->decimal('amount',18,4); $t->decimal('base_amount',18,4); $t->string('method',20); $t->string('payment_reference',160)->nullable();
            $t->foreignId('cashbox_id')->nullable()->constrained()->restrictOnDelete(); $t->foreignId('bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $t->json('allocation_request'); $t->index(['customer_id','currency','document_date']);
        });
        Schema::create('payment_allocations',function(Blueprint $t){
            $t->id(); $t->foreignId('customer_payment_id')->constrained()->restrictOnDelete(); $t->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $t->decimal('amount',18,4); $t->decimal('base_amount',18,4); $t->unique(['customer_payment_id','sales_invoice_id'],'customer_payment_invoice_unique');
        });
        Schema::create('sales_returns',function(Blueprint $t){
            $this->header($t); $t->foreignId('customer_id')->constrained()->restrictOnDelete(); $t->json('customer_snapshot'); $t->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $t->json('payload'); $t->decimal('amount',18,4)->default(0); $t->decimal('base_amount',18,4)->default(0);
            $t->foreignId('approval_id')->nullable()->constrained('workflow_approvals')->restrictOnDelete(); $t->index(['customer_id','document_date']);
        });
        Schema::create('sales_return_lines',function(Blueprint $t){
            $t->id(); $t->foreignId('sales_return_id')->constrained()->restrictOnDelete(); $t->foreignId('sales_invoice_line_id')->constrained()->restrictOnDelete();
            $t->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete(); $t->json('serials'); $t->json('product_snapshot'); $t->string('condition',30);
            foreach (['quantity','net','tax','amount','base_net','base_tax','base_amount','cogs'] as $f) $t->decimal($f,18,4);
            $t->foreignId('inventory_movement_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::create('customer_ledger_entries',function(Blueprint $t){
            $t->id(); $t->foreignId('customer_id')->constrained()->restrictOnDelete(); $t->string('source_type',50); $t->unsignedBigInteger('source_id'); $t->string('event',30);
            $t->unique(['source_type','source_id','event'],'customer_ledger_source_unique'); $t->string('document_no',80); $t->date('posting_date'); $t->date('due_date')->nullable();
            $t->char('currency',3); $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $t->decimal('foreign_amount',18,4); $t->decimal('base_amount',18,4); $t->decimal('exchange_rate',18,8);
            $t->foreignId('account_id')->constrained()->restrictOnDelete(); $t->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete(); $t->timestamp('created_at'); $t->index(['customer_id','currency','posting_date']);
        });
        Schema::table('serial_numbers',function(Blueprint $t){ $t->foreignId('sold_sales_line_id')->nullable()->constrained('sales_invoice_lines')->restrictOnDelete(); $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete(); });
        foreach (['sales_invoices','customer_payments','sales_returns'] as $table) $this->immutableDocument($table);
        foreach (['sales_invoice_lines'=>['sales_invoices','sales_invoice_id'],'payment_allocations'=>['customer_payments','customer_payment_id'],'sales_return_lines'=>['sales_returns','sales_return_id']] as $table=>[$parent,$fk]) $this->immutableLines($table,$parent,$fk);
        foreach (['UPDATE','DELETE'] as $verb) { $suffix=strtolower($verb); DB::unprepared("CREATE TRIGGER customer_ledger_immutable_$suffix BEFORE $verb ON customer_ledger_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Customer ledger is immutable'"); }
    }
    private function header(Blueprint $t): void
    {
        $t->id(); $t->string('document_no',80)->nullable()->unique(); $t->date('document_date'); $t->string('status',20)->default('draft'); $t->unsignedInteger('version')->default(1);
        foreach (['currency','base_currency'] as $f) { $t->char($f,3); $t->foreign($f)->references('code')->on('currencies')->restrictOnDelete(); }
        $t->decimal('exchange_rate',18,8); $t->date('exchange_rate_date'); $t->string('exchange_rate_source',160); $t->text('notes')->nullable();
        $t->foreignId('posted_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete(); $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
        $t->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete(); $t->timestamp('posted_at')->nullable(); $t->timestamps(); $t->index(['status','document_date']);
    }
    private function immutableDocument(string $table): void
    {
        DB::unprepared("CREATE TRIGGER {$table}_draft_insert BEFORE INSERT ON $table FOR EACH ROW BEGIN IF NEW.status='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Create draft first'; END IF; END");
        foreach (['UPDATE','DELETE'] as $verb) { $suffix=strtolower($verb); DB::unprepared("CREATE TRIGGER {$table}_immutable_$suffix BEFORE $verb ON $table FOR EACH ROW BEGIN IF OLD.status='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted document is immutable'; END IF; END"); }
    }
    private function immutableLines(string $table,string $parent,string $fk): void
    {
        foreach (['INSERT','UPDATE','DELETE'] as $verb) {
            $suffix=strtolower($verb); $ref=$verb==='DELETE'?'OLD':'NEW'; $cond="EXISTS(SELECT 1 FROM $parent WHERE id=$ref.$fk AND status='posted')";
            if ($verb==='UPDATE') $cond.=" OR EXISTS(SELECT 1 FROM $parent WHERE id=OLD.$fk AND status='posted')";
            DB::unprepared("CREATE TRIGGER {$table}_immutable_$suffix BEFORE $verb ON $table FOR EACH ROW BEGIN IF $cond THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted document lines are immutable'; END IF; END");
        }
    }
    public function down(): void
    {
        Schema::table('serial_numbers',function(Blueprint $t){ $t->dropConstrainedForeignId('sold_sales_line_id'); $t->dropConstrainedForeignId('customer_id'); });
        foreach (['customer_ledger_entries','sales_return_lines','sales_returns','payment_allocations','customer_payments','sales_invoice_lines','sales_invoices','customer_followups','customers','workflow_approvals','business_policies'] as $table) Schema::dropIfExists($table);
    }
};
