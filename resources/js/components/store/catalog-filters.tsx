import { activeFilterCount, catalogUrl } from '@/lib/catalog';
import { cn } from '@/lib/utils';
import { type CatalogFilters, type CatalogOptions, type StoreCategory } from '@/types';
import { Link } from '@inertiajs/react';
import { type ReactNode, useId } from 'react';

interface CatalogFiltersProps {
    categories: StoreCategory[];
    categorySlug: string | null;
    filters: CatalogFilters;
    options: CatalogOptions;
    onChange: (patch: Partial<CatalogFilters>) => void;
    /** Dirección que quita todos los filtros (conserva la categoría y la búsqueda). */
    clearHref: string;
    className?: string;
}

/** Agrega o quita un valor de una lista. */
function toggle(list: string[], value: string): string[] {
    return list.includes(value) ? list.filter((item) => item !== value) : [...list, value];
}

function Group({ title, children }: { title: string; children: ReactNode }) {
    return (
        <fieldset className="border-store-border border-t pt-5 first:border-t-0 first:pt-0">
            <legend className="store-heading mb-3 text-xl">{title}</legend>
            {children}
        </fieldset>
    );
}

/**
 * Filtros del catálogo: categoría, marca, talla y «solo disponibles».
 * Cada cambio se aplica al instante (la URL guarda el resultado). Se usa igual en la columna de
 * escritorio y en el panel deslizable del celular.
 */
export function CatalogFilters({ categories, categorySlug, filters, options, onChange, clearHref, className }: CatalogFiltersProps) {
    const id = useId();
    const link = (active: boolean) =>
        cn(
            'focus-visible:outline-store-accent flex min-h-11 items-center border-l-2 pl-3 text-sm transition-colors focus-visible:outline-2',
            active ? 'border-store-accent text-store-text font-bold' : 'text-store-muted hover:text-store-text border-transparent',
        );

    return (
        <div className={cn('flex flex-col gap-5', className)}>
            <Group title="Categoría">
                <ul>
                    <li>
                        <Link
                            href={catalogUrl(null, filters)}
                            preserveScroll
                            preserveState
                            aria-current={categorySlug === null ? 'page' : undefined}
                            className={link(categorySlug === null)}
                        >
                            Todo el catálogo
                        </Link>
                    </li>
                    {categories.map((category) => (
                        <li key={category.id}>
                            <Link
                                href={catalogUrl(category.slug, filters)}
                                preserveScroll
                                preserveState
                                aria-current={categorySlug === category.slug ? 'page' : undefined}
                                className={link(categorySlug === category.slug)}
                            >
                                {category.name}
                            </Link>
                        </li>
                    ))}
                </ul>
            </Group>

            <Group title="Disponibilidad">
                <label htmlFor={`${id}-disponibles`} className="flex min-h-11 cursor-pointer items-center gap-3 text-sm">
                    <input
                        id={`${id}-disponibles`}
                        type="checkbox"
                        checked={filters.disponibles}
                        onChange={(event) => onChange({ disponibles: event.target.checked })}
                        className="accent-store-action size-5 shrink-0"
                    />
                    Solo disponibles
                </label>
            </Group>

            {options.brands.length > 0 && (
                <Group title="Marca">
                    <ul>
                        {options.brands.map((brand) => (
                            <li key={brand.slug}>
                                <label htmlFor={`${id}-marca-${brand.slug}`} className="flex min-h-11 cursor-pointer items-center gap-3 text-sm">
                                    <input
                                        id={`${id}-marca-${brand.slug}`}
                                        type="checkbox"
                                        checked={filters.marca.includes(brand.slug)}
                                        onChange={() => onChange({ marca: toggle(filters.marca, brand.slug) })}
                                        className="accent-store-action size-5 shrink-0"
                                    />
                                    {brand.name}
                                </label>
                            </li>
                        ))}
                    </ul>
                </Group>
            )}

            {options.sizes.length > 0 && (
                <Group title="Talla">
                    <ul className="flex flex-wrap gap-2">
                        {options.sizes.map((size) => {
                            const selected = filters.talla.includes(size);

                            return (
                                <li key={size}>
                                    <button
                                        type="button"
                                        aria-pressed={selected}
                                        onClick={() => onChange({ talla: toggle(filters.talla, size) })}
                                        className={cn(
                                            'rounded-store font-store-body focus-visible:outline-store-accent h-11 min-w-11 border-[length:var(--store-border-width)] px-3 text-sm font-semibold transition-colors focus-visible:outline-2',
                                            selected
                                                ? 'border-store-action bg-store-action text-store-action-text'
                                                : 'border-store-border bg-store-surface text-store-text hover:border-store-text',
                                        )}
                                    >
                                        {size}
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </Group>
            )}

            {activeFilterCount(filters) > 0 && (
                <Link
                    href={clearHref}
                    preserveScroll
                    preserveState
                    className="text-store-link focus-visible:outline-store-accent self-start text-sm font-medium underline underline-offset-4 focus-visible:outline-2"
                >
                    Quitar filtros
                </Link>
            )}
        </div>
    );
}
