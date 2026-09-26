<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $t->string('name_ar', 120);
            $t->string('name_en', 120)->nullable();
            $t->string('slug', 160)->unique();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('brands', function (Blueprint $t) {
            $t->id();
            $t->string('name_ar', 120);
            $t->string('name_en', 120)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('units', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name_ar', 80);
            $t->unsignedTinyInteger('decimal_places')->default(0);
        });
        Schema::create('warranty_policies', function (Blueprint $t) {
            $t->id();
            $t->string('name_ar', 120);
            $t->unsignedInteger('duration_value');
            $t->string('duration_unit', 20);
            $t->string('provider_type', 30);
            $t->text('terms')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('sku', 80)->unique();
            $t->string('name_ar', 180);
            $t->string('name_en', 180)->nullable();
            $t->string('manufacturer_model', 120)->nullable();
            $t->foreignId('brand_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('category_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('unit_id')->constrained()->restrictOnDelete();
            $t->foreignId('warranty_policy_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('tax_code_id')->nullable()->constrained()->restrictOnDelete();
            $t->boolean('serial_tracked')->default(true);
            $t->decimal('standard_cost', 18, 4)->default(0);
            $t->decimal('cash_price', 18, 4);
            $t->decimal('installment_price', 18, 4);
            $t->decimal('minimum_price', 18, 4);
            $t->decimal('reorder_level', 18, 4)->default(0);
            $t->json('specifications')->nullable();
            $t->string('energy_rating', 30)->nullable();
            $t->string('country_of_origin', 80)->nullable();
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['active', 'category_id', 'brand_id']);
            $t->index('manufacturer_model');
            $t->index('name_ar');
        });
        Schema::create('product_barcodes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->restrictOnDelete();
            $t->string('barcode', 100)->unique();
        });
        Schema::create('stock_locations', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name_ar', 120);
            $t->string('purpose', 30);
            $t->boolean('sellable')->default(true);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['stock_locations', 'product_barcodes', 'products', 'warranty_policies', 'units', 'brands', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
