<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->string('entry_no', 80)->nullable()->unique();
            $t->foreignId('journal_id')->constrained()->restrictOnDelete();
            $t->date('entry_date');
            $t->foreignId('fiscal_period_id')->nullable()->constrained('accounting_periods')->restrictOnDelete();
            $t->string('reference_type', 60)->default('manual');
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('reference_event', 60)->default('post');
            $t->string('description', 1000);
            $t->string('status', 20)->default('draft');
            $t->char('currency', 3);
            $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $t->decimal('exchange_rate', 18, 8)->default('1');
            $t->date('exchange_rate_date')->nullable();
            $t->string('exchange_rate_source', 160)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->foreignId('reversal_of_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $t->string('reversal_reason', 1000)->nullable();
            $t->timestamps();
            $t->index(['entry_date', 'status']);
            $t->unique(['reference_type', 'reference_id', 'reference_event'], 'journal_source_event_unique');
        });
        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $t->foreignId('account_id')->constrained()->restrictOnDelete();
            $t->decimal('debit', 18, 4)->default(0);
            $t->decimal('credit', 18, 4)->default(0);
            $t->char('currency', 3);
            $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $t->decimal('foreign_amount', 18, 4);
            $t->decimal('exchange_rate', 18, 8);
            $t->string('memo', 500)->nullable();
            $t->index(['account_id', 'journal_entry_id']);
        });
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT single_sided_positive_line CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');
        DB::unprepared("CREATE TRIGGER journal_entries_guard_update BEFORE UPDATE ON journal_entries FOR EACH ROW BEGIN IF OLD.status = 'posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal is immutable'; END IF; IF NEW.status = 'posted' THEN IF NOT EXISTS (SELECT 1 FROM accounting_periods WHERE id=NEW.fiscal_period_id AND status='open' AND NEW.entry_date BETWEEN starts_on AND ends_on) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posting period must be open'; END IF; IF (SELECT COUNT(*) FROM journal_lines WHERE journal_entry_id=OLD.id)<2 OR (SELECT COALESCE(SUM(debit-credit),0) FROM journal_lines WHERE journal_entry_id=OLD.id)<>0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal must balance'; END IF; END IF; END");
        DB::unprepared("CREATE TRIGGER journal_entries_guard_insert BEFORE INSERT ON journal_entries FOR EACH ROW BEGIN IF NEW.status = 'posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Create a draft before posting'; END IF; END");
        DB::unprepared("CREATE TRIGGER journal_entries_guard_delete BEFORE DELETE ON journal_entries FOR EACH ROW BEGIN IF OLD.status='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal cannot be deleted'; END IF; END");
        DB::unprepared("CREATE TRIGGER journal_lines_guard_insert BEFORE INSERT ON journal_lines FOR EACH ROW BEGIN IF (SELECT status FROM journal_entries WHERE id=NEW.journal_entry_id)='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal lines are immutable'; END IF; END");
        DB::unprepared("CREATE TRIGGER journal_lines_guard_update BEFORE UPDATE ON journal_lines FOR EACH ROW BEGIN IF (SELECT status FROM journal_entries WHERE id=OLD.journal_entry_id)='posted' OR (SELECT status FROM journal_entries WHERE id=NEW.journal_entry_id)='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal lines are immutable'; END IF; END");
        DB::unprepared("CREATE TRIGGER journal_lines_guard_delete BEFORE DELETE ON journal_lines FOR EACH ROW BEGIN IF (SELECT status FROM journal_entries WHERE id=OLD.journal_entry_id)='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted journal lines cannot be deleted'; END IF; END");
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('scope', 120);
            $t->string('request_key', 100);
            $t->char('payload_hash', 64);
            $t->json('result_json')->nullable();
            $t->timestamp('created_at');
            $t->unique(['user_id', 'scope', 'request_key'], 'idempotency_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
    }
};
