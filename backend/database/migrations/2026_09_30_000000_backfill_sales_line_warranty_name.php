<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Older sales lines stored the warranty snapshot without the policy name; fill it from the product's current policy.
    public function up(): void
    {
        DB::table('sales_invoice_lines as l')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->join('warranty_policies as w', 'w.id', '=', 'p.warranty_policy_id')
            ->whereNotNull('l.warranty_snapshot')
            ->select('l.id', 'l.warranty_snapshot', 'w.name_ar')
            ->orderBy('l.id')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $snapshot = json_decode($row->warranty_snapshot, true);
                    if (! is_array($snapshot) || ! empty($snapshot['name_ar'])) {
                        continue;
                    }
                    $snapshot['name_ar'] = $row->name_ar;
                    DB::table('sales_invoice_lines')->where('id', $row->id)->update(['warranty_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
                }
            });
    }

    public function down(): void
    {
    }
};
