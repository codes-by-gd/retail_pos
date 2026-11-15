<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only points ledger. Earn/adjust(+) rows track `remaining` so redemptions use the oldest points
        // first and expiry removes exactly what is left of each batch. Balance = SUM(points).
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 15);                                  // earn | redeem | reverse | restore | expire | adjust
            $table->integer('points');                                   // signed
            $table->integer('remaining')->default(0);                    // unspent points of a + row
            $table->date('expires_at')->nullable();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_return_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['contact_id', 'id']);
            $table->index(['expires_at', 'remaining']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->integer('points_earned')->default(0)->after('due_amount');
            $table->integer('points_redeemed')->default(0)->after('points_earned');
        });
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $t) => $t->dropColumn(['points_earned', 'points_redeemed']));
        Schema::dropIfExists('loyalty_transactions');
    }
};
