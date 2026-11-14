<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('article_no', 50)->nullable();     // brand style / article code printed on tags
            $table->string('type', 10)->default('single');   // single | variable
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('tax_rate_id')->constrained()->restrictOnDelete();
            $table->string('hsn_code', 8)->nullable();
            $table->string('material', 100)->nullable();      // fabric: Cotton, Linen, Silk…
            $table->boolean('price_includes_tax')->default(true);
            $table->boolean('track_stock')->default(true);    // false for services / non-stock items
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
            $table->index('article_no');
        });

        // Every sellable item is a variant; a "single" product has exactly one variant with no name.
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();               // "Navy / 40" built from attribute values; null for single products
            $table->string('sku', 50)->unique();
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->decimal('mrp', 15, 2)->nullable();
            $table->decimal('selling_price', 15, 2);
            $table->decimal('wholesale_price', 15, 2)->nullable();
            $table->decimal('reorder_level', 15, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Which attribute values (e.g. Colour=Navy, Size=40) make up each variant.
        Schema::create('attribute_value_product_variant', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->constrained()->restrictOnDelete();
            $table->primary(['product_variant_id', 'attribute_value_id']);
        });

        Schema::create('product_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->string('barcode', 50)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('attribute_value_product_variant');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
    }
};
