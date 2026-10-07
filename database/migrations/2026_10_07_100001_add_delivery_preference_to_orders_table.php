<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preferencia del cliente para la entrega local (día y rango de hora). Es solo lo que pidió:
        // la fecha confirmada sigue en delivery_scheduled_date y delivery_window, que pone el admin.
        Schema::table('orders', function (Blueprint $table) {
            $table->date('delivery_preferred_date')->nullable()->after('delivery_window');
            $table->string('delivery_preferred_window')->nullable()->after('delivery_preferred_date');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_preferred_date', 'delivery_preferred_window']);
        });
    }
};
