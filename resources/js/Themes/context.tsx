import { createContext, useContext } from 'react';

/** Animación de entrada propia de cada tema (la usa el componente Reveal). */
export interface ThemeMotion {
    initial: { opacity: number; x?: number; y?: number };
    animate: { opacity: number; x?: number; y?: number };
    transition: { duration: number; ease: [number, number, number, number] };
}

interface ThemeContextValue {
    name: string;
    motion: ThemeMotion;
}

export const ThemeContext = createContext<ThemeContextValue | null>(null);

export function useTheme(): ThemeContextValue {
    const theme = useContext(ThemeContext);

    if (!theme) {
        throw new Error('useTheme debe usarse dentro del layout de un tema.');
    }

    return theme;
}
