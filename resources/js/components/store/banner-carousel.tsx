import { cn } from '@/lib/utils';
import { useTheme } from '@/Themes/context';
import { type MotionSet } from '@/Themes/motion';
import { type StoreBanner } from '@/types';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { type PointerEvent, type TouchEvent, useCallback, useEffect, useRef, useState } from 'react';

interface BannerCarouselProps {
    banners: StoreBanner[];
    className?: string;
}

/** Para un tema sin MotionSet: los mismos tiempos base (5 s por banner, fundido corto). */
const FALLBACK: MotionSet['carousel'] = { interval: 5, duration: 0.6, effect: 'fade' };

/** Deslizamiento mínimo del dedo, en px, para cambiar de banner. */
const SWIPE = 40;

/** Caja de proporción fija: 16:10 en celular, 21:9 en tableta y escritorio. Sin saltos de layout. */
const FRAME = 'relative aspect-[16/10] w-full overflow-hidden bg-store-surface md:aspect-[21/9]';

/**
 * Carrusel de banners con desplazamiento automático y SIN controles: ni flechas, ni puntos, ni botón
 * de pausa. Cada banner dura lo que diga el tema (5 s) y cambia con un fundido (Copper's) o un
 * deslizamiento (G5) que solo anima opacity y transform.
 *
 * Sin botones se maneja así: en celular se desliza con el dedo; el avance se detiene mientras el cursor
 * está encima, el dedo toca o el foco está dentro, y se reanuda al soltar; también se pausa si la
 * pestaña no está visible. Con prefers-reduced-motion no avanza solo (queda el primero, con swipe).
 * Con un solo banner no hay carrusel ni nada extra, y sin banners no se pinta nada.
 */
