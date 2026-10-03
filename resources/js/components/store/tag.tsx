import { cn } from '@/lib/utils';
import { type ComponentProps } from 'react';

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

/** Etiqueta de producto. Sin texto propio muestra la etiqueta de la tienda para ese tipo. */
export function Tag({ kind, className, children, ...props }: TagProps) {
    return (
        <span
            className={cn(
                'store-tag-shape rounded-store font-store-body inline-block px-2 py-0.5 text-xs font-bold tracking-wide uppercase',
                styles[kind],
                className,
            )}
            {...props}
        >
            {children ?? labels[kind]}
        </span>
    );
}
