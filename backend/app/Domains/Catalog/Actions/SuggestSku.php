<?php

namespace App\Domains\Catalog\Actions;

use Illuminate\Support\Facades\DB;

/**
 * Proposes the next free SKU as BRAND-CATEGORY-NNN, for example LG-REF-001.
 * Segments come from the brand's English name and the category slug; either is
 * omitted when unavailable, and "PRD" is used when both are missing.
 */
class SuggestSku
{
    public function execute(?int $brandId, ?int $categoryId): string
    {
        $brand = $brandId ? DB::table('brands')->where('id', $brandId)->value('name_en') : null;
        $category = $categoryId ? DB::table('categories')->where('id', $categoryId)->first(['slug', 'name_en']) : null;
        $segments = array_filter([
            $this->code($brand, 3),
            $this->code($category?->slug ?: $category?->name_en, 4),
        ]);
        $prefix = $segments ? implode('-', $segments) : 'PRD';
        $next = 1;
        foreach (DB::table('products')->where('sku', 'like', $prefix.'-%')->pluck('sku') as $sku) {
            if (preg_match('/^'.preg_quote($prefix, '/').'-(\d+)$/', $sku, $m)) {
                $next = max($next, (int) $m[1] + 1);
            }
        }

        return sprintf('%s-%03d', $prefix, $next);
    }

    private function code(?string $value, int $length): ?string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $value));

        return $clean === '' ? null : substr($clean, 0, $length);
    }
}
