import { cn } from '@/lib/utils';
import { useTheme } from '@/Themes/context';
import { useEntrance } from '@/Themes/use-entrance';
import { useMotion } from '@/Themes/use-motion';
import { useRef } from 'react';

interface SectionHeadingProps {
    id?: string;
    title: string;
    subtitle?: string;
    className?: string;
}

/**
 * Título de una sección del inicio, con la tipografía de títulos del tema. En un tema con corte de
 * títulos (G5) sube desde abajo de su caja al entrar en pantalla (solo transform, la caja no cambia).
 *
 * Se observa la CAJA, no el texto: el texto desplazado queda recortado por la caja y para el observer
 * nunca «entra en pantalla», así que jamás se revelaría. Y el texto se renderiza visible: solo se
 * esconde en el navegador cuando el observer ya está activo (useEntrance).
 */
export function SectionHeading({ id, title, subtitle, className }: SectionHeadingProps) {
    const { motionSet } = useTheme();
    const active = useMotion();
    const box = useRef<HTMLSpanElement>(null);
    const text = useRef<HTMLSpanElement>(null);
    const cut = motionSet?.heading && active && !active.reduced ? { duration: motionSet.heading.duration, ease: active.set.ease } : null;

    useEntrance({
        targetRef: text,
        hidden: cut ? { y: '100%' } : null,
        visible: cut ? { y: '0%' } : null,
        transition: cut ?? { duration: 0 },
        trigger: 'view',
        viewRef: box,
    });

    return (
        <div className={cn('mb-8 text-center sm:mb-10', className)}>
            <h2 id={id} className="store-heading text-3xl text-balance break-words sm:text-4xl lg:text-5xl">
                {cut ? (
                    <span ref={box} className="-mt-[0.2em] block overflow-hidden pt-[0.2em]" data-heading-cut="">
                        <span ref={text} className="block">
                            {title}
                        </span>
                    </span>
                ) : (
                    title
                )}
            </h2>
            {subtitle && <p className="text-store-muted mx-auto mt-3 max-w-xl">{subtitle}</p>}
        </div>
    );
}
