import { cn } from '@/lib/utils';
import { type CatalogPagination } from '@/types';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

const box =
    'rounded-store font-store-body focus-visible:outline-store-accent inline-flex h-11 min-w-11 items-center justify-center border-[length:var(--store-border-width)] px-3 text-sm font-semibold transition-colors focus-visible:outline-2';

/** Paginación del catálogo: anterior, números con puntos suspensivos y siguiente. */
export function CatalogPager({ pagination, className }: { pagination: CatalogPagination; className?: string }) {
    if (pagination.last <= 1) {
        return null;
    }

    return (
        <nav aria-label="Paginación" className={cn('flex flex-wrap items-center justify-center gap-2', className)}>
            {pagination.prev_url ? (
                <Link
                    href={pagination.prev_url}
                    rel="prev"
                    aria-label="Página anterior"
                    className={cn(box, 'border-store-border bg-store-surface hover:border-store-text')}
                >
                    <ChevronLeft className="size-4" />
                </Link>
            ) : (
                <span aria-hidden="true" className={cn(box, 'border-store-border text-store-muted opacity-50')}>
                    <ChevronLeft className="size-4" />
                </span>
            )}

            <ul className="flex flex-wrap items-center justify-center gap-2">
                {pagination.items.map((item, position) =>
                    item === null ? (
                        <li key={`gap-${position}`} aria-hidden="true" className="text-store-muted px-1">
                            …
                        </li>
                    ) : (
                        <li key={item.page}>
                            <Link
                                href={item.url}
                                aria-label={`Página ${item.page}`}
                                aria-current={item.page === pagination.current ? 'page' : undefined}
                                className={cn(
                                    box,
                                    item.page === pagination.current
                                        ? 'border-store-action bg-store-action text-store-action-text'
                                        : 'border-store-border bg-store-surface hover:border-store-text',
                                )}
                            >
                                {item.page}
                            </Link>
                        </li>
                    ),
                )}
            </ul>

            {pagination.next_url ? (
                <Link
                    href={pagination.next_url}
                    rel="next"
                    aria-label="Página siguiente"
                    className={cn(box, 'border-store-border bg-store-surface hover:border-store-text')}
                >
                    <ChevronRight className="size-4" />
                </Link>
            ) : (
                <span aria-hidden="true" className={cn(box, 'border-store-border text-store-muted opacity-50')}>
                    <ChevronRight className="size-4" />
                </span>
            )}
        </nav>
    );
}
