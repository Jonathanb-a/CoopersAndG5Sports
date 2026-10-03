import { cn } from '@/lib/utils';
import { Search } from 'lucide-react';
import { useId } from 'react';

interface SearchFieldProps {
    className?: string;
}

/** Buscador del encabezado. Todavía no hace nada: el catálogo llega en otra fase. */
export function SearchField({ className }: SearchFieldProps) {
    const id = useId();

    return (
        <form role="search" onSubmit={(event) => event.preventDefault()} className={cn('store-surface flex items-center gap-2 px-3', className)}>
            <label htmlFor={id} className="sr-only">
                Buscar productos
            </label>
            <input
                id={id}
                type="search"
                placeholder="Buscar productos"
                className="font-store-body text-store-text placeholder:text-store-muted h-10 min-w-0 flex-1 bg-transparent text-sm outline-none"
            />
            <button type="submit" aria-label="Buscar" className="text-store-muted hover:text-store-text">
                <Search className="size-4" />
            </button>
        </form>
    );
}
