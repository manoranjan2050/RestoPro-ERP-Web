<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.0 Phase 14 — sales catalogue + customers, and the POS share of each day's cash collection.
 * cash_collections.cash/upi/online stay the DAY TOTALS (every v1 report reads them); pos_* is the part that came
 * from bills, so the hand-entered part = total − pos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60)->unique();
            $t->string('color', 9)->default('#f97316');
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('category_id')->nullable()->constrained('item_categories')->nullOnDelete();
            $t->string('name', 100);
            $t->string('code', 30)->nullable()->unique();     // short code typed at the counter
            $t->string('barcode', 40)->nullable()->unique();
            $t->string('unit', 10)->default('pcs');           // pcs | kg | g | plate | box | ltr
            $t->decimal('price', 12, 2)->default(0);          // base price (used when the item has no variants)
            $t->decimal('gst_rate', 5, 2)->default(0);        // 0 | 5 | 12 | 18 | 28
            $t->string('hsn', 10)->nullable();
            $t->string('food_type', 7)->nullable();           // veg | nonveg | egg
            $t->string('photo_path')->nullable();
            $t->string('description', 255)->nullable();
            $t->boolean('track_stock')->default(false);
            $t->decimal('stock_qty', 12, 3)->default(0);
            $t->decimal('low_stock_at', 12, 3)->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['category_id', 'is_active']);
        });

        Schema::create('item_variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $t->string('name', 40);                           // 500 g · 1 kg · Regular · Large
            $t->decimal('price', 12, 2);
            $t->string('barcode', 40)->nullable()->unique();
            $t->decimal('stock_qty', 12, 3)->default(0);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['item_id', 'name']);
        });

        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 80);
            $t->string('mobile', 15)->nullable()->unique();
            $t->string('email', 120)->nullable();
            $t->string('address', 255)->nullable();
            $t->date('birthday')->nullable();
            $t->date('anniversary')->nullable();
            $t->string('gstin', 15)->nullable();              // for B2B GST bills
            $t->string('note', 255)->nullable();
            $t->timestamps();
            $t->index('name');
        });

        Schema::table('cash_collections', function (Blueprint $t) {
            $t->decimal('pos_cash', 12, 2)->default(0)->after('online');
            $t->decimal('pos_upi', 12, 2)->default(0)->after('pos_cash');
            $t->decimal('pos_online', 12, 2)->default(0)->after('pos_upi');
        });
    }

    public function down(): void
    {
        Schema::table('cash_collections', fn (Blueprint $t) => $t->dropColumn(['pos_cash', 'pos_upi', 'pos_online']));
        Schema::dropIfExists('customers');
        Schema::dropIfExists('item_variants');
        Schema::dropIfExists('items');
        Schema::dropIfExists('item_categories');
    }
};
