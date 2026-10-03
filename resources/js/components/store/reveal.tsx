import { useTheme } from '@/Themes/context';
import { motion } from 'motion/react';
import { type ReactNode } from 'react';

interface RevealProps {
    children: ReactNode;
    delay?: number;
    className?: string;
}

/**
 * Aparece al cargar con la animación del tema: suave y sin rebote en Copper's, rápida y marcada
 * en G5. Con prefers-reduced-motion el layout desactiva el movimiento (MotionConfig).
 */
export function Reveal({ children, delay = 0, className }: RevealProps) {
    const { motion: preset } = useTheme();

    return (
        <motion.div className={className} initial={preset.initial} animate={preset.animate} transition={{ ...preset.transition, delay }}>
            {children}
        </motion.div>
    );
}

/** Igual que Reveal, pero espera a que el bloque entre en pantalla al hacer scroll (una sola vez). */
export function ScrollReveal({ children, delay = 0, className }: RevealProps) {
    const { motion: preset } = useTheme();

    return (
        <motion.div
            className={className}
            initial={preset.initial}
            whileInView={preset.animate}
            viewport={{ once: true, amount: 0.15 }}
            transition={{ ...preset.transition, delay }}
        >
            {children}
        </motion.div>
    );
}
