import { type CatalogFilters } from '@/types';

export const DEFAULT_SORT = 'nuevos';

export const SORT_OPTIONS = [
    { value: 'nuevos', label: 'Más nuevos' },
    { value: 'precio-menor', label: 'Precio: de menor a mayor' },
    { value: 'precio-mayor', label: 'Precio: de mayor a menor' },
] as const;

/** Filtros vacíos: lo que se ve en /catalogo sin nada en la URL. */
export const EMPTY_FILTERS: CatalogFilters = { q: '', marca: [], talla: [], disponibles: false, orden: DEFAULT_SORT };

/**
 * Dirección del catálogo con sus filtros: /catalogo?marca[]=nike&talla[]=27 o /categoria/{slug}?...
 * Lo que está en su valor de siempre no se escribe, para que los enlaces queden cortos.
 */
export function catalogUrl(categorySlug: string | null, filters: CatalogFilters): string {
    const params = new URLSearchParams();

    if (filters.q) params.set('q', filters.q);
    filters.marca.forEach((brand) => params.append('marca[]', brand));
    filters.talla.forEach((size) => params.append('talla[]', size));
    if (filters.disponibles) params.set('disponibles', '1');
    if (filters.orden !== DEFAULT_SORT) params.set('orden', filters.orden);

    const path = categorySlug ? `/categoria/${encodeURIComponent(categorySlug)}` : '/catalogo';
    const query = params.toString();

    return query ? `${path}?${query}` : path;
}

/** Cuántos filtros hay puestos (el orden no cuenta). */
export function activeFilterCount(filters: CatalogFilters): number {
    return filters.marca.length + filters.talla.length + (filters.disponibles ? 1 : 0);
}
