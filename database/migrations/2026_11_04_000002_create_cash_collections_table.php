<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per business day: the end-of-day count of cash, UPI and online (Swiggy/Zomato etc.) sales.
        Schema::create('cash_collections', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->decimal('cash', 12, 2)->default(0);
            $table->decimal('upi', 12, 2)->default(0);
            $table->decimal('online', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0); // kept in sync by the model
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_collections');
    }
};
