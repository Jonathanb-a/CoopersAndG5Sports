import { animate } from 'motion/react';
import { type RefObject, useEffect, useLayoutEffect, useState } from 'react';

type Values = Record<string, number | string>;

interface EntranceOptions {
    /** Elemento que se anima. */
    targetRef: RefObject<HTMLElement | null>;
    /** Estado oculto de partida y estado final visible. Sin ellos (null) no hay animación y el contenido se queda como está. */
    hidden: Values | null;
    visible: Values | null;
    transition: { duration: number; ease?: [number, number, number, number]; delay?: number };
    /** `mount`: al cargar. `view`: cuando `viewRef` entra en pantalla (una sola vez). */
    trigger: 'mount' | 'view';
    /**
     * Elemento que se observa para `view` (por defecto, el que se anima). Debe ser uno que NO quede
     * recortado en el estado oculto: un texto desplazado dentro de un overflow-hidden nunca «entra en
     * pantalla» para el observer, así que para un título con corte se observa la caja, no el texto.
     */
    viewRef?: RefObject<Element | null>;
}

const unit = (value: number | string) => (typeof value === 'number' ? `${value}px` : value);

/** Solo opacity y transform: es todo lo que una entrada puede tocar. */
function applyHidden(element: HTMLElement, hidden: Values) {
    const transforms = [
        hidden.x !== undefined ? `translateX(${unit(hidden.x)})` : null,
        hidden.y !== undefined ? `translateY(${unit(hidden.y)})` : null,
        hidden.scale !== undefined ? `scale(${hidden.scale})` : null,
    ].filter(Boolean);

    if (hidden.opacity !== undefined) {
        element.style.opacity = String(hidden.opacity);
    }

    if (transforms.length > 0) {
        element.style.transform = transforms.join(' ');
    }
}

/** Estado final: sin estilos de animación, es decir, visible y en su sitio. */
function clear(element: HTMLElement) {
    element.style.opacity = '';
    element.style.transform = '';
}

/**
 * Entrada de un bloque con la regla de la tienda: TODO contenido animado termina visible aunque algo
 * falle. El contenido se renderiza visible (así sale en el HTML y así se queda si JavaScript o el
 * observer no funcionan); el estado oculto se pone solo en el navegador, justo antes de pintar, y solo
 * cuando ya hay con qué revelarlo (para `view`, cuando existe IntersectionObserver). Al terminar (o si
 * la animación falla) se quitan los estilos: queda visible.
 *
 * Devuelve si la entrada quedó armada (es decir, si se va a animar).
 */
export function useEntrance({ targetRef, hidden, visible, transition, trigger, viewRef }: EntranceOptions): boolean {
    const [armed, setArmed] = useState(false);
    const seen = useSeen(viewRef ?? targetRef, armed && trigger === 'view', 0.15);
    const enabled = hidden !== null && visible !== null;

    useLayoutEffect(() => {
        const element = targetRef.current;

        if (!enabled || !element || (trigger === 'view' && typeof IntersectionObserver === 'undefined')) {
            return;
        }

        applyHidden(element, hidden);
        setArmed(true);

        // Si el bloque se desmonta a medias, no deja nada escondido.
        return () => clear(element);
        // Solo al montar: el estado oculto se pone una vez.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        const element = targetRef.current;

        if (!armed || !element || !hidden || !visible || (trigger === 'view' && !seen)) {
            return;
        }

        const keyframes = Object.fromEntries(Object.keys(visible).map((key) => [key, [hidden[key] ?? visible[key], visible[key]]]));

        try {
            const playback = animate(element, keyframes, transition);

            playback.then(() => clear(element)).catch(() => clear(element));

            return () => playback.stop();
        } catch {
            clear(element);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [armed, seen]);

    return armed;
}

/**
 * Si el elemento ya entró en pantalla (una sola vez). Propio, y no el de Motion, porque el de Motion
 * lanza un error cuando el navegador no tiene IntersectionObserver; aquí, sin observer, cuenta como
 * visto: nada se queda escondido esperando.
 */
export function useSeen(ref: RefObject<Element | null>, enabled: boolean, amount = 0.15): boolean {
    const [seen, setSeen] = useState(false);

    useEffect(() => {
        const element = ref.current;

        if (!enabled || seen || !element) {
            return;
        }

        if (typeof IntersectionObserver === 'undefined') {
            setSeen(true);

            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    setSeen(true);
                    observer.disconnect();
                }
            },
            { threshold: amount },
        );

        observer.observe(element);

        return () => observer.disconnect();
    }, [ref, enabled, seen, amount]);

    return seen;
}

/**
 * Para lo que se oculta con CSS mientras espera entrar en pantalla (el sello de las etiquetas): true
 * solo en el navegador, después de montar y si hay IntersectionObserver. Antes de eso, visible.
 */
export function useObserverReady(): boolean {
    const [ready, setReady] = useState(false);

    useLayoutEffect(() => {
        setReady(typeof IntersectionObserver !== 'undefined');
    }, []);

    return ready;
}
