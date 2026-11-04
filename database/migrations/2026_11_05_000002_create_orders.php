<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.0 Phase 15 — bills/orders. One table for every kind (counter now; dine-in, delivery and advance orders reuse it
 * in Phase 17). Names, rates and GST are frozen on the bill so later price changes never alter old bills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            // Bill number = prefix + financial year + running number, e.g. 2026-27/0042. Given when the bill is saved
            // (held bills have none). Cancelled bills keep their number so the series has no gaps.
            $t->string('number', 30)->nullable()->unique();
            $t->string('fy', 9)->nullable();
            $t->unsignedInteger('seq')->nullable();
            $t->string('type', 10)->default('counter');      // counter | takeaway | dine_in | delivery | advance
            $t->string('status', 12)->default('completed')->index(); // held | open | completed | cancelled
            $t->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $t->string('customer_name', 80)->nullable();
            $t->string('customer_mobile', 15)->nullable();
            $t->string('customer_gstin', 15)->nullable();
            $t->unsignedBigInteger('table_id')->nullable();   // Phase 17
            $t->unsignedSmallInteger('guests')->nullable();
            $t->dateTime('due_at')->nullable();               // advance orders (Phase 17)
            $t->string('delivery_address', 255)->nullable();
            $t->decimal('delivery_charge', 12, 2)->default(0);
            $t->json('details')->nullable();                  // cake details etc.
            $t->boolean('gst_enabled')->default(false);
            $t->boolean('gst_inclusive')->default(true);
            $t->decimal('subtotal', 12, 2)->default(0);       // Σ qty × rate
            $t->string('discount_type', 3)->default('amt');   // amt | pct
            $t->decimal('discount_value', 12, 2)->default(0);
            $t->decimal('discount', 12, 2)->default(0);       // ₹ off
            $t->decimal('taxable', 12, 2)->default(0);
            $t->decimal('cgst', 12, 2)->default(0);
            $t->decimal('sgst', 12, 2)->default(0);
            $t->decimal('round_off', 6, 2)->default(0);
            $t->decimal('total', 12, 2)->default(0);
            $t->decimal('paid', 12, 2)->default(0);
            $t->string('note', 255)->nullable();
            $t->string('share_token', 64)->nullable()->unique(); // Phase 16: customer bill link
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('cancelled_at')->nullable();
            $t->string('cancel_reason', 255)->nullable();
            $t->dateTime('billed_at')->nullable()->index();
            $t->timestamps();
            $t->unique(['fy', 'seq']);
        });

        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $t->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('item_variants')->nullOnDelete();
            $t->string('name', 100);
            $t->string('variant_name', 40)->nullable();
            $t->string('unit', 10)->default('pcs');
            $t->decimal('qty', 12, 3);
            $t->decimal('rate', 12, 2);                       // menu price (incl. or excl. GST as per the bill)
            $t->decimal('gst_rate', 5, 2)->default(0);
            $t->string('hsn', 10)->nullable();
            $t->decimal('amount', 12, 2);                     // qty × rate
            $t->decimal('discount', 12, 2)->default(0);       // share of the bill discount
            $t->decimal('taxable', 12, 2)->default(0);
            $t->decimal('tax', 12, 2)->default(0);
            $t->decimal('total', 12, 2);                      // taxable + tax
            $t->string('note', 120)->nullable();
            $t->unsignedInteger('kot_no')->nullable();        // Phase 17
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('order_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $t->date('date')->index();
            $t->string('mode', 8);                            // cash | upi | card | online
            $t->decimal('amount', 12, 2);                     // negative = refund
            $t->string('reference', 60)->nullable();          // UPI ref / card last digits
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
