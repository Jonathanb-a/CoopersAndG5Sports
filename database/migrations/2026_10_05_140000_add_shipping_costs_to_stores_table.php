<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Costo de envío por tienda. Vacío (null) = ese método de entrega no se ofrece.
        Schema::table('stores', function (Blueprint $table) {
            $table->decimal('local_shipping_cost', 10, 2)->nullable()->after('bank_info');
            $table->decimal('national_shipping_cost', 10, 2)->nullable()->after('local_shipping_cost');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['local_shipping_cost', 'national_shipping_cost']);
        });
    }
};
