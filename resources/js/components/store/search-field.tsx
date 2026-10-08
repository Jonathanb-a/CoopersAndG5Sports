import { catalogUrl, EMPTY_FILTERS } from '@/lib/catalog';
import { cn } from '@/lib/utils';
import { type CatalogFilters } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { type FormEvent, useEffect, useId, useState } from 'react';

interface SearchFieldProps {
    className?: string;
}

/** Buscador del encabezado: lleva al catálogo con ?q=... y muestra lo que se está buscando. */
export function SearchField({ className }: SearchFieldProps) {
    const id = useId();
    const current = usePage<{ filters?: CatalogFilters }>().props.filters?.q ?? '';
    const [term, setTerm] = useState(current);

    // Si la página cambia el término (otro enlace, atrás/adelante), el campo lo sigue.
    useEffect(() => setTerm(current), [current]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.get(catalogUrl(null, { ...EMPTY_FILTERS, q: term.trim() }));
    };

    return (
        <form role="search" onSubmit={submit} className={cn('store-surface flex items-center gap-2 px-3', className)}>
            <label htmlFor={id} className="sr-only">
                Buscar productos
            </label>
            <input
                id={id}
                type="search"
                name="q"
                value={term}
                onChange={(event) => setTerm(event.target.value)}
                maxLength={100}
                placeholder="Buscar productos"
                className="font-store-body text-store-text placeholder:text-store-muted h-11 min-w-0 flex-1 bg-transparent text-base outline-none"
            />
            <button
                type="submit"
                aria-label="Buscar"
                className="text-store-muted hover:text-store-text -mr-3 inline-flex size-11 shrink-0 items-center justify-center"
            >
                <Search className="size-4" />
            </button>
        </form>
    );
}
