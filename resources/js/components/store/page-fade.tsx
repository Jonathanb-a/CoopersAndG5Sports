import { useEntrance } from '@/Themes/use-entrance';
import { useMotion } from '@/Themes/use-motion';
import { type ReactNode, useRef } from 'react';

/**
 * Fundido corto al entrar a una página (solo opacity, así que no mueve nada ni cambia el layout). Sin
 * efecto con prefers-reduced-motion, en las rutas de pagar y formularios, ni en temas sin movimiento.
 * El contenido se renderiza visible: la transparencia inicial solo se aplica ya en el navegador.
 */
export function PageFade({ children }: { children: ReactNode }) {
    const active = useMotion();
    const ref = useRef<HTMLDivElement>(null);
    const fade = active && !active.reduced ? { duration: active.set.page.duration, ease: active.set.ease } : null;

    useEntrance({
        targetRef: ref,
        hidden: fade ? { opacity: 0 } : null,
        visible: fade ? { opacity: 1 } : null,
        transition: fade ?? { duration: 0 },
        trigger: 'mount',
    });

    if (!fade) {
        return <>{children}</>;
    }

    return (
        <div ref={ref} data-page-fade="">
            {children}
        </div>
    );
}
