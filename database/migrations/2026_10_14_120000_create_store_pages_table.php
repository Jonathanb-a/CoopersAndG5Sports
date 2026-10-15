<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('title');
            $table->longText('body')->nullable();
            // Nota para el personal (por ejemplo «BORRADOR: revisar con el cliente»). Nunca se muestra al público.
            $table->string('internal_note')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->unique(['store_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_pages');
    }
};
