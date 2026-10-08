import { type ThemeMotion } from '../context';
import { type MotionSet } from '../motion';

/**
 * Movimiento de Sports G5: cancha y calle. Entradas rápidas de 180 a 320 ms con un poco de empuje
 * (el easing se pasa de 1 apenas, un overshoot corto), desplazamientos más marcados de 16 a 32 px y
 * escalonado corto y enérgico. Es el único lugar con estos números; tiene la misma forma que el de
 * Copper's más las secciones propias del carácter de G5 (sello, corte de títulos, banner, pulso y deriva).
 */
export const g5Motion: MotionSet = {
    ease: [0.2, 1.25, 0.4, 1],
    reveal: { duration: 0.26, distance: 24, stagger: 0.04, maxStagger: 0.16, mobileDuration: 0.2, mobileDistance: 16 },
    card: { scale: 1.08, duration: 0.22, tilt: 1.5, lift: 4, tagStraighten: 3 },
    header: { duration: 0.22, hideAfter: 100 },
    drawer: { duration: 0.26 },
    addToCart: { holdMs: 2000, fade: 0.2, pop: 0.8 },
    page: { duration: 0.18 },
    // Banners: 5 s cada uno y un deslizamiento un poco más marcado.
    carousel: { interval: 5, duration: 0.55, effect: 'slide' },
    quietPaths: ['/checkout', '/carrito', '/pedido', '/mi-cuenta', '/login', '/register', '/forgot-password', '/reset-password'],
    tag: { duration: 0.28, scale: 1.6, rotate: -14 },
    heading: { duration: 0.3 },
    banner: { distance: 32, scale: 0.96 },
    button: { pulseScale: 1.04, pulseDuration: 0.3 },
    // Deriva lenta de las manchas: 28 s de ida, un par de puntos porcentuales. Solo transform.
    backdrop: { duration: 28, x: 4, y: -3 },
};

/** La misma aparición con la forma que ya usan los temas. */
export const g5RevealMotion: ThemeMotion = {
    initial: { opacity: 0, y: g5Motion.reveal.distance },
    animate: { opacity: 1, y: 0 },
    transition: { duration: g5Motion.reveal.duration, ease: g5Motion.ease },
};

/** Variables de CSS para lo que se anima con CSS (cajón, sello, pulso y deriva): salen de los mismos valores. */
export const g5MotionVars = {
    '--motion-ease': `cubic-bezier(${g5Motion.ease.join(',')})`,
    '--motion-drawer-duration': `${Math.round(g5Motion.drawer.duration * 1000)}ms`,
    '--motion-stamp-duration': `${Math.round(g5Motion.tag!.duration * 1000)}ms`,
    '--motion-stamp-scale': String(g5Motion.tag!.scale),
    '--motion-stamp-rotate': `${g5Motion.tag!.rotate}deg`,
    '--motion-pulse-duration': `${Math.round(g5Motion.button!.pulseDuration * 1000)}ms`,
    '--motion-pulse-scale': String(g5Motion.button!.pulseScale),
    '--motion-drift-duration': `${g5Motion.backdrop!.duration}s`,
    '--motion-drift-x': `${g5Motion.backdrop!.x}%`,
    '--motion-drift-y': `${g5Motion.backdrop!.y}%`,
} as Record<string, string>;
