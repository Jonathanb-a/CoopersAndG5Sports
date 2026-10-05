<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveStore;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Support\ProductCard;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de la tienda (/catalogo y /categoria/{slug}).
 * Filtros, orden y página viven en la URL: ?q=&marca[]=&talla[]=&disponibles=1&orden=&pagina=
 */
class CatalogController extends Controller
{
    private const PER_PAGE = 12;

    private const DEFAULT_SORT = 'nuevos';

    private const SORTS = ['nuevos', 'precio-menor', 'precio-mayor'];

    /** Orden de las tallas en letras; las numéricas van primero, de menor a mayor. */
    private const LETTER_SIZES = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'];

    public function __invoke(Request $request, ?string $slug = null): Response|RedirectResponse
    {
        /** @var Store $store */
        $store = app(ResolveStore::CONTAINER_KEY);

        $category = $slug === null ? null : Category::query()
            ->where('store_id', $store->id)
            ->where('slug', $slug)
            ->firstOrFail();

        // Todo lo que la tienda puede mostrar (de la categoría, si hay): de aquí salen las opciones de filtro.
        $scope = Product::query()
            ->where('products.store_id', $store->id)
            ->where('products.is_active', true)
            ->when($category, fn (Builder $query) => $query->where('products.category_id', $category->id));

        $filters = $this->filters($request);

        $products = $this->filtered(clone $scope, $filters)
            ->with(['images', 'brand'])
            ->withSum('variants as total_stock', 'stock')
            ->paginate(self::PER_PAGE, ['*'], 'pagina')
            ->withQueryString();

        // Una página que ya no existe (enlace viejo) lleva a la última.
        if ($products->lastPage() > 0 && $products->currentPage() > $products->lastPage()) {
            return redirect($products->url($products->lastPage()));
        }

        return Inertia::render('catalog', [
            'category' => $category?->only(['id', 'name', 'slug']),
            'products' => $products->getCollection()->map(fn (Product $product) => ProductCard::from($product))->all(),
            'pagination' => $this->pagination($products),
            'filters' => $filters,
            'options' => [
                'brands' => $this->brandOptions($scope),
                'sizes' => $this->sizeOptions($scope),
            ],
        ]);
    }

    /** Filtros leídos de la URL; lo que no se entiende se ignora. */
    private function filters(Request $request): array
    {
        $sort = $request->query('orden');
        $search = $request->query('q');

        return [
            'q' => mb_substr(trim(is_string($search) ? $search : ''), 0, 100),
            'marca' => $this->strings($request->query('marca')),
            'talla' => $this->strings($request->query('talla')),
            'disponibles' => $request->boolean('disponibles'),
            'orden' => in_array($sort, self::SORTS, true) ? $sort : self::DEFAULT_SORT,
        ];
    }

    private function filtered(Builder $query, array $filters): Builder
    {
        if ($filters['q'] !== '') {
            // % y _ del texto buscado se toman como letras; "!" es el carácter de escape (igual en MySQL y SQLite).
            $like = preg_replace('/[!%_]/', '!$0', $filters['q']);
            $query->whereRaw("products.name like ? escape '!'", ["%{$like}%"]);
        }

        if ($filters['marca']) {
            $query->whereIn('products.brand_id', Brand::query()->whereIn('slug', $filters['marca'])->select('id'));
        }

        if ($filters['talla']) {
            $query->whereHas('variants', fn (Builder $variants) => $variants->whereIn('size', $filters['talla']));
        }

        // Disponible = con piezas, o sobre pedido. Si hay tallas elegidas, las piezas tienen que ser de esas tallas.
        if ($filters['disponibles']) {
            $query->where(fn (Builder $available) => $available
                ->where('products.allow_backorder', true)
                ->orWhereHas('variants', fn (Builder $variants) => $variants
                    ->where('stock', '>', 0)
                    ->when($filters['talla'], fn (Builder $inSizes) => $inSizes->whereIn('size', $filters['talla']))));
        }

        return match ($filters['orden']) {
            'precio-menor' => $query->orderBy('products.price')->orderByDesc('products.id'),
            'precio-mayor' => $query->orderByDesc('products.price')->orderByDesc('products.id'),
            default => $query->orderByDesc('products.created_at')->orderByDesc('products.id'),
        };
    }

    /** Marcas de los productos de la tienda (o de la categoría). */
    private function brandOptions(Builder $scope): array
    {
        return Brand::query()
            ->whereIn('id', (clone $scope)->whereNotNull('products.brand_id')->select('products.brand_id'))
            ->orderBy('name')
            ->get(['name', 'slug'])
            ->all();
    }

    /** Tallas que existen en los productos de la tienda (o de la categoría): 25, 26.5, 28, S, M, L... */
    private function sizeOptions(Builder $scope): array
    {
        return ProductVariant::query()
            ->whereIn('product_id', (clone $scope)->select('products.id'))
            ->distinct()
            ->pluck('size')
            ->sort(fn (string $a, string $b) => $this->sizeRank($a) <=> $this->sizeRank($b))
            ->values()
            ->all();
    }

    /** @return array{0: int, 1: float|int|string} */
    private function sizeRank(string $size): array
    {
        if (is_numeric($size)) {
            return [0, (float) $size];
        }

        $letter = array_search(strtoupper($size), self::LETTER_SIZES, true);

        return $letter === false ? [2, mb_strtolower($size)] : [1, $letter];
    }

    /** Números de página con puntos suspensivos: 1 … 4 5 6 … 20 */
    private function pagination(LengthAwarePaginator $paginator): array
    {
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();

        $pages = collect([1, $last, $current - 1, $current, $current + 1])
            ->filter(fn (int $page) => $page >= 1 && $page <= $last)
            ->unique()
            ->sort()
            ->values();

        $items = [];
        $previous = 0;

        foreach ($pages as $page) {
            if ($page - $previous > 1) {
                $items[] = null;
            }
            $items[] = ['page' => $page, 'url' => $paginator->url($page)];
            $previous = $page;
        }

        return [
            'current' => $current,
            'last' => $last,
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'prev_url' => $paginator->previousPageUrl(),
            'next_url' => $paginator->nextPageUrl(),
            'items' => $items,
        ];
    }

    /** Lista de textos sin vacíos ni repetidos (acepta ?marca=a o ?marca[]=a). */
    private function strings(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn ($item) => trim($item))
            ->unique()
            ->take(30)
            ->values()
            ->all();
    }
}
