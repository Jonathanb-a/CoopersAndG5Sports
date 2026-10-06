<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Entrega a domicilio local: la fecha y el horario los acuerda el admin con el cliente.
        Schema::table('orders', function (Blueprint $table) {
            $table->date('delivery_scheduled_date')->nullable()->after('delivered_at');
            // Texto libre, por ejemplo "Entre 4 y 6 pm".
            $table->string('delivery_window')->nullable()->after('delivery_scheduled_date');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_scheduled_date', 'delivery_window']);
        });
    }
};
