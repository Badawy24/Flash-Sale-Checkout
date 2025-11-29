<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hold_id')->constrained('holds')->cascadeOnDelete();

            $table->unsignedInteger('quantity');
            $table->decimal('price', 10, 2);

            $table->enum('status', ['pending', 'paid', 'cancelled'])->default('pending');
            $table->string('payment_webhook_reference')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
