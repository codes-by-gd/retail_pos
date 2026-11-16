<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---------- Expenses ----------
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('reference_no', 30)->unique();                 // EXP/BR1/26-27/0001
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 15, 2);                             // total paid, incl. any GST
            $table->decimal('gst_amount', 15, 2)->default(0);              // GST included in amount (for reference)
            $table->string('method', 10);                                 // cash | upi | card | bank | cheque
            $table->string('reference', 100)->nullable();                 // UTR / cheque / bill no.
            $table->string('paid_to', 150)->nullable();                   // payee: landlord, electricity board, staff…
            $table->text('note')->nullable();
            $table->foreignId('register_session_id')->nullable()->constrained()->nullOnDelete(); // cash taken from a drawer
            $table->string('attachment_path')->nullable();                // bill / receipt photo
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'date']);
        });

        // ---------- In-app notifications (Laravel database channel) ----------
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable()->after('is_active');
        });

        // ---------- Performance: date-range lookups used by reports, dashboard and lists ----------
        Schema::table('sale_returns', fn (Blueprint $t) => $t->index(['branch_id', 'returned_at']));
        Schema::table('contact_payments', fn (Blueprint $t) => $t->index(['branch_id', 'date']));
        Schema::table('purchases', fn (Blueprint $t) => $t->index(['branch_id', 'received_date']));
        Schema::table('purchase_returns', fn (Blueprint $t) => $t->index(['branch_id', 'date']));
        Schema::table('stock_movements', fn (Blueprint $t) => $t->index(['branch_id', 'created_at']));
        Schema::table('register_sessions', fn (Blueprint $t) => $t->index(['branch_id', 'opened_at']));
        Schema::table('sales', fn (Blueprint $t) => $t->index(['status', 'due_amount']));
        Schema::table('activity_log', fn (Blueprint $t) => $t->index('created_at'));
    }

    public function down(): void
    {
        Schema::table('activity_log', fn (Blueprint $t) => $t->dropIndex(['created_at']));
        Schema::table('sales', fn (Blueprint $t) => $t->dropIndex(['status', 'due_amount']));
        // MySQL now uses these composite indexes for the branch_id foreign keys: give each FK its own index back first.
        foreach (['register_sessions' => 'opened_at', 'stock_movements' => 'created_at', 'purchase_returns' => 'date',
            'purchases' => 'received_date', 'contact_payments' => 'date', 'sale_returns' => 'returned_at'] as $table => $column) {
            Schema::table($table, function (Blueprint $t) use ($table, $column) {
                if (! Schema::hasIndex($table, ['branch_id'])) {
                    $t->index('branch_id');
                }
                $t->dropIndex(['branch_id', $column]);
            });
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notification_preferences'));
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
