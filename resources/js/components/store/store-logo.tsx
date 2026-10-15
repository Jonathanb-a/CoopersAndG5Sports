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
 * nombre de la tienda con la tipografía de títulos del tema. Lleva ancho y alto reales (sin saltos de
 * layout); el tamaño y el contorno de cada tienda salen del tema (clase .store-logo).
 */
export function StoreLogo({ theme, name, className, textClassName }: StoreLogoProps) {
    const [logo, setLogo] = useState<{ src: string; width: number; height: number } | null>(null);

    useEffect(() => {
        if (!theme) {
            return;
        }

        const src = `/brand/${theme}/logo.png`;
        const image = new Image();
        image.onload = () => setLogo({ src, width: image.naturalWidth, height: image.naturalHeight });
        image.src = src;

        return () => {
            image.onload = null;
        };
    }, [theme]);

    if (logo) {
        return <img src={logo.src} alt={name} width={logo.width} height={logo.height} className={cn('store-logo w-auto', className)} />;
    }

    return <span className={cn('store-heading', textClassName)}>{name}</span>;
}
