import { useTheme } from '@/Themes/context';
import { bannerPreset, revealPreset, staggerDelay } from '@/Themes/motion';
import { useEntrance } from '@/Themes/use-entrance';
import { useMotion } from '@/Themes/use-motion';
import { type ReactNode, useRef } from 'react';

interface RevealProps {
    children: ReactNode;
    delay?: number;
    className?: string;
    /** `banner`: entrada más dinámica del banner del home; solo la anima un tema que la define. */
    emphasis?: 'banner';
    /** El contenido anima por sí mismo (títulos con corte): en un tema que lo hace, este bloque no se mueve. */
    bare?: boolean;
}

/**
 * Aparece con la animación del tema, que sale de su MotionSet: suave en Copper's, rápida y con empuje
 * en G5. `Reveal` entra al cargar; `ScrollReveal`, cuando el bloque llega a la pantalla (una sola vez).
 *
 * El contenido se renderiza visible y solo se oculta en el navegador cuando ya hay con qué revelarlo
 * (useEntrance): si JavaScript o el observer fallan, se ve igual. Sin movimiento con
 * prefers-reduced-motion; más ligera en celular; ninguna al pagar ni en formularios.
 */
function Entrance({ children, delay = 0, className, emphasis, bare, trigger }: RevealProps & { trigger: 'mount' | 'view' }) {
    const { motion: legacy, motionSet } = useTheme();
    const active = useMotion();
    const ref = useRef<HTMLDivElement>(null);

    // Un tema sin MotionSet conserva su animación simple de siempre.
    const preset = motionSet
        ? active && !(bare && active.set.heading)
            ? emphasis === 'banner'
                ? bannerPreset(active.set, active)
                : revealPreset(active.set, active)
            : null
        : legacy;

    useEntrance({
        targetRef: ref,
        hidden: preset?.initial ?? null,
        visible: preset?.animate ?? null,
        transition: preset ? { ...preset.transition, delay: active ? staggerDelay(active.set, active, delay) : delay } : { duration: 0 },
        trigger,
    });

    return (
        <div ref={ref} className={className}>
            {children}
        </div>
    );
}

/** Aparece al cargar la página. */
export function Reveal(props: RevealProps) {
    return <Entrance {...props} trigger="mount" />;
}

/** Aparece cuando el bloque entra en pantalla al hacer scroll (una sola vez). */
export function ScrollReveal(props: RevealProps) {
    return <Entrance {...props} trigger="view" />;
}
