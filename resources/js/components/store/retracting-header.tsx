import { cn } from '@/lib/utils';
import { useMotion } from '@/Themes/use-motion';
import { motion, useMotionValueEvent, useScroll } from 'motion/react';
import { type ReactNode, useState } from 'react';

interface RetractingHeaderProps {
    className?: string;
    /** Mantiene el encabezado a la vista (por ejemplo, con el buscador abierto). */
    pinned?: boolean;
    children: ReactNode;
}

/**
 * Encabezado fijo que se recoge al bajar y aparece al subir. Solo mueve transform (translateY): ocupa
 * siempre el mismo lugar, así que no hay saltos de layout. No se mueve con prefers-reduced-motion, en
 * celular, en las rutas de pagar y formularios, ni si el tema no tiene movimiento.
 */
export function RetractingHeader({ className, pinned = false, children }: RetractingHeaderProps) {
    const active = useMotion();
    const { scrollY } = useScroll();
    const [hidden, setHidden] = useState(false);
    const [focused, setFocused] = useState(false);

    useMotionValueEvent(scrollY, 'change', (current) => {
        const previous = scrollY.getPrevious() ?? 0;

        if (!active) {
            return;
        }

        if (current <= active.set.header.hideAfter) {
            setHidden(false);
        } else if (current > previous) {
            setHidden(true);
        } else if (current < previous) {
            setHidden(false);
        }
    });

    const enabled = active !== null && !active.reduced && !active.mobile;
    const retracted = enabled && hidden && !pinned && !focused;

    return (
        <motion.header
            className={cn(className)}
            data-retracted={retracted}
            animate={{ y: retracted ? '-100%' : '0%' }}
            initial={false}
            transition={{ duration: active?.set.header.duration ?? 0, ease: active?.set.ease }}
            onFocusCapture={() => setFocused(true)}
            onBlurCapture={() => setFocused(false)}
        >
            {children}
        </motion.header>
    );
}