export function BannerCarousel({ banners, className }: BannerCarouselProps) {
    const { motionSet } = useTheme();
    const reduced = useReducedMotion() ?? false;
    const settings = motionSet?.carousel ?? FALLBACK;
    const count = banners.length;

    const [position, setPosition] = useState(0);
    const [direction, setDirection] = useState(1);
    const [hovering, setHovering] = useState(false);
    const [touching, setTouching] = useState(false);
    const [focused, setFocused] = useState(false);
    const [tabHidden, setTabHidden] = useState(false);
    const touchStart = useRef<{ x: number; y: number } | null>(null);

    const index = count > 0 ? position % count : 0;
    const autoplay = count > 1 && !reduced && !hovering && !touching && !focused && !tabHidden;

    const go = useCallback(
        (step: 1 | -1) => {
            setDirection(step);
            setPosition((current) => (current + step + count) % count);
        },
        [count],
    );

    // Avance automático. Cada cambio (también con el dedo) reinicia la cuenta.
    useEffect(() => {
        if (!autoplay) {
            return;
        }

        const timer = window.setTimeout(() => go(1), settings.interval * 1000);

        return () => window.clearTimeout(timer);
    }, [autoplay, index, go, settings.interval]);

    // Con la pestaña oculta no avanza.
    useEffect(() => {
        if (count < 2) {
            return;
        }

        const update = () => setTabHidden(document.visibilityState === 'hidden');

        update();
        document.addEventListener('visibilitychange', update);

        return () => document.removeEventListener('visibilitychange', update);
    }, [count]);

    // Carga diferida: solo el primero carga con prioridad; el siguiente se pide poco antes de que le toque.
    useEffect(() => {
        if (count < 2) {
            return;
        }

        const next = banners[(index + 1) % count];
        const timer = window.setTimeout(
            () => {
                new Image().src = next.image_url;
            },
            Math.max(0, settings.interval * 1000 - 2000),
        );

        return () => window.clearTimeout(timer);
    }, [banners, count, index, settings.interval]);

    if (count === 0) {
        return null;
    }

    const slide = (banner: StoreBanner, number: number, current: boolean) => {
        const content = (
            <>
                <img
                    src={banner.image_url}
                    alt={banner.title ?? `Banner ${number + 1}`}
                    width={1600}
                    height={1000}
                    draggable={false}
                    decoding="async"
                    loading={number === 0 ? 'eager' : 'lazy'}
                    fetchPriority={number === 0 ? 'high' : 'auto'}
                    className="size-full object-cover"
                />
                {banner.title && (
                    // Degradado para que el título se lea sobre cualquier foto (blanco sobre negro al 70 %: AA).
                    <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 via-black/45 to-transparent px-5 pt-16 pb-6 sm:px-10 sm:pb-10">
                        <p className="store-heading text-2xl text-balance break-words text-white sm:text-4xl lg:text-5xl">{banner.title}</p>
                    </div>
                )}
            </>
        );

        const props = {
            role: 'group',
            'aria-roledescription': 'diapositiva',
            'aria-label': `${number + 1} de ${count}`,
            'data-banner': banner.id,
            'data-current': current ? '' : undefined,
            className: 'block size-full',
        };

        return banner.link_url ? (
            <a href={banner.link_url} {...props}>
                {content}
            </a>
        ) : (
            <div {...props}>{content}</div>
        );
    };

    // Un solo banner: una imagen fija, sin carrusel, sin temporizador y sin gestos.
    if (count === 1) {
        return (
            <section aria-label="Banner" data-carousel="single" className={cn('rounded-store-lg overflow-hidden', className)}>
                <div className={FRAME}>{slide(banners[0], 0, true)}</div>
            </section>
        );
    }

    const slides = settings.effect === 'slide';
    const duration = reduced ? 0 : settings.duration;
    const ease = motionSet?.ease;

    // Solo opacity y transform. El que entra viene del lado hacia el que se avanza.
    const variants = {
        enter: (way: number) => (slides ? { x: way > 0 ? '100%' : '-100%', opacity: 1 } : { x: '0%', opacity: 0 }),
        center: { x: '0%', opacity: 1 },
        exit: (way: number) => (slides ? { x: way > 0 ? '-100%' : '100%', opacity: 1 } : { x: '0%', opacity: 0 }),
    };

    const onTouchStart = (event: TouchEvent) => {
        touchStart.current = { x: event.touches[0].clientX, y: event.touches[0].clientY };
        setTouching(true);
    };

    const onTouchEnd = (event: TouchEvent) => {
        const start = touchStart.current;
        const end = event.changedTouches[0];

        touchStart.current = null;
        setTouching(false);

        if (!start || !end) {
            return;
        }

        const dx = end.clientX - start.x;
        const dy = end.clientY - start.y;

        // Solo un gesto claramente horizontal cambia de banner; el vertical es el scroll de la página.
        if (Math.abs(dx) >= SWIPE && Math.abs(dx) > Math.abs(dy)) {
            go(dx < 0 ? 1 : -1);
        }
    };

    // El cursor encima pausa. Un toque no cuenta como cursor (si no, en celular quedaría pausado tras tocar).
    const onPointerEnter = (event: PointerEvent) => {
        if (event.pointerType === 'mouse') {
            setHovering(true);
        }
    };

    return (
        <section
            role="region"
            aria-roledescription="carrusel"
            aria-label="Banners"
            data-carousel="auto"
            data-index={index}
            data-autoplay={autoplay ? 'on' : 'off'}
            className={cn('rounded-store-lg touch-pan-y overflow-hidden', className)}
            onPointerEnter={onPointerEnter}
            onPointerLeave={() => setHovering(false)}
            onTouchStart={onTouchStart}
            onTouchEnd={onTouchEnd}
            onTouchCancel={() => {
                touchStart.current = null;
                setTouching(false);
            }}
            onFocus={() => setFocused(true)}
            onBlur={() => setFocused(false)}
        >
            <div className={FRAME} aria-live={autoplay ? 'off' : 'polite'}>
                <AnimatePresence initial={false} custom={direction}>
                    <motion.div
                        key={banners[index].id}
                        custom={direction}
                        variants={variants}
                        initial="enter"
                        animate="center"
                        exit="exit"
                        transition={{ duration, ease }}
                        className="absolute inset-0"
                    >
                        {slide(banners[index], index, true)}
                    </motion.div>
                </AnimatePresence>
            </div>
        </section>
    );
}
