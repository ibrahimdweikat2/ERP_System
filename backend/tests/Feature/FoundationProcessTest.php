<?php

namespace Tests\Feature;

use App\Domains\Accounting\Actions\CreateFiscalYear;
use App\Domains\Accounting\Actions\SaveManualJournal;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Journal;
use App\Domains\Approvals\Actions\DecideInventoryApproval;
use App\Domains\Catalog\Actions\SaveProduct;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Identity\Models\Role;
use App\Domains\Inventory\Actions\PostStockDocument;
use App\Domains\Inventory\Actions\SaveStockDocument;
use App\Domains\Inventory\Actions\SubmitStockDocument;
use App\Domains\Inventory\Enums\StockDocumentType;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\Purchasing\Actions\PostGoodsReceipt;
use App\Domains\Purchasing\Actions\PurchaseOrderWorkflow;
use App\Domains\Purchasing\Actions\SaveGoodsReceipt;
use App\Domains\Purchasing\Actions\SavePurchaseOrder;
use App\Domains\Purchasing\Actions\SaveSupplier;
use App\Domains\Purchasing\Actions\SaveSupplierInvoice;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Domains\StoreSetup\Models\DocumentSequence;
use App\Domains\Tax\Models\TaxCode;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FailingJob;
use Tests\TestCase;

