import { cn } from '@/lib/utils';
import { type ComponentProps } from 'react';

type ContainerProps = ComponentProps<'div'> & {
    as?: 'div' | 'section' | 'main' | 'header' | 'footer' | 'nav';
};

/** Ancho máximo y márgenes laterales de la tienda. Primero celular, luego más aire en pantallas grandes. */
export function Container({ as: Tag = 'div', className, ...props }: ContainerProps) {
    return <Tag className={cn('mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8', className)} {...props} />;
}
