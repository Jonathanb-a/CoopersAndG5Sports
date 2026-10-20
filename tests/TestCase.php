<?php

namespace Tests;

use App\Http\Middleware\ResolveStore;
use App\Models\Store;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sin esperas reales (p. ej. los 200 ms del Timebox de recuperar contraseña) ni llamadas HTTP de verdad
        // (Telegram, Google): lo que necesite HTTP lo declara con Http::fake().
        Sleep::fake();
        Http::preventStrayRequests();
    }

    /** Simula la tienda de una petición de la tienda en línea (lo que hace ResolveStore) para llamar a un servicio como cliente. */
    protected function requestingStore(Store $store): void
    {
        app()->instance(ResolveStore::CONTAINER_KEY, $store);
    }
}
