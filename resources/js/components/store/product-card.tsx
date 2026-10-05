import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Tag, type TagKind } from './tag';

interface ProductCardProps {
    name: string;
    price: number | string;
    brand?: string | null;
    imageUrl?: string | null;
    tag?: TagKind | null;
    href?: string;
    className?: string;
}

/**
 * Tarjeta de producto: foto, marca, nombre, precio y etiqueta opcional; el estilo lo pone el tema.
 * Las fotos son chicas, así que van sin estirarse (nunca más grandes que su tamaño real), enteras y
 * centradas en un recuadro del mismo alto en todas las tarjetas.
 */
export function ProductCard({ name, price, brand, imageUrl, tag, href, className }: ProductCardProps) {
    const body = (
        <>
            <div className="bg-store-photo relative aspect-square overflow-hidden">
                {imageUrl ? (
                    <img src={imageUrl} alt={name} loading="lazy" className="absolute inset-0 m-auto max-h-full max-w-full object-contain" />
                ) : (
                    <div className="store-heading text-store-muted absolute inset-0 flex items-center justify-center text-6xl" aria-hidden="true">
                        {name.charAt(0)}
                    </div>
                )}
                {tag && <Tag kind={tag} className="absolute top-2 left-2" />}
            </div>
            <div className="border-store-border flex flex-1 flex-col border-t p-3 sm:p-4">
                {brand && <p className="font-store-body text-store-muted truncate text-xs font-semibold tracking-wider uppercase">{brand}</p>}
                <h3 className="font-store-body text-store-text mt-0.5 line-clamp-2 min-h-[2lh] text-sm font-medium sm:text-base">{name}</h3>
                <p className="font-store-body text-store-text mt-auto pt-2 text-base font-bold">{formatMoney(price)}</p>
            </div>
        </>
    );

    return (
        <article className={cn('store-surface store-transition store-lift flex h-full flex-col overflow-hidden', className)}>
            {href ? (
                <Link
                    href={href}
                    className="focus-visible:outline-store-accent flex flex-1 flex-col focus-visible:outline-2 focus-visible:-outline-offset-2"
                >
                    {body}
                </Link>
            ) : (
                body
            )}
        </article>
    );
}
