<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---------- Sales: dues & cancellation ----------
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('due_amount', 15, 2)->default(0)->after('account_amount');   // still unpaid (on account)
            $table->timestamp('cancelled_at')->nullable()->after('status');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable()->after('cancelled_by');
        });

        // ---------- Money received from / paid to contacts outside a bill ----------
        Schema::create('contact_payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 30)->unique();               // RCPT/BR1/26-27/0001 · PAY/BR1/26-27/0001
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('register_session_id')->nullable()->constrained()->nullOnDelete(); // cash through a drawer
            $table->string('direction', 3);                             // in (from customer) | out (to supplier)
            $table->string('method', 10);                               // cash | card | upi | bank | cheque
            $table->decimal('amount', 15, 2);
            $table->decimal('unallocated', 15, 2)->default(0);           // left as advance / credit
            $table->string('reference', 100)->nullable();               // UTR / cheque no.
            $table->date('date');
            $table->string('note')->nullable();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->index(['contact_id', 'date']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_payment_id')->constrained()->cascadeOnDelete();
            $table->morphs('allocatable');                              // sale | purchase
            $table->decimal('amount', 15, 2);
        });

        // ---------- Quotations ----------
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 30)->unique();               // QTN/BR1/26-27/0001
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone', 15)->nullable();
            $table->char('place_of_supply', 2);
            $table->boolean('is_inter_state')->default(false);
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->date('valid_until')->nullable();
            $table->string('status', 12)->default('draft');            // draft | sent | accepted | rejected | converted
            $table->string('bill_discount_type', 10)->nullable();
            $table->decimal('bill_discount_value', 15, 2)->default(0);
            foreach (['subtotal', 'line_discount_total', 'bill_discount_total', 'taxable_total', 'cgst_total', 'sgst_total', 'igst_total', 'cess_total', 'grand_total'] as $col) {
                $table->decimal($col, 15, 2)->default(0);
            }
            $table->decimal('round_off', 8, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('sku', 50);
            $table->string('hsn_code', 8)->nullable();
            $table->string('unit', 10);
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 15, 2)->default(0);
            foreach (['line_discount', 'bill_discount_share', 'taxable_value', 'cgst', 'sgst', 'igst', 'cess', 'line_total'] as $col) {
                $table->decimal($col, 15, 2)->default(0);
            }
            $table->decimal('gst_rate', 5, 2)->default(0);
        });

        // ---------- Purchasing ----------
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 30)->unique();               // PO/BR1/26-27/0001
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('status', 20)->default('draft');            // draft | ordered | partially_received | received | cancelled
            $table->decimal('estimated_total', 15, 2)->default(0);      // Σ qty × cost (before GST)
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('received_quantity', 15, 3)->default(0);
            $table->decimal('unit_cost', 15, 2);
        });

        // Goods receipt + supplier bill
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 30)->unique();               // PUR/BR1/26-27/0001
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('supplier_invoice_no', 50)->nullable();
            $table->date('supplier_invoice_date')->nullable();
            $table->date('received_date');
            $table->char('place_of_supply', 2);
            $table->boolean('is_inter_state')->default(false);
            foreach (['subtotal', 'discount_total', 'taxable_total', 'cgst_total', 'sgst_total', 'igst_total', 'cess_total', 'other_charges', 'grand_total', 'paid_amount', 'due_amount'] as $col) {
                $table->decimal($col, 15, 2)->default(0);
            }
            $table->decimal('round_off', 8, 2)->default(0);
            $table->string('payment_status', 10)->default('due');      // paid | partial | due
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['supplier_id', 'supplier_invoice_no']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('variant_name')->nullable();
            $table->string('sku', 50);
            $table->string('hsn_code', 8)->nullable();
            $table->string('unit', 10);
            $table->decimal('quantity', 15, 3);
            $table->decimal('returned_quantity', 15, 3)->default(0);
            $table->decimal('unit_cost', 15, 2);                         // supplier rate per unit, excl. GST
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 15, 2)->default(0);
            foreach (['line_discount', 'taxable_value', 'cgst', 'sgst', 'igst', 'cess', 'line_total'] as $col) {
                $table->decimal($col, 15, 2)->default(0);
            }
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('landed_unit_cost', 15, 4)->default(0);     // taxable + share of freight, per unit → stock cost
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 30)->unique();               // DN/BR1/26-27/0001 (debit note)
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('date');
            foreach (['taxable_total', 'cgst_total', 'sgst_total', 'igst_total', 'cess_total', 'total'] as $col) {
                $table->decimal($col, 15, 2)->default(0);
            }
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            foreach (['taxable_value', 'cgst', 'sgst', 'igst', 'cess', 'line_total'] as $col) {
                $table->decimal($col, 15, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        foreach (['purchase_return_items', 'purchase_returns', 'purchase_items', 'purchases', 'purchase_order_items', 'purchase_orders',
            'quotation_items', 'quotations', 'payment_allocations', 'contact_payments'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['due_amount', 'cancelled_at', 'cancel_reason']);
        });
    }
};
