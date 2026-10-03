import { cn } from '@/lib/utils';
import { type StoreBanner } from '@/types';
import { ChevronLeft, ChevronRight, Pause, Play } from 'lucide-react';
import { useReducedMotion } from 'motion/react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface BannerCarouselProps {
    banners: StoreBanner[];
    /** Milisegundos entre banners. */
    interval?: number;
    className?: string;
}

/**
 * Carrusel de banners. Usa scroll nativo con snap, así el deslizamiento táctil funciona sin código.
 * Avanza solo, se detiene al pasar el cursor o enfocar (y con el botón de pausa) y no avanza solo
 * con prefers-reduced-motion. Con un solo banner no muestra flechas, puntos ni pausa.
 */
export function BannerCarousel({ banners, interval = 6000, className }: BannerCarouselProps) {
    const track = useRef<HTMLDivElement>(null);
    const [index, setIndex] = useState(0);
    const [paused, setPaused] = useState(false);
    const [engaged, setEngaged] = useState(false);
    const reduceMotion = useReducedMotion();

    const count = banners.length;
    const autoplay = count > 1 && !paused && !engaged && !reduceMotion;

    const goTo = useCallback(
        (target: number) => {
            const element = track.current;

            if (!element || count === 0) {
                return;
            }

            const next = (target + count) % count;
            element.scrollTo({ left: next * element.clientWidth, behavior: reduceMotion ? 'auto' : 'smooth' });
        },
        [count, reduceMotion],
    );

    // El índice sigue al scroll: sirve igual para flechas, puntos y deslizamiento con el dedo.
    const onScroll = () => {
        const element = track.current;

        if (element && element.clientWidth > 0) {
            setIndex(Math.round(element.scrollLeft / element.clientWidth));
        }
    };

    // Avance automático. Cada cambio de banner (también manual) reinicia la cuenta.
    useEffect(() => {
        if (!autoplay) {
            return;
        }

        const timer = setTimeout(() => goTo(index + 1), interval);

        return () => clearTimeout(timer);
    }, [autoplay, index, interval, goTo]);

    // Si cambia el ancho (girar el celular, redimensionar) se mantiene el banner actual.
    useEffect(() => {
        const element = track.current;

        if (!element) {
            return;
        }

        const observer = new ResizeObserver(() => element.scrollTo({ left: index * element.clientWidth, behavior: 'auto' }));
        observer.observe(element);

        return () => observer.disconnect();
    }, [index]);

    if (count === 0) {
        return null;
    }

    const arrow =
        'store-surface absolute top-1/2 z-10 hidden size-11 -translate-y-1/2 items-center justify-center text-store-text opacity-90 hover:opacity-100 focus-visible:outline-2 focus-visible:outline-store-accent sm:inline-flex';

    return (
        <section
            role="region"
            aria-roledescription="carrusel"
            aria-label="Banners"
            className={cn('rounded-store-lg relative overflow-hidden', className)}
            onPointerEnter={() => setEngaged(true)}
            onPointerLeave={() => setEngaged(false)}
            onFocus={() => setEngaged(true)}
            onBlur={() => setEngaged(false)}
        >
            <div
                ref={track}
                onScroll={onScroll}
                aria-live={autoplay ? 'off' : 'polite'}
                className="flex snap-x snap-mandatory overflow-x-auto overscroll-x-contain [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            >
                {banners.map((banner, position) => {
                    const slide = (
                        <>
                            <img
                                src={banner.image_url}
                                alt={banner.title ?? `Banner ${position + 1}`}
                                draggable={false}
                                loading={position === 0 ? 'eager' : 'lazy'}
                                fetchPriority={position === 0 ? 'high' : 'auto'}
                                className="size-full object-cover"
                            />
                            {banner.title && (
                                <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent px-5 pt-16 pb-10 sm:px-10 sm:pb-12">
                                    <p className="store-heading text-2xl text-white sm:text-4xl lg:text-5xl">{banner.title}</p>
                                </div>
                            )}
                        </>
                    );

                    const frame = 'relative block aspect-[16/10] w-full shrink-0 snap-center overflow-hidden bg-store-surface sm:aspect-[21/9]';

                    return banner.link_url ? (
                        <a
                            key={banner.id}
                            href={banner.link_url}
                            role="group"
                            aria-roledescription="diapositiva"
                            aria-label={`${position + 1} de ${count}`}
                            className={frame}
                        >
                            {slide}
                        </a>
                    ) : (
                        <div
                            key={banner.id}
                            role="group"
                            aria-roledescription="diapositiva"
                            aria-label={`${position + 1} de ${count}`}
                            className={frame}
                        >
                            {slide}
                        </div>
                    );
                })}
            </div>

            {count > 1 && (
                <>
                    <button type="button" aria-label="Banner anterior" onClick={() => goTo(index - 1)} className={cn(arrow, 'left-3')}>
                        <ChevronLeft className="size-5" />
                    </button>
                    <button type="button" aria-label="Banner siguiente" onClick={() => goTo(index + 1)} className={cn(arrow, 'right-3')}>
                        <ChevronRight className="size-5" />
                    </button>

                    <div className="absolute inset-x-0 bottom-3 z-10 flex items-center justify-center gap-2">
                        <div className="bg-store-bg/85 flex items-center rounded-full px-1 backdrop-blur">
                            {banners.map((banner, position) => (
                                <button
                                    key={banner.id}
                                    type="button"
                                    aria-label={`Ir al banner ${position + 1}`}
                                    aria-current={position === index ? 'true' : undefined}
                                    onClick={() => goTo(position)}
                                    className="group focus-visible:outline-store-accent inline-flex size-6 items-center justify-center focus-visible:outline-2"
                                >
                                    <span
                                        className={cn(
                                            'store-transition block size-2.5 rounded-full',
                                            position === index ? 'bg-store-accent scale-110' : 'bg-store-text/30 group-hover:bg-store-text/60',
                                        )}
                                    />
                                </button>
                            ))}
                        </div>
                        <button
                            type="button"
                            aria-label={paused ? 'Reanudar avance automático' : 'Pausar avance automático'}
                            onClick={() => setPaused((value) => !value)}
                            className="bg-store-bg/85 text-store-text focus-visible:outline-store-accent inline-flex size-8 items-center justify-center rounded-full backdrop-blur focus-visible:outline-2"
                        >
                            {paused ? <Play className="size-3.5" /> : <Pause className="size-3.5" />}
                        </button>
                    </div>
                </>
            )}
        </section>
    );
}
