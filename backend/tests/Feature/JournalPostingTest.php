<?php

namespace Tests\Feature;

use App\Domains\Accounting\Actions\ChangePeriodState;
use App\Domains\Accounting\Actions\CreateFiscalYear;
use App\Domains\Accounting\Actions\PostJournal;
use App\Domains\Accounting\Actions\ReverseJournal;
use App\Domains\Accounting\Actions\SaveManualJournal;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Journal;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Identity\Models\Role;
use App\Domains\StoreSetup\Models\ExchangeRate;
use App\Models\User;
use App\Support\BusinessException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JournalPostingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = User::factory()->create();
        $this->owner->roles()->attach(Role::where('name', 'owner')->firstOrFail());
        app(CreateFiscalYear::class)->execute(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
    }

    private function data(string $amount = '1000.0001', string $currency = 'ILS'): array
    {
        return ['journal_id' => Journal::where('code', 'general')->value('id'), 'entry_date' => '2026-09-06', 'description' => 'قيد اختبار رأس المال', 'currency' => $currency, 'lines' => [
            ['account_id' => Account::where('code', '1100')->value('id'), 'debit' => $amount, 'credit' => '0'],
            ['account_id' => Account::where('code', '3100')->value('id'), 'debit' => '0', 'credit' => $amount],
        ]];
    }

    private function draft(?array $data = null): JournalEntry
    {
        return app(SaveManualJournal::class)->execute($data ?? $this->data(), $this->owner->id);
    }

    public function test_exact_decimal_post_reversal_and_history(): void
    {
        $entry = app(PostJournal::class)->execute($this->draft()->id, $this->owner->id);
        $this->assertSame('posted', $entry->status);
        $this->assertSame('1000.0001', $entry->lines->first()->debit);
        $this->assertSame($entry->entry_no, app(PostJournal::class)->execute($entry->id, $this->owner->id)->entry_no);
        $reverse = app(ReverseJournal::class)->execute($entry->id, '2026-10-01', 'تصحيح قيد الاختبار', $this->owner->id);
        $this->assertSame($entry->id, $reverse->reversal_of_id);
        $this->assertSame('posted', $entry->fresh()->status);
        $this->assertSame('0.0000', (string) DB::table('journal_lines')->where('account_id', Account::where('code', '1100')->value('id'))->selectRaw('SUM(debit-credit) AS balance')->value('balance'));
        $this->assertSame(2, JournalEntry::where('status', 'posted')->count());
    }

    public function test_unbalanced_journal_does_not_post_or_consume_number(): void
    {
        $data = $this->data();
        $data['lines'][1]['credit'] = '999.9999';
        $entry = $this->draft($data);
        try {
            app(PostJournal::class)->execute($entry->id, $this->owner->id);
            $this->fail('Unbalanced journal posted');
        } catch (BusinessException $e) {
            $this->assertSame('JOURNAL_UNBALANCED', $e->errorCode);
        }
        $this->assertSame('draft', $entry->fresh()->status);
        $this->assertSame(0, DB::table('document_sequence_counters')->count());
    }

    public function test_locked_period_rejects_posting_and_later_open_period_accepts_reversal(): void
    {
        $entry = app(PostJournal::class)->execute($this->draft()->id, $this->owner->id);
        app(ChangePeriodState::class)->execute($entry->fiscal_period_id, 'locked', 'إقفال شهر أيلول', $this->owner->id);
        try {
            app(PostJournal::class)->execute($this->draft()->id, $this->owner->id);
            $this->fail('Locked posting accepted');
        } catch (BusinessException $e) {
            $this->assertSame('PERIOD_NOT_OPEN', $e->errorCode);
        }
        try {
            app(ReverseJournal::class)->execute($entry->id, '2026-09-07', 'عكس في شهر مقفل', $this->owner->id);
            $this->fail('Locked reversal accepted');
        } catch (BusinessException $e) {
            $this->assertSame('PERIOD_NOT_OPEN', $e->errorCode);
        }
        $this->assertSame(0, JournalEntry::where('reversal_of_id', $entry->id)->count());
        $reverse = app(ReverseJournal::class)->execute($entry->id, '2026-10-01', 'عكس في فترة لاحقة', $this->owner->id);
        $this->assertSame('posted', $reverse->status);
    }

    public function test_control_accounts_cannot_receive_manual_journals(): void
    {
        $data = $this->data();
        $data['lines'][0]['account_id'] = Account::where('code', '1200')->value('id');
        $this->expectException(BusinessException::class);
        $this->draft($data);
    }

    public function test_posted_line_update_and_entry_delete_are_rejected_by_mysql(): void
    {
        $entry = app(PostJournal::class)->execute($this->draft()->id, $this->owner->id);
        foreach ([
            fn () => DB::table('journal_lines')->where('id', $entry->lines->first()->id)->update(['debit' => '1']),
            fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['description' => 'tampered']),
            fn () => DB::table('journal_entries')->where('id', $entry->id)->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Ledger mutation accepted');
            } catch (QueryException $e) {
                $this->assertStringContainsString('45000', $e->getMessage());
            }
        }
    }

    public function test_foreign_currency_rate_is_frozen_in_original_and_reversal(): void
    {
        ExchangeRate::create(['currency_code' => 'USD', 'rate_date' => '2026-09-01', 'rate_to_base' => '3.12345678', 'source' => 'test', 'created_at' => now()]);
        $entry = app(PostJournal::class)->execute($this->draft($this->data('100.0000', 'USD'))->id, $this->owner->id);
        $this->assertSame('312.3457', $entry->lines->first()->debit);
        ExchangeRate::create(['currency_code' => 'USD', 'rate_date' => '2026-10-01', 'rate_to_base' => '4.00000000', 'source' => 'test', 'created_at' => now()]);
        $reverse = app(ReverseJournal::class)->execute($entry->id, '2026-10-01', 'إلغاء القيد بسعره الأصلي', $this->owner->id);
        $this->assertSame('312.3457', $reverse->lines->first()->credit);
        $this->assertSame('3.12345678', $reverse->exchange_rate);
    }

    public function test_create_post_retry_payload_conflict_and_delete_audit_through_api(): void
    {
        $this->actingAs($this->owner);
        $response = $this->withHeader('Idempotency-Key', 'journal-create-001')->postJson('/api/v1/journal-entries', $this->data())->assertCreated();
        $id = $response->json('data.id');
        $this->withHeader('Idempotency-Key', 'journal-create-001')->postJson('/api/v1/journal-entries', $this->data())->assertCreated()->assertJsonPath('data.id', $id);
        $data = $this->data('2000');
        $this->withHeader('Idempotency-Key', 'journal-create-001')->postJson('/api/v1/journal-entries', $data)->assertConflict();
        $this->withHeader('Idempotency-Key', 'journal-post-001')->postJson('/api/v1/journal-entries/'.$id.'/post')->assertOk()->assertJsonPath('data.status', 'posted');
        $this->withHeader('Idempotency-Key', 'journal-post-001')->postJson('/api/v1/journal-entries/'.$id.'/post')->assertOk();
        $this->assertSame(2, DB::table('journal_lines')->count());
        $this->deleteJson('/api/v1/journal-entries/'.$id)->assertUnprocessable()->assertJsonPath('code', 'JOURNAL_DELETE_FORBIDDEN');
        $this->assertDatabaseHas('audit_logs', ['action' => 'accounting.deletion_rejected', 'entity_id' => (string) $id]);
    }

    public function test_api_rejects_float_money_and_cashier_posting(): void
    {
        $data = $this->data();
        $data['lines'][0]['debit'] = 0.1;
        $this->actingAs($this->owner)->withHeader('Idempotency-Key', 'journal-create-002')->postJson('/api/v1/journal-entries', $data)->assertUnprocessable();
        $cashier = User::factory()->create();
        $cashier->roles()->attach(Role::where('name', 'cashier')->firstOrFail());
        $this->actingAs($cashier)->postJson('/api/v1/journal-entries', [])->assertForbidden();
    }

    public function test_locked_period_cannot_be_reopened_but_soft_close_can(): void
    {
        $id = DB::table('accounting_periods')->where('period_no', 9)->value('id');
        $action = app(ChangePeriodState::class);
        $action->execute($id, 'soft_closed', 'مراجعة القيود قبل الإغلاق', $this->owner->id);
        $action->execute($id,'open','إعادة فتح للمراجعة',$this->owner->id);
        $action->execute($id,'locked','إقفال نهائي للفترة',$this->owner->id);
        $this->expectException(BusinessException::class);
        $action->execute($id,'open','محاولة فتح فترة مقفلة',$this->owner->id);
    }
}
