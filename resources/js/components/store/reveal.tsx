import { useTheme } from '@/Themes/context';
import { revealPreset, staggerDelay } from '@/Themes/motion';
import { useMotion } from '@/Themes/use-motion';
import { motion } from 'motion/react';
import { type ReactNode } from 'react';

interface RevealProps {
    children: ReactNode;
    delay?: number;
    className?: string;
}

/**
 * Aparece al cargar con la animación del tema. Copper's usa su MotionSet (suave, sin rebote; sin
 * movimiento con prefers-reduced-motion; más ligera en celular; ninguna al pagar ni en formularios).
 * Los temas sin MotionSet (G5) siguen con su animación de siempre.
 */
export function Reveal({ children, delay = 0, className }: RevealProps) {
    const { motion: legacy, motionSet } = useTheme();
    const active = useMotion();

    if (motionSet) {
        const preset = active && revealPreset(active.set, active);

        if (!active || !preset) {
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
export function ScrollReveal({ children, delay = 0, className }: RevealProps) {
    const { motion: legacy, motionSet } = useTheme();
    const active = useMotion();

    if (motionSet) {
        const preset = active && revealPreset(active.set, active);

        if (!active || !preset) {
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
