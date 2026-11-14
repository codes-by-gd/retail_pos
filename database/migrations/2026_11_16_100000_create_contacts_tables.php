<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('price_type', 10)->default('retail');     // retail | wholesale — which variant price the POS uses
            $table->decimal('discount_percent', 5, 2)->default(0);   // automatic bill discount for members
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();                   // CUS-00001 / SUP-00001
            $table->string('type', 10);                             // customer | supplier | both
            $table->string('name');
            $table->string('company_name')->nullable();             // firm name for suppliers / B2B customers
            $table->string('phone', 15)->nullable()->unique();      // normalised digits; POS lookup key
            $table->string('alt_phone', 15)->nullable();
            $table->string('email')->nullable();
            $table->string('gstin', 15)->nullable();
            $table->char('state_code', 2)->nullable();              // place of supply (CGST+SGST vs IGST)
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->foreignId('customer_group_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('credit_limit', 15, 2)->default(0);     // 0 = no credit sales allowed
            $table->unsignedSmallInteger('payment_terms_days')->nullable(); // suppliers: pay within N days
            $table->date('date_of_birth')->nullable();
            $table->date('anniversary')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_walk_in')->default(false);          // the built-in counter customer
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'name']);
        });

        // Append-only account ledger. Balance = SUM(debit) − SUM(credit):
        // positive → the contact owes us (receivable), negative → we owe them (payable).
        Schema::create('contact_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('type', 30);                             // see LedgerEntryType
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->nullableMorphs('reference');                    // sale, payment, purchase… (later phases)
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['contact_id', 'date', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_ledger_entries');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('customer_groups');
    }
};
