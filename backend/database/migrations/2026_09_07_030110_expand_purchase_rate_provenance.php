<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', fn (Blueprint $t) => $t->string('exchange_rate_source', 160)->change());
    }

    public function down(): void
    { /* Preserve complete rate provenance in existing documents. */
    }
};
