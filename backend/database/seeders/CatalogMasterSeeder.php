<?php

namespace Database\Seeders;

use App\Domains\Catalog\Models\Unit;
use App\Domains\Inventory\Models\StockLocation;
use Illuminate\Database\Seeder;

class CatalogMasterSeeder extends Seeder
{
    public function run(): void
    {
        Unit::firstOrCreate(['code' => 'piece'], ['name_ar' => 'قطعة', 'decimal_places' => 0]);
        foreach ([['SHOWROOM', 'صالة العرض', 'showroom', true], ['WAREHOUSE', 'المستودع الرئيسي', 'warehouse', true], ['RETURNS', 'فحص المرتجعات', 'returns', false], ['DAMAGED', 'تالف / مفتوح', 'damaged', false], ['WARRANTY', 'الصيانة والضمان', 'warranty', false], ['RESERVED', 'محجوز للعملاء', 'reserved', false]] as [$code,$name,$purpose,$sellable]) {
            StockLocation::firstOrCreate(['code' => $code], ['name_ar' => $name, 'purpose' => $purpose, 'sellable' => $sellable, 'active' => true]);
        }
    }
}
