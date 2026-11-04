<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.0 Phase 18 — simple stock. Every change to an item's (or size's) stock is one row here; items.stock_qty and
 * item_variants.stock_qty are the running totals kept in step by App\Services\Stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $t->foreignId('variant_id')->nullable()->constrained('item_variants')->cascadeOnDelete();
            $t->date('date')->index();
            $t->decimal('qty', 12, 3);                 // + in, − out
            $t->string('reason', 10);                  // made | purchase | sale | wastage | adjust | opening
            $t->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $t->decimal('balance', 12, 3)->default(0); // stock after this movement
            $t->string('note', 120)->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['item_id', 'variant_id']);
            $t->index(['order_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
