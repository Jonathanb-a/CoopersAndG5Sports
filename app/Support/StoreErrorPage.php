<?php

namespace App\Support;

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveStore;
use App\Models\Store;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Páginas de error (404, 419, 500, 503) con el diseño de la tienda del dominio. Fuera de una tienda
 * (el panel /admin, Livewire, peticiones JSON o un dominio sin tienda) deja la respuesta de Laravel.
 * El 500 solo se reemplaza con APP_DEBUG apagado: en desarrollo se sigue viendo el detalle del error.
 */
class StoreErrorPage
{
    public const STATUSES = [404, 419, 500, 503];

    public const TEXTS = [
        404 => ['title' => 'No encontramos esta página', 'message' => 'Puede que el enlace esté mal escrito o que la página ya no exista.'],
        419 => ['title' => 'Tu sesión venció', 'message' => 'Pasó mucho tiempo sin actividad. Vuelve a cargar la página e inténtalo de nuevo.'],
        500 => ['title' => 'Algo salió mal', 'message' => 'Tuvimos un problema de nuestro lado. Inténtalo de nuevo en unos minutos.'],
        503 => ['title' => 'Volvemos en un momento', 'message' => 'Estamos haciendo mejoras en la tienda. Regresa en unos minutos.'],
    ];

    public static function render(Response $response, Request $request): Response
    {
        $status = $response->getStatusCode();

        if (! in_array($status, self::STATUSES, true)
            || ($status === 500 && config('app.debug'))
            || $request->expectsJson()
            || $request->is('admin', 'admin/*', 'livewire/*', 'filament/*')) {
            return $response;
        }

        try {
            $store = app()->bound(ResolveStore::CONTAINER_KEY) ? app(ResolveStore::CONTAINER_KEY) : ResolveStore::forHost($request->getHost());
        } catch (Throwable) {
            // Sin base de datos (por ejemplo, en mantenimiento) no hay tienda que pintar.
            return $response;
        }

        if (! $store instanceof Store) {
            return $response;
        }

        try {
            ResolveStore::bind($store);
            self::shareBasics($request);

            $text = self::TEXTS[$status];

            return Inertia::render('error', ['status' => $status, ...$text])
                ->withViewData(['meta' => [
                    'type' => 'website',
                    'title' => "{$text['title']} · {$store->name}",
                    'description' => $text['message'],
                    'site_name' => $store->name,
                    'url' => null,
                    'image' => null,
                ]])
                ->toResponse($request)
                ->setStatusCode($status);
        } catch (Throwable $exception) {
            report($exception);

            return $response;
        }
    }

    /**
     * Lo que normalmente comparte HandleInertiaRequests. Un 419 o un 503 ocurren antes de que corra, y
     * sin sesión iniciada no hay cliente ni errores que leer: se comparte lo mínimo para el layout.
     */
    private static function shareBasics(Request $request): void
    {
        $hasSession = $request->hasSession() && $request->session()->isStarted();

        if ($hasSession) {
            Inertia::share(app(HandleInertiaRequests::class)->share($request));

            return;
        }

        Inertia::share(['auth' => ['customer' => null], 'errors' => (object) [], 'googleEnabled' => false]);
        Inertia::share('miniCart', ['count' => 0, 'subtotal' => '0.00', 'lines' => []]);
    }
}
