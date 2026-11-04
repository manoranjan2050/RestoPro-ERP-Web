<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What was paid on the bill itself at purchase / "mark paid". Khata payments are tracked separately
        // and spread over bills FIFO; expenses.paid_amount = direct_paid + allocated khata payments.
        Schema::table('expenses', function (Blueprint $table) {
            $table->decimal('direct_paid', 12, 2)->default(0)->after('amount');
        });
        DB::table('expenses')->update(['direct_paid' => DB::raw('paid_amount')]);

        Schema::create('vendor_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->date('date')->index();
            $table->decimal('amount', 12, 2);
            $table->string('payment_mode', 10)->default('cash');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Which bill (or the opening balance, expense_id = null) each payment settled. Rebuilt on every change.
        Schema::create('vendor_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_payment_id')->constrained('vendor_payments')->cascadeOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_payment_allocations');
        Schema::dropIfExists('vendor_payments');
        Schema::table('expenses', fn (Blueprint $t) => $t->dropColumn('direct_paid'));
    }
};
