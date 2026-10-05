import { buttonVariants, StoreButton } from '@/components/store/button';
import { CatalogFilters } from '@/components/store/catalog-filters';
import { CatalogPager } from '@/components/store/catalog-pagination';
import { Container } from '@/components/store/container';
import { MobileMenu } from '@/components/store/mobile-menu';
import { ProductCard } from '@/components/store/product-card';
import { Reveal, ScrollReveal } from '@/components/store/reveal';
import { activeFilterCount, catalogUrl, EMPTY_FILTERS, SORT_OPTIONS } from '@/lib/catalog';
import { cn } from '@/lib/utils';
import StoreLayout from '@/Themes';
import { useTheme } from '@/Themes/context';
import { type CatalogOptions, type CatalogPagination, type CatalogFilters as Filters, type SharedData, type StoreProduct } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { SlidersHorizontal } from 'lucide-react';
import { useEffect, useId, useState } from 'react';

interface CatalogProps {
    category: { id: number; name: string; slug: string } | null;
    products: StoreProduct[];
    pagination: CatalogPagination;
    filters: Filters;
    options: CatalogOptions;
}

export default function Catalog(props: CatalogProps) {
    const { store } = usePage<SharedData>().props;
    const title = props.category?.name ?? 'Catálogo';

    return (
        <StoreLayout>
            <Head title={`${title} · ${store.name}`} />
            <CatalogContent {...props} />
        </StoreLayout>
    );
}