class FoundationProcessTest extends TestCase
{
    // Real processes need committed fixtures, so transaction-based isolation is
    // unsuitable. Keep MySQL triggers/schema and clear rows between scenarios.
    use DatabaseTruncation;

    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDownAfterClass();
    }

    public static function stockTrackingModes(): array
    {
        return [[true], [false]];
    }

    public function test_numbering_sees_a_counter_created_after_the_transaction_snapshot(): void
    {
        $this->seed();
        $sequence = DocumentSequence::where('document_type', 'purchase_order')->firstOrFail();
        config(['database.connections.counter_writer' => config('database.connections.mysql')]);
        DB::beginTransaction();
        try {
            $this->assertSame(0, DB::table('document_sequence_counters')->count());
            DB::connection('counter_writer')->table('document_sequence_counters')->insert([
                'document_sequence_id' => $sequence->id, 'period_key' => '2026', 'next_number' => 2,
            ]);
            $this->assertSame('PO/2026/000002', app(NextDocumentNumber::class)->execute('purchase_order', '2026-09-01'));
            $this->assertSame('PO/2026/000003', app(NextDocumentNumber::class)->execute('purchase_order', '2026-09-01'));
            DB::commit();
            $this->assertDatabaseHas('document_sequence_counters', ['document_sequence_id' => $sequence->id, 'period_key' => '2026', 'next_number' => 4]);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge('counter_writer');
        }
    }

    public function test_concurrent_invoices_in_different_periods_cannot_bill_the_same_receipt_twice(): void
    {
        $this->seed();
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'owner')->firstOrFail());
        app(CreateFiscalYear::class)->execute(['name' => 'Invoice race', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $supplier = app(SaveSupplier::class)->execute(['code' => 'PI-RACE-SUP', 'legal_name' => 'مورد تزامن فواتير', 'currency' => 'ILS', 'contacts' => [], 'payment_terms_days' => 0, 'credit_limit' => '0', 'active' => true], $user->id);
        $product = app(SaveProduct::class)->execute(['sku' => 'PI-RACE', 'name_ar' => 'منتج فواتير متزامنة', 'unit_id' => Unit::firstOrFail()->id, 'serial_tracked' => false, 'active' => true, 'cash_price' => '20', 'installment_price' => '30', 'minimum_price' => '10', 'reorder_level' => '1', 'barcodes' => []], $user->id);
        $tax = TaxCode::create(['code' => 'TEST-ZERO', 'name_ar' => 'صفر للاختبار', 'category' => 'zero', 'rate' => '0', 'effective_from' => '2026-01-01', 'input_account_id' => Account::where('code', '1400')->value('id'), 'output_account_id' => Account::where('code', '2200')->value('id')]);
        $receipt = app(SaveGoodsReceipt::class)->execute(['supplier_id' => $supplier->id, 'document_date' => '2026-08-01', 'currency' => 'ILS', 'delivery_reference' => 'PI-RACE-DELIVERY', 'lines' => [['product_id' => $product->id, 'location_id' => StockLocation::where('code', 'WAREHOUSE')->value('id'), 'condition' => 'new', 'quantity' => '1', 'unit_cost' => '10', 'serials' => []]]], $user->id);
        $receipt = app(PostGoodsReceipt::class)->execute($receipt->id, 1, $user->id);
        $processes = [];
        foreach (['2026-08-01', '2026-09-01'] as $i => $date) {
            $invoice = app(SaveSupplierInvoice::class)->execute(['supplier_id' => $supplier->id, 'supplier_invoice_no' => 'RACE-'.$i, 'invoice_date' => '2026-08-01', 'posting_date' => $date, 'due_date' => '2026-09-30', 'currency' => 'ILS', 'lines' => [['goods_receipt_line_id' => $receipt->lines[0]->id, 'quantity' => '1', 'unit_price' => '10', 'discount_amount' => '0', 'tax_code_id' => $tax->id, 'tax_inclusive' => false, 'tax_recoverable' => false]]], $user->id);
            $processes[] = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/supplier-invoice-worker.php'), (string) $invoice->id, (string) $user->id], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.mysql.database'), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'), 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => config('database.connections.mysql.port')]);
        }
        foreach ($processes as $process) {
            $process->start();
        }
        $outputs = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $outputs[] = trim($process->getOutput());
        }
        sort($outputs);
        $this->assertSame(['POSTED:PI/2026/000001', 'REJECTED:RECEIPT_OVER_INVOICED'], $outputs);
        $this->assertDatabaseCount('supplier_ledger_entries', 1);
        $this->assertDatabaseCount('tax_transactions', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_concurrent_receipts_in_different_periods_cannot_exceed_a_po_quantity(): void
    {
        $this->seed();
        $user = User::factory()->create();
        app(CreateFiscalYear::class)->execute(['name' => 'GR concurrency', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $supplier = app(SaveSupplier::class)->execute(['code' => 'GR-RACE-SUP', 'legal_name' => 'مورد استلام متزامن', 'currency' => 'ILS', 'contacts' => [], 'payment_terms_days' => 0, 'credit_limit' => '0', 'active' => true], $user->id);
        $product = app(SaveProduct::class)->execute(['sku' => 'GR-RACE', 'name_ar' => 'منتج استلام متزامن', 'unit_id' => Unit::firstOrFail()->id, 'serial_tracked' => true, 'active' => true, 'cash_price' => '20', 'installment_price' => '30', 'minimum_price' => '10', 'reorder_level' => '1', 'barcodes' => []], $user->id);
        DB::table('approval_policies')->where('key', 'purchase_order')->update(['threshold' => '1000']);
        $order = app(SavePurchaseOrder::class)->execute(['supplier_id' => $supplier->id, 'document_date' => '2026-08-01', 'currency' => 'ILS', 'lines' => [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '10', 'discount_amount' => '0', 'tax_code_id' => null, 'tax_inclusive' => false]]], $user->id);
        app(PurchaseOrderWorkflow::class)->submit($order->id, 1, $user->id);
        app(PurchaseOrderWorkflow::class)->issue($order->id, 1, $user->id);
        $processes = [];
        foreach (['2026-08-01', '2026-09-01'] as $i => $date) {
            $doc = app(SaveGoodsReceipt::class)->execute(['purchase_order_id' => $order->id, 'supplier_id' => $supplier->id, 'document_date' => $date, 'currency' => 'ILS', 'delivery_reference' => 'RACE-'.$i, 'lines' => [['product_id' => $product->id, 'purchase_order_line_id' => $order->lines[0]->id, 'location_id' => StockLocation::where('code', 'WAREHOUSE')->value('id'), 'condition' => 'new', 'quantity' => '1', 'serials' => ['RACE-SERIAL-'.$i]]]], $user->id);
            $processes[] = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/goods-receipt-worker.php'), (string) $doc->id, (string) $user->id], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.mysql.database'), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'), 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => config('database.connections.mysql.port')]);
        }
        foreach ($processes as $process) {
            $process->start();
        }
        $outputs = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $outputs[] = trim($process->getOutput());
        }
        sort($outputs);
        $this->assertSame(['POSTED:GR/2026/000001', 'REJECTED:PURCHASE_ORDER_OVER_RECEIPT'], $outputs);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseCount('serial_numbers', 1);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_simultaneous_distinct_purchase_orders_get_distinct_numbers(): void
    {
        $this->seed();
        $user = User::factory()->create();
        $supplier = app(SaveSupplier::class)->execute(['code' => 'RACE-SUP', 'legal_name' => 'مورد تزامن', 'currency' => 'ILS', 'contacts' => [], 'payment_terms_days' => 0, 'credit_limit' => '0', 'active' => true], $user->id);
        $product = app(SaveProduct::class)->execute(['sku' => 'PO-RACE', 'name_ar' => 'منتج تزامن', 'unit_id' => Unit::firstOrFail()->id, 'serial_tracked' => true, 'active' => true, 'cash_price' => '20', 'installment_price' => '30', 'minimum_price' => '10', 'reorder_level' => '1', 'barcodes' => []], $user->id);
        DB::table('approval_policies')->where('key', 'purchase_order')->update(['threshold' => '1000']);
        $processes = [];
        for ($i = 0; $i < 4; $i++) {
            $doc = app(SavePurchaseOrder::class)->execute(['supplier_id' => $supplier->id, 'document_date' => '2026-09-01', 'currency' => 'ILS', 'lines' => [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '10', 'discount_amount' => '0', 'tax_code_id' => null, 'tax_inclusive' => false]]], $user->id);
            app(PurchaseOrderWorkflow::class)->submit($doc->id, 1, $user->id);
            $processes[] = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/purchase-order-worker.php'), (string) $doc->id, (string) $user->id], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.mysql.database'), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'), 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => config('database.connections.mysql.port')]);
        }
        foreach ($processes as $process) {
            $process->start();
        }
        $numbers = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $numbers[] = trim($process->getOutput());
        }
        sort($numbers);
        $this->assertSame(['PO/2026/000001', 'PO/2026/000002', 'PO/2026/000003', 'PO/2026/000004'], $numbers);
        $this->assertSame(4, DB::table('audit_logs')->where('action', 'purchasing.order_issued')->count());
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    #[DataProvider('stockTrackingModes')]
    public function test_concurrent_transfers_cannot_issue_the_same_stock_twice(bool $serialized): void
    {
        $this->seed();
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'owner')->firstOrFail());
        app(CreateFiscalYear::class)->execute(['name' => 'stock concurrency', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $product = app(SaveProduct::class)->execute(['sku' => 'CONCURRENT', 'name_ar' => 'جهاز اختبار متزامن', 'unit_id' => Unit::firstOrFail()->id, 'serial_tracked' => $serialized, 'active' => true, 'standard_cost' => '10', 'cash_price' => '20', 'installment_price' => '30', 'minimum_price' => '15', 'reorder_level' => '1', 'barcodes' => []], $user->id);
        $warehouse = StockLocation::where('code', 'WAREHOUSE')->value('id');
        $showroom = StockLocation::where('code', 'SHOWROOM')->value('id');
        $adjustment = StockDocumentType::Adjustment;
        $transfer = StockDocumentType::Transfer;
        $save = app(SaveStockDocument::class);
        $data = ['document_date' => '2026-09-01', 'reason' => 'اختبار منع ازدواج صرف الجهاز', 'location_id' => $warehouse, 'adjustment_kind' => 'opening', 'lines' => [['product_id' => $product->id, 'quantity' => '1', 'unit_cost' => '10.1234', 'serials' => $serialized ? ['CONCUR-1'] : []]]];
        $opening = $save->execute($adjustment, $data, $user->id);
        $opening = app(SubmitStockDocument::class)->execute($adjustment, $opening->id, 1, $user->id);
        app(DecideInventoryApproval::class)->execute($opening->approval_id, 'approved', 'موافقة اختبار التزامن', $user->id);
        app(PostStockDocument::class)->execute($adjustment, $opening->id, 1, $user->id);
        unset($data['adjustment_kind'],$data['lines'][0]['unit_cost']);
        $data['destination_id'] = $showroom;
        $docs = [$save->execute($transfer, $data, $user->id), $save->execute($transfer, $data, $user->id)];
        $processes = [];
        foreach ($docs as $doc) {
            $p = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/stock-post-worker.php'), $transfer->value, (string) $doc->id, '1', (string) $user->id], base_path(), [
                'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.mysql.database'), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'), 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => config('database.connections.mysql.port'),
            ]);
            $p->start();
            $processes[] = $p;
        }
        $outputs = [];
        foreach ($processes as $p) {
            $p->wait();
            $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
            $outputs[] = trim($p->getOutput());
        }
        sort($outputs);
        $this->assertSame(['POSTED:ST/2026/000001', 'REJECTED:INSUFFICIENT_STOCK'], $outputs);
        $this->assertDatabaseCount('inventory_movements', 3);
        $this->assertDatabaseCount('serial_movements', $serialized ? 2 : 0);
        $this->assertDatabaseCount('journal_entries', 1);
        if ($serialized) {
            $this->assertDatabaseHas('serial_numbers', ['serial_no' => 'CONCUR-1', 'current_location_id' => $showroom]);
        }
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'stock_transfer.posted')->count());
        $this->assertSame('10.1234', DB::table('inventory_balances')->sum('inventory_value'));
    }

    public function test_four_simultaneous_postings_create_one_ledger_entry(): void
    {
        $this->seed();
        $user = User::factory()->create();
        app(CreateFiscalYear::class)->execute(['name' => 'concurrency', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $entry = app(SaveManualJournal::class)->execute([
            'journal_id' => Journal::where('code', 'general')->value('id'),
            'entry_date' => '2026-09-06', 'description' => 'Concurrent posting test', 'currency' => 'ILS',
            'lines' => [
                ['account_id' => Account::where('code', '1100')->value('id'), 'debit' => '100.0001', 'credit' => '0'],
                ['account_id' => Account::where('code', '3100')->value('id'), 'debit' => '0', 'credit' => '100.0001'],
            ],
        ], $user->id);
        $processes = [];
        for ($i = 0; $i < 4; $i++) {
            $p = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/post-worker.php'), (string) $entry->id, (string) $user->id], base_path(), [
                'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.mysql.database'),
                'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'),
                'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => config('database.connections.mysql.port'),
            ]);
            $p->start();
            $processes[] = $p;
        }
        foreach ($processes as $p) {
            $p->wait();
            $this->assertTrue($p->isSuccessful(), $p->getErrorOutput());
            $this->assertSame('JE/2026/000001', trim($p->getOutput()));
        }
        $this->assertSame(1, DB::table('journal_entries')->where('status', 'posted')->count());
        $this->assertSame(2, DB::table('journal_lines')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'accounting.journal_posted')->count());
    }

    public function test_concurrent_sequences_and_real_database_worker_failure_handling(): void
    {
        $this->seed();
        $processes = [];
        for ($i = 0; $i < 4; $i++) {
            $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/sequence-worker.php')], base_path(), [
                'APP_ENV' => 'testing', 'DB_DATABASE' => config('database.connections.mysql.database'),
                'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'),
                'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => config('database.connections.mysql.port'),
            ]);
            $process->start();
            $processes[] = $process;
        }
        $numbers = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $numbers = array_merge($numbers, explode(PHP_EOL, trim($process->getOutput())));
        }
        $this->assertCount(40, $numbers);
        $this->assertCount(40, array_unique($numbers));
        Artisan::call('erp:queue-probe', ['--queue' => 'maintenance']);
        $this->assertSame(1, DB::table('jobs')->count());
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'maintenance', '--once' => true, '--tries' => 1]);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('queue_probe_runs')->where('completion_count', 1)->count());
        Queue::connection('database')->push(new FailingJob, '', 'default');
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--tries' => 1]);
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertStringContainsString('Intentional foundation', DB::table('failed_jobs')->value('exception'));
    }
}
