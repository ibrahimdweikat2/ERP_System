<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $version = DB::selectOne('SELECT VERSION() AS version')->version;
        if (str_contains($version, 'MariaDB') || version_compare($version, '8.0.0', '<')) {
            throw new RuntimeException('Inventory requires MySQL 8+. Run on the configured MySQL 8 database.');
        }
        Schema::create('approval_policies', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->decimal('threshold', 18, 4)->default(0);
            $t->boolean('segregate_requester')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        DB::table('approval_policies')->insert(['key' => 'inventory_adjustment', 'threshold' => '0', 'segregate_requester' => false, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('approvals', function (Blueprint $t) {
            $t->id();
            $t->string('source_type', 60);
            $t->unsignedBigInteger('source_id');
            $t->unsignedInteger('source_version');
            $t->char('payload_hash', 64);
            $t->json('payload_json');
            $t->decimal('amount', 18, 4);
            $t->char('currency', 3);
            $t->foreign('currency')->references('code')->on('currencies')->restrictOnDelete();
            $t->string('policy_key', 80);
            $t->foreign('policy_key')->references('key')->on('approval_policies')->restrictOnDelete();
            $t->unsignedInteger('policy_version');
            $t->boolean('segregate_requester');
            $t->string('status', 20)->default('pending');
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('decision_reason')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->unique(['source_type', 'source_id', 'source_version'], 'approval_source_version_unique');
            $t->index(['status', 'created_at']);
        });
        Schema::create('approval_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('approval_id')->constrained()->restrictOnDelete();
            $t->string('decision', 30);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('reason', 1000);
            $t->timestamp('occurred_at');
        });
        Schema::create('inventory_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $t->decimal('qty_on_hand', 18, 4)->default(0);
            $t->decimal('qty_reserved', 18, 4)->default(0);
            $t->decimal('qty_available', 18, 4)->storedAs('qty_on_hand - qty_reserved');
            $t->decimal('inventory_value', 18, 4)->default(0);
            $t->decimal('average_cost', 18, 8)->default(0);
            $t->unsignedBigInteger('last_movement_id')->nullable();
            $t->timestamp('updated_at');
            $t->unique(['product_id', 'location_id']);
        });
        DB::statement('ALTER TABLE inventory_balances ADD CONSTRAINT stock_nonnegative CHECK (qty_on_hand>=0 AND qty_reserved>=0 AND qty_reserved<=qty_on_hand AND inventory_value>=0 AND (qty_on_hand>0 OR inventory_value=0))');
        Schema::create('inventory_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $t->string('direction', 3);
            $t->decimal('quantity', 18, 4);
            $t->decimal('unit_cost', 18, 8);
            $t->decimal('total_cost', 18, 4);
            $t->string('movement_type', 60);
            $t->string('source_type', 60);
            $t->unsignedBigInteger('source_id');
            $t->unsignedBigInteger('source_line_id');
            $t->string('source_event', 40);
            $t->date('movement_date');
            $t->timestamp('occurred_at');
            $t->timestamp('posted_at');
            $t->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $t->index(['product_id', 'location_id', 'occurred_at'], 'inventory_product_location_time');
            $t->index(['movement_date', 'id']);
            $t->unique(['source_type', 'source_id', 'source_line_id', 'source_event'], 'inventory_source_event_unique');
        });
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT movement_positive CHECK (quantity>0 AND unit_cost>=0 AND total_cost>=0 AND direction IN ('in','out'))");
        Schema::table('inventory_balances', fn (Blueprint $t) => $t->foreign('last_movement_id')->references('id')->on('inventory_movements')->restrictOnDelete());
        Schema::create('serial_numbers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->string('serial_no', 120)->unique();
            $t->string('status', 40);
            $t->foreignId('current_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
            $t->decimal('acquisition_cost', 18, 4);
            $t->foreignId('receipt_movement_id')->constrained('inventory_movements')->restrictOnDelete();
            $t->date('warranty_start')->nullable();
            $t->date('warranty_end')->nullable();
            $t->timestamps();
            $t->index(['product_id', 'status', 'current_location_id'], 'serial_availability_index');
        });
        Schema::create('serial_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('serial_number_id')->constrained()->restrictOnDelete();
            $t->foreignId('inventory_movement_id')->constrained()->restrictOnDelete();
            $t->string('from_status', 40)->nullable();
            $t->string('to_status', 40);
            $t->foreignId('from_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
            $t->foreignId('to_location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('occurred_at');
            $t->unique(['serial_number_id', 'inventory_movement_id'], 'serial_movement_unique');
        });
        foreach (['stock_transfers', 'stock_adjustments', 'stock_counts'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->id();
                $t->string('document_no', 80)->nullable()->unique();
                $t->date('document_date');
                $t->string('reason', 1000);
                $t->string('status', 20)->default('draft');
                $t->unsignedInteger('version')->default(1);
                $t->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
                if ($table === 'stock_transfers') {
                    $t->foreignId('destination_id')->constrained('stock_locations')->restrictOnDelete();
                }
                if ($table === 'stock_adjustments') {
                    $t->string('adjustment_kind', 20);
                }
                if ($table === 'stock_counts') {
                    $t->timestamp('snapshot_at');
                }
                $t->foreignId('approval_id')->nullable()->constrained()->restrictOnDelete();
                $t->char('approved_payload_hash', 64)->nullable();
                $t->decimal('gross_value', 18, 4)->default(0);
                $t->foreignId('posted_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
                $t->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
                $t->timestamp('posted_at')->nullable();
                $t->timestamps();
                $t->index(['status', 'document_date']);
            });
            $lineTable = match ($table) {
                'stock_transfers' => 'stock_transfer_lines','stock_adjustments' => 'stock_adjustment_lines','stock_counts' => 'stock_count_lines'
            };
            $foreign = match ($table) {
                'stock_transfers' => 'stock_transfer_id','stock_adjustments' => 'stock_adjustment_id','stock_counts' => 'stock_count_id'
            };
            Schema::create($lineTable, function (Blueprint $t) use ($table, $foreign) {
                $t->id();
                $t->foreignId($foreign)->constrained()->restrictOnDelete();
                $t->foreignId('product_id')->constrained()->restrictOnDelete();
                if ($table === 'stock_counts') {
                    $t->decimal('expected_quantity', 18, 4);
                    $t->decimal('counted_quantity', 18, 4)->nullable();
                    $t->json('expected_serials');
                    $t->json('counted_serials');
                    $t->unsignedBigInteger('snapshot_movement_id')->nullable();
                } else {
                    $t->decimal('quantity', 18, 4);
                    $t->json('serials');
                }
                $t->decimal('unit_cost', 18, 4)->nullable();
                $t->decimal('posted_value', 18, 4)->nullable();
                $t->unique([$foreign, 'product_id']);
            });
            DB::unprepared("CREATE TRIGGER {$table}_immutable_update BEFORE UPDATE ON {$table} FOR EACH ROW BEGIN IF OLD.status='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted stock document is immutable'; END IF; END");
            DB::unprepared("CREATE TRIGGER {$table}_immutable_delete BEFORE DELETE ON {$table} FOR EACH ROW BEGIN IF OLD.status='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted stock document cannot be deleted'; END IF; END");
            DB::unprepared("CREATE TRIGGER {$table}_draft_insert BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NEW.status='posted' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Create stock draft before posting'; END IF; END");
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $operation) {
                $condition = match ($operation) {
                    'INSERT' => "(SELECT status FROM {$table} WHERE id=NEW.{$foreign})='posted'",'DELETE' => "(SELECT status FROM {$table} WHERE id=OLD.{$foreign})='posted'",'UPDATE' => "(SELECT status FROM {$table} WHERE id=OLD.{$foreign})='posted' OR (SELECT status FROM {$table} WHERE id=NEW.{$foreign})='posted'"
                };
                DB::unprepared("CREATE TRIGGER {$lineTable}_".strtolower($operation)." BEFORE {$operation} ON {$lineTable} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Posted stock lines are immutable'; END IF; END");
            }
        }
        foreach (['inventory_movements', 'serial_movements', 'approval_steps'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                DB::unprepared("CREATE TRIGGER {$table}_no_".strtolower($operation)." BEFORE {$operation} ON {$table} FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ledger history is immutable'");
            }
        }
    }

    public function down(): void
    {
        foreach (['stock_count_lines', 'stock_counts', 'stock_adjustment_lines', 'stock_adjustments', 'stock_transfer_lines', 'stock_transfers', 'serial_movements', 'serial_numbers', 'inventory_balances', 'inventory_movements', 'approval_steps', 'approvals', 'approval_policies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
