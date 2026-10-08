<?php

namespace Tests;

use App\Http\Middleware\ResolveStore;
use App\Models\Store;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Simula la tienda de una petición de la tienda en línea (lo que hace ResolveStore) para llamar a un servicio como cliente. */
    protected function requestingStore(Store $store): void
    {
        app()->instance(ResolveStore::CONTAINER_KEY, $store);
    }
}
