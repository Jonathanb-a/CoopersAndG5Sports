<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cuándo se avisó al dueño que un pedido en efectivo ya confirmado pasó su fecha sin entregarse.
        // Sirve para avisar una sola vez; se limpia al darle más tiempo o reprogramar la entrega.
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('cash_overdue_notified_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('cash_overdue_notified_at');
        });
    }
};
