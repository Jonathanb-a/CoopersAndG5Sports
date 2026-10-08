import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';
import { useMotion } from '@/Themes/use-motion';
import { type ProductSize } from '@/types';
import { Link } from '@inertiajs/react';
import { motion } from 'motion/react';
import { type ReactNode } from 'react';
import { AddToCartLabel } from './add-to-cart-label';
import { buttonVariants, StoreButton } from './button';

interface BuyBarProps {
    /** Para identificarla (data-buy-bar). */
    name: string;
    /** Texto chico sobre el monto: «Precio», «Total»... */
    label: string;
    amount: number | string;
    /** Dentro de un Container: se sale de sus márgenes para ocupar todo el ancho. */
    bleed?: boolean;
    /** Aviso de error (por ejemplo, ya no hay piezas): va arriba, a todo el ancho, para que se vea sin subir. */
    error?: string | null;
    children: ReactNode;
}

/**
 * Barra de compra de celular (por debajo del ancho de tableta): el monto y el botón principal siempre
 * a la vista y al alcance del pulgar, con el área segura de iOS. Mismo estilo que la barra del checkout.
 *
 * Es `sticky`, no `fixed`: ocupa su propio lugar al final del contenido, así que nunca tapa nada (ni
 * el pie de página) y no hace falta agregar espacio. Queda debajo del encabezado y del cajón del carrito.
 * Solo anima su entrada (opacity y transform), y no con prefers-reduced-motion ni en las rutas de pagar.
 */
export function BuyBar({ name, label, amount, bleed = false, error, children }: BuyBarProps) {
    const active = useMotion();

    const className = cn(
        'bg-store-surface border-store-border font-store-body text-store-text sticky bottom-0 z-20 flex flex-wrap items-center gap-x-4 gap-y-2 border-t px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:px-6 md:hidden',
        bleed && '-mx-4 sm:-mx-6',
    );

    const content = (
        <>
            {error && (
                <p role="alert" className="text-store-error w-full text-sm font-medium">
                    {error}
                </p>
            )}
            <div className="shrink-0">
                <p className="text-store-muted text-xs">{label}</p>
                <p className="text-lg leading-tight font-bold">{formatMoney(amount)}</p>
            </div>
            {children}
        </>
    );

    if (active && !active.reduced) {
        return (
            <motion.div
                data-buy-bar={name}
                className={className}
                initial={{ opacity: 0, y: active.set.reveal.mobileDistance }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: active.set.reveal.mobileDuration, ease: active.set.ease }}
            >
                {content}
            </motion.div>
        );
    }

    return (
        <div data-buy-bar={name} className={className}>
            {content}
        </div>
    );
}

interface ProductBuyBarProps {
    price: number | string;
    sizes: ProductSize[];
    /** Talla elegida, si ya hay una. */
    chosen: ProductSize | null;
    adding: boolean;
    added: boolean;
    onAdd: () => void;
    /** Lleva al selector de tallas. */
    onChooseSize: () => void;
    error?: string | null;
}

/**
 * Barra del producto: precio y botón. Sin talla elegida el botón dice «Elige tu talla» y lleva al
 * selector en vez de agregar; agotado va deshabilitado; sobre pedido conserva el texto de siempre y lo
 * avisa sobre el precio. Un producto sin tallas registradas no tiene barra.
 */
export function ProductBuyBar({ price, sizes, chosen, adding, added, onAdd, onChooseSize, error }: ProductBuyBarProps) {
    if (sizes.length === 0) {
        return null;
    }

    const soldOut = !sizes.some((item) => item.selectable);
    const needsSize = !soldOut && !chosen;

    return (
        <BuyBar name="product" label={chosen?.status === 'backorder' ? 'Sobre pedido' : 'Precio'} amount={price} error={error}>
            <StoreButton size="lg" className="min-w-0 flex-1 px-4" onClick={needsSize ? onChooseSize : onAdd} disabled={soldOut || adding}>
                {needsSize ? 'Elige tu talla' : <AddToCartLabel soldOut={soldOut} adding={adding} added={added} />}
            </StoreButton>
        </BuyBar>
    );
}

/** Barra del carrito: total y «Continuar con la compra». Con el carrito vacío no se muestra. */
export function CartBuyBar({ count, total }: { count: number; total: number | string }) {
    if (count === 0) {
        return null;
    }

    return (
        <BuyBar name="cart" label="Total estimado" amount={total} bleed>
            <Link href="/checkout" className={cn(buttonVariants({ size: 'lg' }), 'min-w-0 flex-1 px-4')}>
                Continuar con la compra
            </Link>
        </BuyBar>
    );
}
