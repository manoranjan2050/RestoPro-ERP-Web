<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('color', 9)->default('#64748b');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->unique();
            $table->string('mobile', 15)->nullable();
            $table->string('address', 255)->nullable();
            $table->foreignId('default_category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->decimal('opening_balance', 12, 2)->default(0); // amount owed to the vendor before using this app
            $table->string('note', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('status', 10)->default('paid')->index(); // paid | partial | unpaid — derived from paid_amount
            $table->string('payment_mode', 10)->nullable();          // cash | upi | bank | card
            $table->date('paid_on')->nullable();
            $table->string('note', 255)->nullable();
            $table->string('bill_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        $defaults = [
            ['Chena/Paneer', '#f59e0b'], ['Electricity', '#eab308'], ['Gas', '#ef4444'], ['Grocery', '#10b981'],
            ['Koila', '#475569'], ['Milk', '#0ea5e9'], ['PAN/TEA/EXTRA', '#8b5cf6'], ['Vegetable', '#22c55e'], ['Other', '#64748b'],
        ];
        DB::table('expense_categories')->insert(array_map(fn ($c, $i) => [
            'name' => $c[0], 'color' => $c[1], 'sort' => $i, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], $defaults, array_keys($defaults)));
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('expense_categories');
    }
};
