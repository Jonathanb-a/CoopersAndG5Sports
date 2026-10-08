import { usePage } from '@inertiajs/react';
import { useReducedMotion } from 'motion/react';
import { useSyncExternalStore } from 'react';
import { useTheme } from './context';
import { isQuietPath, type MotionContext, type MotionSet } from './motion';

const MOBILE_BREAKPOINT = 768;

function subscribeToViewport(callback: () => void) {
    window.addEventListener('resize', callback);

    return () => window.removeEventListener('resize', callback);
}

/**
 * Celular, leído de inmediato en el cliente (no después de montar): la aparición de las secciones
 * decide su estado inicial al montarse, así que no puede esperar a un efecto. En el servidor se asume
 * escritorio.
 */
function useMobileViewport(): boolean {
    return useSyncExternalStore(
        subscribeToViewport,
        () => window.innerWidth < MOBILE_BREAKPOINT,
        () => false,
    );
}

export interface ActiveMotion extends MotionContext {
    set: MotionSet;
}

/**
 * El movimiento que aplica ahora mismo, o null si el tema no tiene (G5) o la ruta es de pagar o de
 * formularios. Con prefers-reduced-motion devuelve `reduced: true` y cada componente se queda quieto.
 */
export function useMotion(): ActiveMotion | null {
    const { motionSet } = useTheme();
    const reduced = useReducedMotion() ?? false;
    const mobile = useMobileViewport();
    const { url } = usePage();

    if (!motionSet || isQuietPath(motionSet, url)) {
        return null;
    }

    return { set: motionSet, reduced, mobile };
}
