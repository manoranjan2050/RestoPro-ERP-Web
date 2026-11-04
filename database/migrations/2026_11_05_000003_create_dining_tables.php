<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.0 Phase 17 — dine-in tables, and a `stage` for orders that are still running:
 *   dine_in:  open while guests eat (KOTs sent), completed when settled
 *   delivery: placed → preparing → ready → out → delivered
 *   advance:  booked → ready → delivered (picked up)
 * ("dining_tables" because TABLES is a reserved word in MySQL.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_tables', function (Blueprint $t) {
            $t->id();
            $t->string('name', 30);
            $t->string('area', 30)->nullable();      // Ground floor, AC hall, Terrace…
            $t->unsignedSmallInteger('seats')->default(4);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['area', 'name']);
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->string('stage', 12)->nullable()->after('status');
            $t->index(['status', 'type']);
            $t->index('table_id');
            $t->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropIndex(['status', 'type']);
            $t->dropIndex(['table_id']);
            $t->dropIndex(['due_at']);
            $t->dropColumn('stage');
        });
        Schema::dropIfExists('dining_tables');
    }
};
