<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Inventory\Actions\StockLedger;
use App\Domains\Inventory\Models\InventoryBalance;
use App\Domains\Inventory\Models\InventoryMovement;
use App\Domains\Inventory\Models\SerialNumber;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Http\Controllers\Controller;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function settings(): JsonResponse
    {
        return response()->json(['data' => ['base_currency' => StoreSetting::current()->base_currency, 'valuation_policy' => 'moving_average_per_location']]);
    }

    private function filters(Request $r): array
    {
        return $r->validate(['search' => ['nullable', 'string', 'max:100'], 'product_id' => ['nullable', 'integer', 'exists:products,id'], 'location_id' => ['nullable', 'integer', 'exists:stock_locations,id'], 'per_page' => ['nullable', 'integer', 'between:1,100'], 'page' => ['nullable', 'integer', 'min:1'], 'low_stock' => ['nullable', 'boolean'], 'eligible' => ['nullable', 'boolean'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'status' => ['nullable', 'string', 'max:40']]);
    }

    private function scope($q, array $d, string $locationColumn = 'location_id'): void
    {
        if (isset($d['product_id'])) {
            $q->where('product_id', $d['product_id']);
        }if (isset($d['location_id'])) {
            $q->where($locationColumn, $d['location_id']);
        }
        if (! empty($d['search'])) {
            $s = $d['search'];
            $q->whereHas('product', fn ($q) => $q->where('name_ar', 'like', "%$s%")->orWhere('sku', 'like', "%$s%"));
        }
    }

    public function balances(Request $r): JsonResponse
    {
        $d = $this->filters($r);
        $q = InventoryBalance::with(['product:id,sku,name_ar,serial_tracked,reorder_level,active', 'location']);
        $this->scope($q, $d);
        if ($r->boolean('quarantine')) {
            $q->whereHas('location', fn ($q) => $q->where('sellable', false));
        }
        if ($d['eligible'] ?? false) {
            $q->whereHas('location', fn ($q) => $q->where('active', true)->where('sellable', true))->whereHas('product', fn ($q) => $q->where('active', true))->where('qty_available', '>', 0);
        }
        if ($d['low_stock'] ?? false) {
            // Built with the query builder (not raw SQL) so every table is company-filtered.
            $available = DB::table('inventory_balances as b')->join('stock_locations as l', 'l.id', '=', 'b.location_id')->where('l.active', true)->where('l.sellable', true)->selectRaw('b.product_id, SUM(b.qty_available) AS available')->groupBy('b.product_id');
            $q->whereIn('product_id', DB::table('products as p')->leftJoinSub($available, 'a', 'a.product_id', '=', 'p.id')->where('p.active', true)->whereRaw('p.reorder_level > COALESCE(a.available,0)')->select('p.id'));
        }
        $page = $q->orderBy('product_id')->orderBy('location_id')->paginate(\App\Support\PerPage::resolve(25));
        $page->through(function ($row) use ($r) {
            $row = $row->toArray();
            $row['sellable_quantity'] = $row['location']['active'] && $row['location']['sellable'] && $row['product']['active'] ? $row['qty_available'] : '0.0000';
            if (! $r->user()->hasPermission('inventory.view_cost')) {
                unset($row['inventory_value'],$row['average_cost']);
            }

            return $row;
        });

        return response()->json($page);
    }

    public function movements(Request $r): JsonResponse
    {
        $d = $this->filters($r);
        $q = InventoryMovement::with(['product:id,sku,name_ar', 'location']);
        $this->scope($q, $d);
        if (isset($d['from'])) {
            $q->where('movement_date', '>=', $d['from']);
        }if (isset($d['to'])) {
            $q->where('movement_date', '<=', $d['to']);
        }
        $page = $q->orderByDesc('id')->paginate(\App\Support\PerPage::resolve(25));
        $page->through(function ($row) use ($r) {
            $a = $row->toArray();
            if (! $r->user()->hasPermission('inventory.view_cost')) {
                unset($a['unit_cost'],$a['total_cost']);
            }

            return $a;
        });

        return response()->json($page);
    }

    public function reorder(Request $r): JsonResponse
    {
        $d = $this->filters($r);
        $available = DB::table('inventory_balances as b')->join('stock_locations as l', 'l.id', '=', 'b.location_id')->where('l.active', true)->where('l.sellable', true)->selectRaw('b.product_id, SUM(b.qty_available) AS available')->groupBy('b.product_id');
        $q = DB::table('products as p')->leftJoinSub($available, 'b', 'b.product_id', '=', 'p.id')->where('p.active', true)->whereRaw('p.reorder_level > COALESCE(b.available,0)')->select(['p.id', 'p.sku', 'p.name_ar', 'p.reorder_level'])->selectRaw('CAST(COALESCE(b.available,0) AS DECIMAL(18,4)) AS available, CAST(p.reorder_level-COALESCE(b.available,0) AS DECIMAL(18,4)) AS suggested_quantity');
        if (! empty($d['search'])) {
            $s = $d['search'];
            $q->where(fn ($q) => $q->where('p.name_ar', 'like', "%$s%")->orWhere('p.sku', 'like', "%$s%"));
        }

        return response()->json($q->orderBy('p.sku')->paginate(\App\Support\PerPage::resolve(25)));
    }

    /**
     * Internal serial numbers (OPEN-{SKU}-001…) for opening stock whose real serials are
     * unknown. Skips numbers already issued or waiting in an unposted adjustment.
     */
    public function suggestSerials(Request $r): JsonResponse
    {
        $d = $r->validate(['product_id' => ['required', 'integer', 'exists:products,id'], 'count' => ['required', 'integer', 'between:1,500'], 'exclude' => ['sometimes', 'array', 'max:1000'], 'exclude.*' => ['string', 'max:120']]);
        $sku = (string) DB::table('products')->where('id', $d['product_id'])->value('sku');
        $prefix = 'OPEN-'.trim(preg_replace('/[^A-Z0-9_.\/-]+/', '-', strtoupper($sku)), '-').'-';
        $used = SerialNumber::where('serial_no', 'like', $prefix.'%')->pluck('serial_no')->all();
        DB::table('stock_adjustment_lines as l')->join('stock_adjustments as a', 'a.id', '=', 'l.stock_adjustment_id')
            ->where('a.status', '!=', 'posted')->where('l.product_id', $d['product_id'])->pluck('l.serials')
            ->each(function ($json) use (&$used) {
                $used = [...$used, ...(json_decode((string) $json, true) ?: [])];
            });
        $used = array_flip([...$used, ...array_map(fn ($s) => StockLedger::normalizeSerial($s), $d['exclude'] ?? [])]);
        $serials = [];
        for ($n = 1; count($serials) < $d['count']; $n++) {
            $candidate = $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            if (! isset($used[$candidate])) {
                $serials[] = $candidate;
            }
        }

        return response()->json(['data' => $serials]);
    }

    public function serials(Request $r): JsonResponse
    {
        $d = $this->filters($r);
        $q = SerialNumber::with(['product:id,sku,name_ar', 'location']);
        if (isset($d['product_id'])) {
            $q->where('product_id', $d['product_id']);
        }if (isset($d['location_id'])) {
            $q->where('current_location_id', $d['location_id']);
        }
        if (! empty($d['search'])) {
            $q->where('serial_no', 'like', '%'.StockLedger::normalizeSerial($d['search']).'%');
        }
        if (! empty($d['status'])) {
            $q->where('status', $d['status']);
        }
        if ($d['eligible'] ?? false) {
            $q->where('status', 'in_stock')->whereHas('location', fn ($q) => $q->where('active', true)->where('sellable', true))->whereHas('product', fn ($q) => $q->where('active', true));
        }
        $page = $q->orderByDesc('id')->paginate(\App\Support\PerPage::resolve(25));
        $page->through(function ($row) use ($r) {
            $a = $row->toArray();
            if (! $r->user()->hasPermission('inventory.view_cost')) {
                unset($a['acquisition_cost']);
            }

            return $a;
        });

        return response()->json($page);
    }

    public function serialHistory(Request $r, SerialNumber $serial): JsonResponse
    {
        $data = DB::table('serial_movements as s')->join('inventory_movements as m', 'm.id', '=', 's.inventory_movement_id')->leftJoin('stock_locations as fl', 'fl.id', '=', 's.from_location_id')->leftJoin('stock_locations as tl', 'tl.id', '=', 's.to_location_id')->where('s.serial_number_id', $serial->id)->select(['s.id', 's.from_status', 's.to_status', 's.occurred_at', 's.actor_id', 'm.source_type', 'm.source_id', 'm.movement_type', 'm.movement_date', 'fl.name_ar as from_location', 'tl.name_ar as to_location'])->orderByDesc('s.id')->paginate(\App\Support\PerPage::resolve(50));

        return response()->json($data);
    }

    public function policy(): JsonResponse
    {
        return response()->json(['data' => DB::table('approval_policies')->where('key', 'inventory_adjustment')->first()]);
    }

    public function savePolicy(Request $r): JsonResponse
    {
        $d = $r->validate(['threshold' => ['required', 'string', Decimal::MONEY_RULE], 'segregate_requester' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return DB::transaction(function () use ($d) {
            $old = DB::table('approval_policies')->where('key', 'inventory_adjustment')->lockForUpdate()->first();
            DB::table('approval_policies')->where('key', 'inventory_adjustment')->update(['threshold' => $d['threshold'], 'segregate_requester' => $d['segregate_requester'], 'version' => $old->version + 1, 'updated_at' => now()]);
            app(RecordAudit::class)->execute('inventory.approval_policy_changed', 'approval_policy', null, (array) $old, $d);

            return $this->policy();
        }, 3);
    }
}
