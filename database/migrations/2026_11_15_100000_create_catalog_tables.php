<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('short_name', 10)->unique();   // pcs, kg, ltr…
            $table->boolean('allow_decimal')->default(false); // weighed/loose items
            $table->timestamps();
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);                    // "GST 18%", "Garments"
            $table->decimal('rate', 5, 2);                 // total GST %, split into CGST/SGST or used as IGST
            $table->decimal('cess_rate', 5, 2)->default(0);
            // Price-based slab (garments): above this taxable value per piece, rate_above applies instead.
            $table->decimal('threshold_amount', 15, 2)->nullable();
            $table->decimal('rate_above', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Variant dimensions: Colour, Size, Waist, Shirt size, Kids age…
        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0); // order in variant names ("Navy / 40") and matrices
            $table->timestamps();
        });

        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->string('value', 50);
            $table->unsignedSmallInteger('sort_order')->default(0);   // S before M before L
            $table->timestamps();
            $table->unique(['attribute_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('units');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};
