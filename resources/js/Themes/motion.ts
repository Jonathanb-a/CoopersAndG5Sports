/**
 * Movimiento de un tema: valores y funciones puras. Cada tema define su propio MotionSet en
 * Themes/{tema}/motion.ts y lo entrega en el contexto del tema; los componentes compartidos solo leen
 * esos valores (nunca llevan números propios). Un tema sin MotionSet no recibe estas animaciones.
 * Las secciones opcionales (tag, heading, banner, button, backdrop y los extras de card y addToCart)
 * son del carácter de un tema: si el tema no las define, ese efecto no existe.
 *
 * Reglas para todo lo que use esto: animar solo opacity y transform, nunca propiedades que muevan el
 * layout; con prefers-reduced-motion no hay movimiento; en celular, una versión más ligera; y ninguna
 * animación en el checkout ni en los formularios.
 */
export type Ease = [number, number, number, number];

export interface MotionSet {
    ease: Ease;
    /** Aparición suave de secciones y rejillas. Duraciones en segundos, distancias en px. */
    reveal: { duration: number; distance: number; stagger: number; maxStagger: number; mobileDuration: number; mobileDistance: number };
    /**
     * Tarjeta de producto: acercamiento de la foto y cambio a la segunda foto (duración en segundos).
     * Opcionales: inclinación en grados, elevación extra en px y grados que se endereza la etiqueta.
     */
    card: { scale: number; duration: number; tilt?: number; lift?: number; tagStraighten?: number };
    /** Encabezado: se recoge al bajar y aparece al subir. */
    header: { duration: number; hideAfter: number };
    /** Cajón (menú, mini carrito): entrada deslizante. */
    drawer: { duration: number };
    /** Botón de agregar al carrito: cuánto se queda el aviso «Agregado». */
    addToCart: { holdMs: number; fade: number; /** escala inicial del «Agregado» (efecto de pop) */ pop?: number };
    /** Fundido breve al cambiar de página. */
    page: { duration: number };
    /** Rutas sin ninguna animación: pagar y formularios. */
    quietPaths: string[];
    /** Etiquetas de producto: entrada tipo sello (escala y rotación en grados, en segundos). */
    tag?: { duration: number; scale: number; rotate: number };
    /** Títulos de sección: entrada con corte, subiendo desde abajo de su caja. */
    heading?: { duration: number };
    /** Banner del home: entrada más dinámica. */
    banner?: { distance: number; scale: number };
    /** Botón principal: pulso breve al pasar el cursor. */
    button?: { pulseScale: number; pulseDuration: number };
    /** Manchas de pintura del fondo: deriva lenta y sutil (segundos y desplazamiento en %). */
    backdrop?: { duration: number; x: number; y: number };
}

export interface MotionContext {
    /** El visitante pidió reducir el movimiento (prefers-reduced-motion). */
    reduced: boolean;
    /** Pantalla de celular. */
    mobile: boolean;
}

export interface RevealPreset {
    initial: { opacity: number; y: number; scale?: number };
    animate: { opacity: number; y: number; scale?: number };
    transition: { duration: number; ease: Ease };
}

/** La animación de aparición, o null si no debe haber movimiento (movimiento reducido). */
export function revealPreset(set: MotionSet, context: MotionContext): RevealPreset | null {
    if (context.reduced) {
        return null;
    }

    const { duration, distance, mobileDuration, mobileDistance } = set.reveal;

    return {
        initial: { opacity: 0, y: context.mobile ? mobileDistance : distance },
        animate: { opacity: 1, y: 0 },
        transition: { duration: context.mobile ? mobileDuration : duration, ease: set.ease },
    };
}

/** Entrada del banner del home, o null si el tema no la tiene o no debe haber movimiento. */
export function bannerPreset(set: MotionSet, context: MotionContext): RevealPreset | null {
    if (context.reduced || !set.banner) {
        return null;
    }

    const { duration, mobileDuration, mobileDistance } = set.reveal;

    // En celular solo sube un poco: sin el escalado.
    return context.mobile
        ? { initial: { opacity: 0, y: mobileDistance }, animate: { opacity: 1, y: 0 }, transition: { duration: mobileDuration, ease: set.ease } }
        : {
              initial: { opacity: 0, y: set.banner.distance, scale: set.banner.scale },
              animate: { opacity: 1, y: 0, scale: 1 },
              transition: { duration, ease: set.ease },
          };
}

/** Retraso de una tarjeta dentro de una rejilla: escalonado corto, topado, y ninguno en celular. */
export function staggerDelay(set: MotionSet, context: MotionContext, requested: number): number {
    if (context.mobile || context.reduced) {
        return 0;
    }

    return Math.min(Math.max(requested, 0), set.reveal.maxStagger);
}

/** Si en esta ruta (pagar, formularios) no debe haber animaciones. */
export function isQuietPath(set: MotionSet, url: string): boolean {
    const path = url.split(/[?#]/)[0] || '/';

    return set.quietPaths.some((quiet) => path === quiet || path.startsWith(`${quiet}/`));
}

/** Estilo de una transición de CSS (hover) con las duraciones del tema; sin movimiento, instantánea. */
export function cssTransition(
    set: MotionSet,
    seconds: number,
    properties: string,
): { transitionProperty: string; transitionDuration: string; transitionTimingFunction: string } {
    return {
        transitionProperty: properties,
        transitionDuration: `${Math.round(seconds * 1000)}ms`,
        transitionTimingFunction: `cubic-bezier(${set.ease.join(',')})`,
    };
}
