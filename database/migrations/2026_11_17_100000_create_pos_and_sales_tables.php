<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---------- Cash registers ----------
        Schema::create('registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);                              // "Counter 1"
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
        });

        // One shift on one register: opening float → sales → cash count (Z-report).
        Schema::create('register_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('register_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->decimal('opening_cash', 15, 2)->default(0);
            $table->string('status', 10)->default('open');            // open | closed
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('counted_cash', 15, 2)->nullable();
            $table->decimal('difference', 15, 2)->nullable();         // counted − expected
            $table->string('closing_note')->nullable();
            $table->timestamps();
            $table->index(['register_id', 'status']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('register_session_id')->constrained()->cascadeOnDelete();
            $table->string('type', 5);                                // in | out
            $table->decimal('amount', 15, 2);
            $table->string('reason');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        // ---------- Sales ----------
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 16)->unique();               // GST: max 16 characters
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('register_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();   // cashier
            $table->timestamp('sold_at');
            // customer snapshot (invoice must not change if the contact is edited later)
            $table->string('customer_name');
            $table->string('customer_phone', 15)->nullable();
            $table->string('customer_gstin', 15)->nullable();
            $table->char('place_of_supply', 2);                        // state code
            $table->boolean('is_inter_state')->default(false);        // IGST instead of CGST+SGST
            $table->string('price_type', 10)->default('retail');
            // totals
            $table->decimal('subtotal', 15, 2);                        // Σ unit price × qty, before discounts
            $table->decimal('line_discount_total', 15, 2)->default(0);
            $table->string('bill_discount_type', 10)->nullable();      // percent | amount
            $table->decimal('bill_discount_value', 15, 2)->default(0);
            $table->decimal('bill_discount_total', 15, 2)->default(0);
            $table->decimal('taxable_total', 15, 2);
            $table->decimal('cgst_total', 15, 2)->default(0);
            $table->decimal('sgst_total', 15, 2)->default(0);
            $table->decimal('igst_total', 15, 2)->default(0);
            $table->decimal('cess_total', 15, 2)->default(0);
            $table->decimal('round_off', 8, 2)->default(0);
            $table->decimal('grand_total', 15, 2);
            $table->decimal('paid_total', 15, 2)->default(0);          // all tenders, incl. change
            $table->decimal('change_given', 15, 2)->default(0);
            $table->decimal('account_amount', 15, 2)->default(0);      // put on the customer's account (credit)
            $table->string('status', 15)->default('completed');        // completed | cancelled (Phase 4)
            $table->string('payment_status', 10)->default('paid');     // paid | partial | due
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'sold_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            // snapshot
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('sku', 50);
            $table->string('hsn_code', 8)->nullable();
            $table->string('unit', 10);
            $table->boolean('price_includes_tax');
            $table->decimal('mrp', 15, 2)->nullable();
            $table->decimal('list_price', 15, 2);                      // price before any override
            $table->decimal('unit_price', 15, 2);                      // price charged per unit (after override)
            $table->decimal('quantity', 15, 3);
            $table->decimal('returned_quantity', 15, 3)->default(0);
            $table->string('discount_type', 10)->nullable();           // percent | amount
            $table->decimal('discount_value', 15, 2)->default(0);
            $table->decimal('line_discount', 15, 2)->default(0);
            $table->decimal('bill_discount_share', 15, 2)->default(0);
            $table->decimal('taxable_value', 15, 2);
            $table->decimal('gst_rate', 5, 2);                          // rate actually applied
            $table->decimal('cgst', 15, 2)->default(0);
            $table->decimal('sgst', 15, 2)->default(0);
            $table->decimal('igst', 15, 2)->default(0);
            $table->decimal('cess_rate', 5, 2)->default(0);
            $table->decimal('cess', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->decimal('unit_cost', 15, 4)->default(0);           // average cost at sale time (profit)
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('method', 15);                              // cash | card | upi | account | exchange
            $table->decimal('amount', 15, 2);
            $table->string('reference', 100)->nullable();              // card slip / UPI ref / credit note no.
            $table->foreignId('sale_return_id')->nullable();           // exchange credit used (FK added below)
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->string('credit_note_no', 16)->unique();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('register_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('returned_at');
            $table->decimal('taxable_total', 15, 2);
            $table->decimal('cgst_total', 15, 2)->default(0);
            $table->decimal('sgst_total', 15, 2)->default(0);
            $table->decimal('igst_total', 15, 2)->default(0);
            $table->decimal('cess_total', 15, 2)->default(0);
            $table->decimal('round_off', 8, 2)->default(0);
            $table->decimal('total', 15, 2);
            $table->string('refund_method', 15);                      // cash | card | upi | account | exchange
            $table->foreignId('used_in_sale_id')->nullable()->constrained('sales')->nullOnDelete(); // exchange credit used
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        Schema::table('sale_payments', function (Blueprint $table) {
            $table->foreign('sale_return_id')->references('id')->on('sale_returns')->nullOnDelete();
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->boolean('restock')->default(true);                 // false = damaged, not back in stock
            $table->decimal('taxable_value', 15, 2);
            $table->decimal('cgst', 15, 2)->default(0);
            $table->decimal('sgst', 15, 2)->default(0);
            $table->decimal('igst', 15, 2)->default(0);
            $table->decimal('cess', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
        });

        // Parked bills
        Schema::create('held_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_carts');
        Schema::dropIfExists('sale_return_items');
        Schema::table('sale_payments', fn (Blueprint $t) => $t->dropForeign(['sale_return_id']));
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('register_sessions');
        Schema::dropIfExists('registers');
    }
};
