<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('tracking_carrier')->nullable()->after('delivered_at');
            $table->string('tracking_number')->nullable()->after('tracking_carrier');
            $table->string('payment_reference')->nullable()->after('paid_at');
            $table->timestamp('cancelled_at')->nullable()->after('tracking_number');
            $table->text('cancel_reason')->nullable()->after('cancelled_at');
            // customer | admin | system
            $table->string('cancelled_by')->nullable()->after('cancel_reason');
            $table->timestamp('refunded_at')->nullable()->after('cancelled_by');
            $table->timestamp('cancellation_requested_at')->nullable()->after('refunded_at');
            $table->text('cancellation_request_note')->nullable()->after('cancellation_requested_at');
            // Notas del personal. Aparte de `notes`, que son las del cliente y él las ve en su pedido.
            $table->text('internal_notes')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'tracking_carrier', 'tracking_number', 'payment_reference', 'cancelled_at', 'cancel_reason',
                'cancelled_by', 'refunded_at', 'cancellation_requested_at', 'cancellation_request_note', 'internal_notes',
            ]);
        });
    }
};
