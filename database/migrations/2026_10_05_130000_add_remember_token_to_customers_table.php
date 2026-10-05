<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Para "Mantener sesión iniciada" en el login de clientes.
        Schema::table('customers', function (Blueprint $table) {
            $table->rememberToken()->after('google_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropRememberToken();
        });
    }
};
