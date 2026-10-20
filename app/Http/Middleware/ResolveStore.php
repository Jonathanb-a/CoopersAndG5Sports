<?php

namespace App\Http\Middleware;

use App\Models\Category;
use App\Models\Store;
use App\Models\StorePage;
use App\Services\CartService;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ResolveStore
{
    public const CONTAINER_KEY = 'currentStore';

    public function handle(Request $request, Closure $next): Response
    {
        $store = self::forHost($request->getHost());

        abort_if($store === null, 404);

        self::bind($store);

        return $next($request);
    }

    /** La tienda activa de un dominio (stores.domain o, si no hay, un alias de shop.domain_aliases), o null. */
    public static function forHost(string $host): ?Store
    {
        $host = strtolower($host);

        $store = Store::query()
            ->where('domain', $host)
            ->where('is_active', true)
            ->first();

        if ($store === null && ($slug = self::aliases()[$host] ?? null) !== null) {
            $store = Store::query()->where('slug', $slug)->where('is_active', true)->first();
        }

        return $store;
    }

    /**
     * Dominios adicionales de config('shop.domain_aliases') ("dominio=slug,otro=slug"): dominio => slug.
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        $aliases = [];

        foreach (explode(',', (string) config('shop.domain_aliases')) as $pair) {
            [$domain, $slug] = array_pad(array_map('trim', explode('=', $pair, 2)), 2, '');

            if ($domain !== '' && $slug !== '') {
                $aliases[strtolower($domain)] = $slug;
            }
        }

        return $aliases;
    }

    /**
     * Deja la tienda como la actual y comparte con Inertia lo que usa su layout. También lo usan las
     * páginas de error, que pueden ocurrir antes de que corra este middleware.
     */
    public static function bind(Store $store): void
    {
        app()->instance(self::CONTAINER_KEY, $store);

        Inertia::share('store', [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'theme' => $store->theme,
            'whatsapp_phone' => $store->whatsapp_phone,
            'whatsapp_url' => $store->whatsappUrl(),
        ]);

        // Menú de categorías: solo las que tienen al menos un producto activo (y sin borrar).
        Inertia::share('categories', fn () => Category::query()
            ->where('categories.store_id', $store->id)
            ->whereHas('products', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get(['categories.id', 'categories.name', 'categories.slug']));

        // Contador y mini carrito del encabezado. Con el carrito vacío no consulta la base de datos.
        Inertia::share('miniCart', fn () => app(CartService::class)->summary($store));

        // Pie de página: páginas publicadas y datos de la tienda (los enlaces no publicados no salen).
        Inertia::share('footer', fn () => [
            'pages' => StorePage::publishedLinks($store),
            'address' => trim((string) $store->pickup_address) ?: null,
            'hours' => trim((string) $store->pickup_hours) ?: null,
            'map_url' => trim((string) $store->pickup_map_url) ?: null,
        ]);
    }

    public function terminate(Request $request, Response $response): void
    {
        app()->forgetInstance(self::CONTAINER_KEY);
    }
}
