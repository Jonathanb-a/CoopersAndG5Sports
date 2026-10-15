<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Support\SafeHtml;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Página de contenido o legal de una tienda (/pagina/{slug}). Solo las publicadas se ven en la tienda.
 * El HTML se sanea al guardar y otra vez al mostrar (SafeHtml).
 */
class StorePage extends Model
{
    use BelongsToStore;

    /** Las páginas que tiene cada tienda, en el orden del pie de página: slug => título por defecto. */
    public const PAGES = [
        'quienes-somos' => 'Quiénes somos',
        'contacto' => 'Contacto',
        'preguntas-frecuentes' => 'Preguntas frecuentes',
        'envios-y-entregas' => 'Envíos y entregas',
        'cambios-y-devoluciones' => 'Cambios y devoluciones',
        'aviso-de-privacidad' => 'Aviso de privacidad',
        'terminos-y-condiciones' => 'Términos y condiciones',
    ];

    public const PRIVACY = 'aviso-de-privacidad';

    public const TERMS = 'terminos-y-condiciones';

    protected $fillable = ['store_id', 'slug', 'title', 'body', 'internal_note', 'is_published'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (StorePage $page) {
            $page->body = SafeHtml::clean($page->body);
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function url(): string
    {
        return "/pagina/{$this->slug}";
    }

    /**
     * Páginas publicadas de la tienda para el pie de página y el consentimiento, en el orden de PAGES.
     *
     * @return list<array{slug: string, title: string, url: string}>
     */
    public static function publishedLinks(Store $store): array
    {
        $order = array_flip(array_keys(self::PAGES));

        return self::query()
            ->where('store_id', $store->id)
            ->where('is_published', true)
            ->get(['slug', 'title'])
            ->sortBy(fn (StorePage $page) => $order[$page->slug] ?? 99)
            ->map(fn (StorePage $page) => ['slug' => $page->slug, 'title' => $page->title, 'url' => $page->url()])
            ->values()
            ->all();
    }
}
