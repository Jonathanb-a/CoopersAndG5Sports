import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';
import { useTheme } from '@/Themes/context';
import { cssTransition } from '@/Themes/motion';
import { useMotion } from '@/Themes/use-motion';
import { Link } from '@inertiajs/react';
import { type CSSProperties } from 'react';
import { Tag, type TagKind } from './tag';

interface ProductCardProps {
    name: string;
    price: number | string;
    brand?: string | null;
    imageUrl?: string | null;
    /** Segunda foto: en temas con movimiento, la tarjeta cambia a ella al pasar el cursor. */
    secondImageUrl?: string | null;
    tag?: TagKind | null;
    href?: string;
    className?: string;
}

/**
 * Tarjeta de producto: foto, marca, nombre, precio y etiqueta opcional; el estilo lo pone el tema.
 * Las fotos son chicas, así que van sin estirarse (nunca más grandes que su tamaño real), enteras y
 * centradas en un recuadro del mismo alto en todas las tarjetas.
 */
export function ProductCard({ name, price, brand, imageUrl, secondImageUrl, tag, href, className }: ProductCardProps) {
    const { motionSet } = useTheme();
    const active = useMotion();
    // Solo con hover real (no en celular) y sin movimiento reducido: el zoom y la segunda foto son del tema.
    const hover = motionSet && active && !active.reduced && !active.mobile ? motionSet : null;
    const second = hover && imageUrl && secondImageUrl ? secondImageUrl : null;
    // Extras del carácter del tema (G5): inclinación y elevación de la tarjeta, y la etiqueta que se endereza.
    const { tilt, lift, tagStraighten } = hover?.card ?? {};

    const body = (
        <>
            <div className="bg-store-photo relative aspect-square overflow-hidden">
                {imageUrl ? (
                    <>
                        <img
                            src={imageUrl}
                            alt={name}
                            loading="lazy"
                            data-photo="main"
                            className={cn(
                                'absolute inset-0 m-auto max-h-full max-w-full object-contain',
                                hover && 'will-change-transform group-hover/card:scale-(--card-zoom)',
                                second && 'group-hover/card:opacity-0',
                            )}
                            style={
                                hover
                                    ? ({
                                          ...cssTransition(hover, hover.card.duration, 'transform, opacity'),
                                          '--card-zoom': String(hover.card.scale),
                                      } as CSSProperties)
                                    : undefined
                            }
                        />
                        {second && hover && (
                            <img
                                src={second}
                                alt=""
                                loading="lazy"
                                aria-hidden="true"
                                data-photo="second"
                                className="absolute inset-0 m-auto max-h-full max-w-full object-contain opacity-0 group-hover/card:opacity-100"
                                style={cssTransition(hover, hover.card.duration, 'opacity')}
                            />
                        )}
                    </>
                ) : (
                    <div className="store-heading text-store-muted absolute inset-0 flex items-center justify-center text-6xl" aria-hidden="true">
                        {name.charAt(0)}
                    </div>
                )}
                {tag && (
                    <Tag
                        kind={tag}
                        className={cn('absolute top-2 left-2', tagStraighten !== undefined && 'group-hover/card:rotate-(--tag-undo)')}
                        style={
                            tagStraighten !== undefined && hover
                                ? ({ '--tag-undo': `${tagStraighten}deg`, ...cssTransition(hover, hover.card.duration, 'rotate') } as CSSProperties)
                                : undefined
                        }
                    />
                )}
            </div>
            <div className="border-store-border flex flex-1 flex-col border-t p-3 sm:p-4">
                {brand && <p className="font-store-body text-store-muted truncate text-xs font-semibold tracking-wider uppercase">{brand}</p>}
                <h3 className="font-store-body text-store-text mt-0.5 line-clamp-2 min-h-[2lh] text-sm font-medium sm:text-base">{name}</h3>
                <p className="font-store-body text-store-text mt-auto pt-2 text-base font-bold">{formatMoney(price)}</p>
            </div>
        </>
    );

    return (
        <article
            className={cn(
                'group/card store-surface store-transition store-lift flex h-full flex-col overflow-hidden',
                tilt !== undefined && 'group-hover/card:rotate-(--card-tilt)',
                lift !== undefined && 'group-hover/card:-translate-y-(--card-lift)',
                className,
            )}
            style={
                tilt !== undefined || lift !== undefined
                    ? ({
                          '--card-tilt': `${tilt ?? 0}deg`,
                          '--card-lift': `${lift ?? 0}px`,
                          // Se suman a las que ya anima el tema, sin quitarlas.
                          transitionProperty: 'color, background-color, border-color, box-shadow, transform, opacity, rotate, translate',
                      } as CSSProperties)
                    : undefined
            }
        >
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
