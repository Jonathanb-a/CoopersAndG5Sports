import { buttonVariants } from '@/components/store/button';
import { MobileMenu } from '@/components/store/mobile-menu';
import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';
import { useTheme } from '@/Themes/context';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';

interface MiniCartPanelProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/** Mini carrito: panel lateral que se abre al agregar un producto. */
export function MiniCartPanel({ open, onOpenChange }: MiniCartPanelProps) {
    const { miniCart } = usePage<SharedData>().props;
    const { root } = useTheme();

    return (
        <MobileMenu open={open} onOpenChange={onOpenChange} container={root} side="right" title="Tu carrito" className="sm:w-96">
            <p className="store-heading mt-2 text-3xl">Agregado a tu carrito</p>

            <div className="mt-6 flex-1 overflow-y-auto" role="status">
                {miniCart.lines.length === 0 ? (
                    <p className="text-store-muted">Tu carrito está vacío.</p>
                ) : (
                    <ul className="divide-store-border border-store-border divide-y border-y">
                        {miniCart.lines.map((line) => (
                            <li key={line.variant_id} className="flex gap-3 py-3">
                                <div className="bg-store-photo rounded-store relative size-16 shrink-0 overflow-hidden">
                                    {line.image_url ? (
                                        <img src={line.image_url} alt="" className="absolute inset-0 m-auto max-h-full max-w-full object-contain" />
                                    ) : (
                                        <span
                                            className="store-heading text-store-muted absolute inset-0 flex items-center justify-center text-2xl"
                                            aria-hidden="true"
                                        >
                                            {line.name.charAt(0)}
                                        </span>
                                    )}
                                </div>
                                <div className="min-w-0 flex-1 text-sm">
                                    <p className="line-clamp-2 font-medium">{line.name}</p>
                                    <p className="text-store-muted">Talla {line.size}</p>
                                    <p className="mt-0.5">
                                        {line.quantity} × {formatMoney(line.unit_price)}
                                    </p>
                                </div>
                                <p className="text-sm font-bold">{formatMoney(line.line_total)}</p>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div className="mt-4">
                <p className="flex items-baseline justify-between text-lg font-bold">
                    <span>Subtotal</span>
                    <span>{formatMoney(miniCart.subtotal)}</span>
                </p>
                <div className="mt-4 flex flex-col gap-2">
                    <Link href="/checkout" className={cn(buttonVariants({ size: 'lg' }), 'w-full')}>
                        Continuar con la compra
                    </Link>
                    <Link href="/carrito" className={cn(buttonVariants({ variant: 'outline' }), 'w-full')}>
                        Ver carrito
                    </Link>
                    <button type="button" onClick={() => onOpenChange(false)} className={cn(buttonVariants({ variant: 'ghost' }), 'w-full')}>
                        Seguir comprando
                    </button>
                </div>
            </div>
        </MobileMenu>
    );
}
