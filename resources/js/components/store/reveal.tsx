import { useTheme } from '@/Themes/context';
import { bannerPreset, revealPreset, staggerDelay } from '@/Themes/motion';
import { useMotion } from '@/Themes/use-motion';
import { motion } from 'motion/react';
import { type ReactNode } from 'react';

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
 * Aparece al cargar con la animación del tema, que sale de su MotionSet: suave en Copper's, rápida y
 * con empuje en G5. Sin movimiento con prefers-reduced-motion; más ligera en celular; ninguna al
 * pagar ni en formularios. Un tema sin MotionSet conserva su animación simple de siempre.
 */
export function Reveal({ children, delay = 0, className, emphasis, bare }: RevealProps) {
    const { motion: legacy, motionSet } = useTheme();
    const active = useMotion();

    if (motionSet) {
        const preset = active && (emphasis === 'banner' ? bannerPreset(active.set, active) : revealPreset(active.set, active));

        if (!active || !preset || (bare && active.set.heading)) {
            return <div className={className}>{children}</div>;
        }

        return (
            <motion.div
                className={className}
                initial={preset.initial}
                animate={preset.animate}
                transition={{ ...preset.transition, delay: staggerDelay(active.set, active, delay) }}
            >
                {children}
            </motion.div>
        );
    }

    return (
        <motion.div className={className} initial={legacy.initial} animate={legacy.animate} transition={{ ...legacy.transition, delay }}>
            {children}
        </motion.div>
    );
}

/** Igual que Reveal, pero espera a que el bloque entre en pantalla al hacer scroll (una sola vez). */
export function ScrollReveal({ children, delay = 0, className, bare }: RevealProps) {
    const { motion: legacy, motionSet } = useTheme();
    const active = useMotion();

    if (motionSet) {
        const preset = active && revealPreset(active.set, active);

        if (!active || !preset || (bare && active.set.heading)) {
            return <div className={className}>{children}</div>;
        }

        return (
            <motion.div
                className={className}
                initial={preset.initial}
                whileInView={preset.animate}
                viewport={{ once: true, amount: 0.15 }}
                transition={{ ...preset.transition, delay: staggerDelay(active.set, active, delay) }}
            >
                {children}
            </motion.div>
        );
    }

    return (
        <motion.div
            className={className}
            initial={legacy.initial}
            whileInView={legacy.animate}
            viewport={{ once: true, amount: 0.15 }}
            transition={{ ...legacy.transition, delay }}
        >
            {children}
        </motion.div>
    );
}
