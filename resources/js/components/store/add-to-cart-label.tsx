import { useTheme } from '@/Themes/context';
import { useMotion } from '@/Themes/use-motion';
import { Check, ShoppingBag } from 'lucide-react';
import { motion } from 'motion/react';

interface AddToCartLabelProps {
    soldOut: boolean;
    adding: boolean;
    /** Se acaba de agregar: el tema con movimiento lo confirma un momento con un check. */
    added: boolean;
}

/**
 * Contenido del botón de agregar al carrito. En Copper's confirma con un check y «Agregado» (cambio
 * suave de opacidad; instantáneo con prefers-reduced-motion). En los demás temas queda como siempre.
 */
export function AddToCartLabel({ soldOut, adding, added }: AddToCartLabelProps) {
    const { motionSet } = useTheme();
    const active = useMotion();

    if (soldOut) {
        return (
            <>
                <ShoppingBag className="size-5" aria-hidden="true" />
                Agotado
            </>
        );
    }

    if (adding) {
        return (
            <>
                <ShoppingBag className="size-5" aria-hidden="true" />
                Agregando…
            </>
        );
    }

    if (added && motionSet) {
        const fade = active && !active.reduced ? { duration: active.set.addToCart.fade, ease: active.set.ease } : { duration: 0 };

        return (
            <motion.span
                key="added"
                role="status"
                className="inline-flex items-center gap-2"
                initial={{ opacity: 0 }}
                animate={{ opacity: 1 }}
                transition={fade}
            >
                <Check className="size-5" aria-hidden="true" />
                Agregado
            </motion.span>
        );
    }

    return (
        <>
            <ShoppingBag className="size-5" aria-hidden="true" />
            Agregar al carrito
        </>
    );
}
