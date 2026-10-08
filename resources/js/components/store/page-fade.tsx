import { useMotion } from '@/Themes/use-motion';
import { motion } from 'motion/react';
import { type ReactNode } from 'react';

/**
 * Fundido corto al entrar a una página (solo opacity, así que no mueve nada ni cambia el layout). Sin
 * efecto con prefers-reduced-motion, en las rutas de pagar y formularios, ni en temas sin movimiento.
 */
export function PageFade({ children }: { children: ReactNode }) {
    const active = useMotion();

    if (!active || active.reduced) {
        return <>{children}</>;
    }

    return (
        <motion.div initial={{ opacity: 0 }} animate={{ opacity: 1 }} transition={{ duration: active.set.page.duration, ease: active.set.ease }}>
            {children}
        </motion.div>
    );
}
