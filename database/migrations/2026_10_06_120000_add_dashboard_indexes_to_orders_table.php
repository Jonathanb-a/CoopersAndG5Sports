<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El dashboard filtra siempre por tienda: ventas por estado y fecha de pago, y listas por
        // fecha de creación. Antes solo había índice de status (y el de la llave foránea de store_id).
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['store_id', 'status', 'paid_at'], 'orders_store_status_paid_at_index');
            $table->index(['store_id', 'created_at'], 'orders_store_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_store_status_paid_at_index');
            $table->dropIndex('orders_store_created_at_index');
        });
    }
};
