<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('mobile', 15)->nullable();
            $table->string('photo_path')->nullable();
            $table->string('designation', 60)->nullable();      // Cook, Helper, Waiter…
            $table->date('joining_date');
            $table->date('leaving_date')->nullable();
            $table->string('salary_type', 10)->default('monthly'); // monthly | daily
            $table->decimal('salary_amount', 12, 2);              // per month, or per day for daily
            // Balance before this app: + = restaurant owes staff, − = staff owes (advance not yet recovered).
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->string('id_type', 30)->nullable();
            $table->string('id_number', 40)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('emergency_contact', 80)->nullable();
            $table->string('note', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('share_token', 64)->nullable()->unique(); // Phase 6: public read-only link
            $table->string('share_pin', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 1); // P present · A absent · H half day · L paid leave · O weekly off
            $table->string('note', 120)->nullable();
            $table->timestamps();
            $table->unique(['staff_id', 'date']);
            $table->index('date');
        });

        Schema::create('staff_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();
            $table->date('date')->index();
            $table->string('type', 10);           // salary | advance | bonus | deduction
            $table->decimal('amount', 12, 2);
            $table->string('payment_mode', 10)->nullable(); // null for deduction (no money moves)
            $table->string('for_month', 7)->nullable();     // Y-m the salary is for
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_payments');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('staff');
    }
};
