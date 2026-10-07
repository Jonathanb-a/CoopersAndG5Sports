<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Datos para «Recoger en tienda»: se muestran en el checkout, en la página de gracias y en
        // el detalle del pedido del cliente.
        Schema::table('stores', function (Blueprint $table) {
            $table->string('pickup_address', 500)->nullable()->after('national_shipping_cost');
            // Texto libre, por ejemplo "Lun a Sáb 10:00 a 19:00".
            $table->string('pickup_hours')->nullable()->after('pickup_address');
            $table->string('pickup_map_url', 500)->nullable()->after('pickup_hours');
            $table->text('pickup_notes')->nullable()->after('pickup_map_url');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['pickup_address', 'pickup_hours', 'pickup_map_url', 'pickup_notes']);
        });
    }
};
