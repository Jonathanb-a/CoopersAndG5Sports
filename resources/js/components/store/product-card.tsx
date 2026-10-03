import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';
import { Tag, type TagKind } from './tag';

interface ProductCardProps {
    name: string;
    price: number | string;
    imageUrl?: string | null;
    tag?: TagKind | null;
    href?: string;
    className?: string;
}

/** Tarjeta de producto. Foto grande, nombre, precio y etiqueta opcional; el estilo lo pone el tema. */
export function ProductCard({ name, price, imageUrl, tag, href, className }: ProductCardProps) {
    const body = (
        <>
            <div className="bg-store-bg relative aspect-[4/5] overflow-hidden">
                {imageUrl ? (
                    <img src={imageUrl} alt={name} loading="lazy" className="store-transition size-full object-cover group-hover:scale-105" />
                ) : (
                    <div className="store-heading text-store-muted flex size-full items-center justify-center text-6xl" aria-hidden="true">
                        {name.charAt(0)}
                    </div>
                )}
                {tag && <Tag kind={tag} className="absolute top-2 left-2" />}
            </div>
            <div className="p-3 sm:p-4">
                <h3 className="font-store-body text-store-text line-clamp-2 text-sm font-medium sm:text-base">{name}</h3>
                <p className="font-store-body text-store-text mt-1 text-base font-bold">{formatMoney(price)}</p>
            </div>
        </>
    );

    return (
        <article className={cn('group store-surface store-transition store-lift overflow-hidden', className)}>
            {href ? (
                <a href={href} className="focus-visible:outline-store-accent block focus-visible:outline-2 focus-visible:-outline-offset-2">
                    {body}
                </a>
            ) : (
                body
            )}
        </article>
    );
}
