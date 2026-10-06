<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Intentos de entrega a domicilio de un pedido local.
        Schema::create('delivery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('result');
            $table->text('notes')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_attempts');
    }
};
