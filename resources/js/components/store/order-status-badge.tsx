import { cn } from '@/lib/utils';

export type OrderStatusValue = 'apartado' | 'pagado' | 'enviado' | 'entregado' | 'cancelado';

/**
 * Color por estado. Son fondos sólidos con su propio color de texto, así el contraste (AA) no
 * depende de si el tema de la tienda es claro u oscuro.
 */
const tones: Record<OrderStatusValue, string> = {
    apartado: 'bg-amber-300 text-black',
    pagado: 'bg-emerald-700 text-white',
    enviado: 'bg-sky-700 text-white',
    entregado: 'bg-neutral-700 text-white',
    cancelado: 'bg-red-700 text-white',
};

interface OrderStatusBadgeProps {
    status: OrderStatusValue;
    /** Texto ya en español; "enviado" llega como "Listo para recoger" o "En camino". */
    label: string;
    className?: string;
}

/** Estado de un pedido, en español y con color. */
export function OrderStatusBadge({ status, label, className }: OrderStatusBadgeProps) {
    return (
        <span
            className={cn(
                'rounded-store font-store-body inline-block px-2.5 py-1 text-xs font-bold tracking-wide whitespace-nowrap uppercase',
                tones[status] ?? tones.entregado,
                className,
            )}
        >
            {label}
        </span>
    );
}
