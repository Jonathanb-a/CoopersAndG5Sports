import { cn } from '@/lib/utils';
import { useTheme } from '@/Themes/context';
import { useObserverReady, useSeen } from '@/Themes/use-entrance';
import { useMotion } from '@/Themes/use-motion';
import { type ComponentProps, useRef } from 'react';

export type TagKind = 'sold-out' | 'backorder' | 'last-piece';

const labels: Record<TagKind, string> = {
    'sold-out': 'Agotado',
    backorder: 'Sobre pedido',
    'last-piece': 'Última pieza',
};

const styles: Record<TagKind, string> = {
    'sold-out': 'bg-store-tag-soldout text-store-tag-soldout-text',
    backorder: 'bg-store-tag-backorder text-store-tag-backorder-text border border-store-border',
    'last-piece': 'bg-store-tag-last text-store-tag-last-text',
};

type TagProps = ComponentProps<'span'> & { kind: TagKind };

/**
 * Etiqueta de producto. Sin texto propio muestra la etiqueta de la tienda para ese tipo. En un tema
 * con sello (G5) entra como calcomanía: llega con escala y una rotación corta al aparecer en pantalla
 * (CSS con las propiedades scale y rotate, que se suman a la inclinación fija del tema).
 */
export function Tag({ kind, className, children, style, ...props }: TagProps) {
    const { motionSet } = useTheme();
    const active = useMotion();
    const ref = useRef<HTMLSpanElement>(null);
    // Solo se esconde a esperar cuando el observer ya puede revelarla; si no, se queda puesta y visible.
    const ready = useObserverReady();
    const wants = Boolean(motionSet?.tag && active && !active.reduced && !active.mobile);
    const seen = useSeen(ref, ready && wants, 0.6);

    // Sin movimiento reducido ni celular: en celular la etiqueta aparece ya puesta.
    const stamp = ready && wants;

    return (
        <span
            ref={ref}
            data-stamp={stamp ? (seen ? 'in' : 'pending') : undefined}
            className={cn(
                'store-tag-shape rounded-store font-store-body inline-block px-2 py-0.5 text-xs font-bold tracking-wide uppercase',
                styles[kind],
                stamp && seen && 'g5-stamp',
                className,
            )}
            style={stamp && !seen ? { ...style, opacity: 0 } : style}
            {...props}
        >
            {children ?? labels[kind]}
        </span>
    );
}
