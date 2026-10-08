import { cn } from '@/lib/utils';
import { useTheme } from '@/Themes/context';
import { useMotion } from '@/Themes/use-motion';
import { motion } from 'motion/react';

interface SectionHeadingProps {
    id?: string;
    title: string;
    subtitle?: string;
    className?: string;
}

/**
 * Título de una sección del inicio, con la tipografía de títulos del tema. En un tema con corte de
 * títulos (G5) sube desde abajo de su caja al entrar en pantalla (solo transform, la caja no cambia).
 */
export function SectionHeading({ id, title, subtitle, className }: SectionHeadingProps) {
    const { motionSet } = useTheme();
    const active = useMotion();
    const cut = motionSet?.heading && active && !active.reduced ? { set: active.set, duration: motionSet.heading.duration } : null;

    return (
        <div className={cn('mb-8 text-center sm:mb-10', className)}>
            <h2 id={id} className="store-heading text-3xl sm:text-4xl lg:text-5xl">
                {cut ? (
                    <span className="block overflow-hidden" data-heading-cut="">
                        <motion.span
                            className="block"
                            initial={{ y: '100%' }}
                            whileInView={{ y: '0%' }}
                            viewport={{ once: true, amount: 0.6 }}
                            transition={{ duration: cut.duration, ease: cut.set.ease }}
                        >
                            {title}
                        </motion.span>
                    </span>
                ) : (
                    title
                )}
            </h2>
            {subtitle && <p className="text-store-muted mx-auto mt-3 max-w-xl">{subtitle}</p>}
        </div>
    );
}
