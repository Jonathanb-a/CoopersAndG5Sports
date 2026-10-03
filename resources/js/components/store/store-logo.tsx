import { cn } from '@/lib/utils';
import { useEffect, useState } from 'react';

interface StoreLogoProps {
    theme: string | null;
    name: string;
    className?: string;
    textClassName?: string;
}

/**
 * Logo de la tienda (public/brand/{theme}/logo.png). Mientras no exista el archivo muestra el
 * nombre de la tienda con la tipografía de títulos del tema.
 */
export function StoreLogo({ theme, name, className, textClassName }: StoreLogoProps) {
    const [logo, setLogo] = useState<string | null>(null);

    useEffect(() => {
        if (!theme) {
            return;
        }

        const src = `/brand/${theme}/logo.png`;
        const image = new Image();
        image.onload = () => setLogo(src);
        image.src = src;

        return () => {
            image.onload = null;
        };
    }, [theme]);

    if (logo) {
        return <img src={logo} alt={name} className={cn('h-8 w-auto lg:h-9', className)} />;
    }

    return <span className={cn('store-heading', textClassName)}>{name}</span>;
}
