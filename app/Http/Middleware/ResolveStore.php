<?php

namespace App\Http\Middleware;

use App\Models\Category;
use App\Models\Store;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ResolveStore
{
    public const CONTAINER_KEY = 'currentStore';

    public function handle(Request $request, Closure $next): Response
    {
        $store = Store::query()
            ->where('domain', strtolower($request->getHost()))
            ->where('is_active', true)
            ->first();

        abort_if($store === null, 404);

        app()->instance(self::CONTAINER_KEY, $store);

        Inertia::share('store', [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'theme' => $store->theme,
            'whatsapp_phone' => $store->whatsapp_phone,
        ]);

        // Menú de categorías: solo las que tienen al menos un producto activo (y sin borrar).
        Inertia::share('categories', fn () => Category::query()
            ->where('categories.store_id', $store->id)
            ->whereHas('products', fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get(['categories.id', 'categories.name', 'categories.slug']));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        app()->forgetInstance(self::CONTAINER_KEY);
    }
}