/** Catálogo de la tienda: filtros (columna en escritorio, panel deslizable en celular), orden, tarjetas y paginación. */
function CatalogContent({ category, products, pagination, filters, options }: CatalogProps) {
    const { categories } = usePage<SharedData>().props;
    const { root } = useTheme();
    const sortId = useId();
    const [panelOpen, setPanelOpen] = useState(false);
    const [loading, setLoading] = useState(false);

    const title = category?.name ?? 'Catálogo';
    const categorySlug = category?.slug ?? null;
    const activeCount = activeFilterCount(filters);
    const hasSearch = filters.q !== '';

    // Mientras llega el resultado, la lista se atenúa y lo anuncia como ocupada.
    useEffect(() => {
        const stopStart = router.on('start', () => setLoading(true));
        const stopFinish = router.on('finish', () => setLoading(false));

        return () => {
            stopStart();
            stopFinish();
        };
    }, []);

    /** Aplica un cambio de filtro u orden: la URL se actualiza y la página vuelve a la 1. */
    const change = (patch: Partial<Filters>) => {
        router.get(catalogUrl(categorySlug, { ...filters, ...patch }), {}, { preserveState: true, preserveScroll: true, replace: true });
    };

    const clearHref = catalogUrl(categorySlug, { ...EMPTY_FILTERS, q: filters.q });

    const filterPanel = (
        <CatalogFilters
            categories={categories}
            categorySlug={categorySlug}
            filters={filters}
            options={options}
            onChange={change}
            clearHref={clearHref}
        />
    );

    return (
        <Container as="section" className="py-8 sm:py-12 lg:py-16" aria-labelledby="titulo-catalogo">
            <Reveal className="mb-8 text-center sm:mb-12">
                <h1 id="titulo-catalogo" className="store-heading text-4xl sm:text-5xl lg:text-6xl">
                    {title}
                </h1>
                {hasSearch && (
                    <p className="text-store-muted mt-3">
                        Resultados para <span className="text-store-text font-semibold">“{filters.q}”</span>
                    </p>
                )}
            </Reveal>

            <div className="lg:grid lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-10">
                <aside aria-label="Filtros" className="hidden lg:block">
                    <div className="sticky top-32">{filterPanel}</div>
                </aside>

                <div>
                    <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                        <button
                            type="button"
                            onClick={() => setPanelOpen(true)}
                            className="rounded-store store-surface text-store-text focus-visible:outline-store-accent inline-flex h-11 items-center gap-2 px-4 text-sm font-semibold focus-visible:outline-2 lg:hidden"
                        >
                            <SlidersHorizontal className="size-4" aria-hidden="true" />
                            Filtros
                            {activeCount > 0 && (
                                <span className="bg-store-action text-store-action-text inline-flex size-5 items-center justify-center rounded-full text-xs">
                                    {activeCount}
                                </span>
                            )}
                        </button>

                        <p role="status" className="text-store-muted order-last w-full text-sm sm:order-none sm:w-auto">
                            {pagination.total === 0 ? 'Sin resultados' : `${pagination.total} ${pagination.total === 1 ? 'producto' : 'productos'}`}
                        </p>

                        <div className="flex items-center gap-2">
                            <label htmlFor={sortId} className="text-store-muted text-sm">
                                Ordenar por
                            </label>
                            <select
                                id={sortId}
                                value={filters.orden}
                                onChange={(event) => change({ orden: event.target.value })}
                                className="rounded-store border-store-border bg-store-surface text-store-text focus-visible:outline-store-accent h-11 border-[length:var(--store-border-width)] px-3 text-sm focus-visible:outline-2"
                            >
                                {SORT_OPTIONS.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>

                    {products.length > 0 ? (
                        <div
                            aria-busy={loading}
                            className={cn(
                                'grid grid-cols-2 gap-3 transition-opacity sm:grid-cols-3 sm:gap-6 xl:grid-cols-4',
                                loading && 'opacity-60',
                            )}
                        >
                            {products.map((product, position) => (
                                <ScrollReveal key={product.id} delay={(position % 4) * 0.05}>
                                    <ProductCard
                                        name={product.name}
                                        brand={product.brand}
                                        price={product.price}
                                        imageUrl={product.image_url}
                                        tag={product.tag}
                                    />
                                </ScrollReveal>
                            ))}
                        </div>
                    ) : (
                        <EmptyResults filters={filters} categoryName={category?.name ?? null} clearHref={clearHref} />
                    )}

                    <CatalogPager pagination={pagination} className="mt-10 sm:mt-14" />
                </div>
            </div>

            <MobileMenu open={panelOpen} onOpenChange={setPanelOpen} container={root} title="Filtros" className="overflow-y-auto">
                <p className="store-heading mt-2 mb-6 text-3xl">Filtros</p>
                <div className="flex-1 overflow-y-auto">{filterPanel}</div>
                <StoreButton onClick={() => setPanelOpen(false)} className="mt-6 w-full">
                    {pagination.total === 0 ? 'Ver filtros' : `Ver ${pagination.total} ${pagination.total === 1 ? 'producto' : 'productos'}`}
                </StoreButton>
            </MobileMenu>
        </Container>
    );
}

function EmptyResults({ filters, categoryName, clearHref }: { filters: Filters; categoryName: string | null; clearHref: string }) {
    const filtered = activeFilterCount(filters) > 0;
    const searched = filters.q !== '';

    return (
        <div role="status" className="store-surface px-6 py-14 text-center sm:py-20">
            <h2 className="store-heading text-3xl sm:text-4xl">No encontramos productos</h2>
            <p className="text-store-muted mx-auto mt-3 max-w-md">
                {searched
                    ? `No hay resultados para “${filters.q}”${filtered ? ' con los filtros elegidos' : ''}. Revisa cómo escribiste el nombre o prueba con otra palabra.`
                    : filtered
                      ? 'Ningún producto cumple con los filtros elegidos. Prueba quitando alguno.'
                      : categoryName
                        ? `Por ahora no hay productos en ${categoryName}.`
                        : 'Por ahora no hay productos en el catálogo.'}
            </p>
            <div className="mt-8 flex flex-wrap justify-center gap-3">
                {filtered && (
                    <Link href={clearHref} replace className={buttonVariants()}>
                        Quitar filtros
                    </Link>
                )}
                {(searched || categoryName !== null) && (
                    <Link href="/catalogo" className={buttonVariants({ variant: 'outline' })}>
                        Ver todo el catálogo
                    </Link>
                )}
            </div>
        </div>
    );
}
