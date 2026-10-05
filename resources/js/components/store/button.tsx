import { cn } from '@/lib/utils';
import { cva, type VariantProps } from 'class-variance-authority';
import { type ComponentProps } from 'react';

export const buttonVariants = cva(
    [
        'store-transition store-lift inline-flex items-center justify-center gap-2 rounded-store text-center font-store-body font-semibold',
        'border-[length:var(--store-border-width)] border-transparent',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-store-accent',
        'disabled:pointer-events-none disabled:opacity-50',
    ],
    {
        variants: {
            variant: {
                primary: 'bg-store-action text-store-action-text shadow-(--store-shadow) hover:bg-store-action-hover',
                outline: 'border-store-text text-store-text hover:bg-store-text hover:text-store-bg',
                ghost: 'text-store-text hover:bg-store-surface',
            },
            size: {
                sm: 'h-9 px-4 text-sm',
                md: 'h-11 px-6 text-base',
                lg: 'h-14 px-8 text-lg',
            },
        },
        defaultVariants: { variant: 'primary', size: 'md' },
    },
);

type ButtonProps = VariantProps<typeof buttonVariants> &
    (({ href: string } & ComponentProps<'a'>) | ({ href?: undefined } & ComponentProps<'button'>));

/** Botón de la tienda: colores, radio, sombra y movimiento salen de las variables del tema. */
export function StoreButton({ variant, size, className, ...props }: ButtonProps) {
    const classes = cn(buttonVariants({ variant, size }), className);

    if (props.href !== undefined) {
        return <a className={classes} {...(props as ComponentProps<'a'>)} />;
    }

    return <button type="button" className={classes} {...(props as ComponentProps<'button'>)} />;
}
