<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveStore;
use App\Models\Store;
use App\Models\StorePage;
use App\Support\SafeHtml;
use Inertia\Inertia;
use Inertia\Response;

/** Página de contenido o legal: /pagina/{slug}. Solo publicada y de esta tienda; cualquier otra responde 404. */
class StorePageController extends Controller
{
    public function __invoke(string $slug): Response
    {
        /** @var Store $store */
        $store = app(ResolveStore::CONTAINER_KEY);

        $page = StorePage::query()
            ->where('store_id', $store->id)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        // Se sanea otra vez al mostrar, por si el contenido llegó a la base sin pasar por el modelo.
        $html = SafeHtml::clean($page->body);
        $title = "{$page->title} · {$store->name}";
        $description = SafeHtml::excerpt($html) ?: "{$page->title} de {$store->name}.";

        return Inertia::render('store-page', [
            'page' => ['slug' => $page->slug, 'title' => $page->title, 'html' => $html],
            'meta' => ['title' => $title, 'description' => $description],
        ])->withViewData(['meta' => [
            'type' => 'website',
            'title' => $title,
            'description' => $description,
            'site_name' => $store->name,
            'url' => url($page->url()),
            'image' => null,
        ]]);
    }
}
