import { type ThemeMotion } from '../context';
import { type MotionSet } from '../motion';

/**
 * Movimiento de Copper's: fino y elegante. Entradas de 300 a 500 ms con ease-out suave, desplazamientos
 * cortos (8 a 16 px) y nada que rebote. Es el único lugar con estos números.
 */
export const coppersMotion: MotionSet = {
    ease: [0.22, 1, 0.36, 1],
    reveal: { duration: 0.45, distance: 12, stagger: 0.06, maxStagger: 0.24, mobileDuration: 0.35, mobileDistance: 8 },
    card: { scale: 1.03, duration: 0.5 },
    header: { duration: 0.35, hideAfter: 120 },
    drawer: { duration: 0.4 },
    addToCart: { holdMs: 1800, fade: 0.3 },
    page: { duration: 0.3 },
    quietPaths: ['/checkout', '/carrito', '/pedido', '/mi-cuenta', '/login', '/register', '/forgot-password', '/reset-password'],
};

/** La misma aparición con la forma que ya usan los temas (Reveal en G5 la lee igual). */
export const coppersRevealMotion: ThemeMotion = {
    initial: { opacity: 0, y: coppersMotion.reveal.distance },
    animate: { opacity: 1, y: 0 },
    transition: { duration: coppersMotion.reveal.duration, ease: coppersMotion.ease },
};

/** Variables de CSS para lo que se anima con CSS (el cajón): salen de los mismos valores. */
export const coppersMotionVars = {
    '--motion-ease': `cubic-bezier(${coppersMotion.ease.join(',')})`,
    '--motion-drawer-duration': `${Math.round(coppersMotion.drawer.duration * 1000)}ms`,
} as Record<string, string>;
