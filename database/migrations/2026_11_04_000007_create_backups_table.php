<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('filename')->unique();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('sha256', 64)->nullable();
            $table->string('trigger', 20);              // manual | scheduled | pre-restore | uploaded
            $table->json('counts')->nullable();          // rows per table
            $table->json('destinations')->nullable();    // {dropbox: {ok, message, at}, ...}
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
