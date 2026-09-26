<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Catalog images are replaceable master data, not retained financial evidence,
    // so they live outside the immutable document_attachments register.
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('original_name', 255);
            $t->string('stored_path', 255)->unique();
            $t->string('mime_type', 80);
            $t->unsignedBigInteger('size');
            $t->char('checksum', 64);
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
