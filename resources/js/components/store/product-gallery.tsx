import { cn } from '@/lib/utils';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useReducedMotion } from 'motion/react';
import { useRef, useState } from 'react';

interface ProductGalleryProps {
    images: string[];
    name: string;
    className?: string;
}

/**
 * Galería de fotos: foto grande con deslizamiento táctil (scroll nativo con snap) y miniaturas.
 * Las fotos son chicas: van enteras y sin estirarse (nunca más grandes que su tamaño real), centradas
 * en un recuadro cuadrado sobre fondo blanco.
 */
export function ProductGallery({ images, name, className }: ProductGalleryProps) {
    const track = useRef<HTMLDivElement>(null);
    const [index, setIndex] = useState(0);
    const reduceMotion = useReducedMotion();
    const count = images.length;

    const goTo = (target: number) => {
        const element = track.current;

        if (!element || count === 0) {
            return;
        }

        const next = Math.min(Math.max(target, 0), count - 1);
        element.scrollTo({ left: next * element.clientWidth, behavior: reduceMotion ? 'auto' : 'smooth' });
        setIndex(next);
    };

    // El índice sigue al scroll: sirve igual para el dedo, las flechas y las miniaturas.
    const onScroll = () => {
        const element = track.current;

        if (element && element.clientWidth > 0) {
            setIndex(Math.round(element.scrollLeft / element.clientWidth));
        }
    };

    if (count === 0) {
        return (
            <div className={cn('store-surface bg-store-photo flex aspect-square items-center justify-center', className)}>
                <span className="store-heading text-store-muted text-8xl" aria-hidden="true">
                    {name.charAt(0)}
                </span>
                <span className="sr-only">Este producto todavía no tiene fotos.</span>
            </div>
        );
    }

    const arrow =
        'store-surface absolute top-1/2 z-10 hidden size-11 -translate-y-1/2 items-center justify-center text-store-text opacity-90 hover:opacity-100 focus-visible:outline-2 focus-visible:outline-store-accent disabled:hidden sm:inline-flex';

    return (
        <div className={className}>
            <section role="region" aria-roledescription="galería" aria-label={`Fotos de ${name}`} className="store-surface relative overflow-hidden">
                <div
                    ref={track}
                    onScroll={onScroll}
                    className="flex snap-x snap-mandatory overflow-x-auto overscroll-x-contain [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                >
                    {images.map((src, position) => (
                        <div
                            key={src}
                            role="group"
                            aria-roledescription="foto"
                            aria-label={`${position + 1} de ${count}`}
                            className="bg-store-photo relative aspect-square w-full shrink-0 snap-center"
                        >
                            <img
                                src={src}
                                alt={position === 0 ? name : `${name}, foto ${position + 1}`}
                                draggable={false}
                                loading={position === 0 ? 'eager' : 'lazy'}
                                className="absolute inset-0 m-auto max-h-full max-w-full object-contain"
                            />
                        </div>
                    ))}
                </div>

                {count > 1 && (
                    <>
                        <button
                            type="button"
                            aria-label="Foto anterior"
                            disabled={index === 0}
                            onClick={() => goTo(index - 1)}
                            className={cn(arrow, 'left-3')}
                        >
                            <ChevronLeft className="size-5" />
                        </button>
                        <button
                            type="button"
                            aria-label="Foto siguiente"
                            disabled={index === count - 1}
                            onClick={() => goTo(index + 1)}
                            className={cn(arrow, 'right-3')}
                        >
                            <ChevronRight className="size-5" />
                        </button>
                        <p
                            className="bg-store-bg/85 text-store-text absolute right-3 bottom-3 rounded-full px-2.5 py-0.5 text-xs font-semibold backdrop-blur sm:hidden"
                            aria-hidden="true"
                        >
                            {index + 1} / {count}
                        </p>
                    </>
                )}
            </section>

            {count > 1 && (
                <ul className="mt-3 flex gap-2 overflow-x-auto pb-1" aria-label="Miniaturas">
                    {images.map((src, position) => (
                        <li key={src} className="shrink-0">
                            <button
                                type="button"
                                aria-label={`Ver foto ${position + 1}`}
                                aria-current={position === index ? 'true' : undefined}
                                onClick={() => goTo(position)}
                                className={cn(
                                    'bg-store-photo rounded-store focus-visible:outline-store-accent relative block size-16 overflow-hidden border-[length:var(--store-border-width)] transition-colors focus-visible:outline-2 sm:size-20',
                                    position === index ? 'border-store-accent' : 'border-store-border opacity-70 hover:opacity-100',
                                )}
                            >
                                <img src={src} alt="" loading="lazy" className="absolute inset-0 m-auto max-h-full max-w-full object-contain" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
