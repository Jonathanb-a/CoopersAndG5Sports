import { cn } from '@/lib/utils';

interface SectionHeadingProps {
    id?: string;
    title: string;
    subtitle?: string;
    className?: string;
}

/** Título de una sección del inicio, con la tipografía de títulos del tema. */
export function SectionHeading({ id, title, subtitle, className }: SectionHeadingProps) {
    return (
        <div className={cn('mb-8 text-center sm:mb-10', className)}>
            <h2 id={id} className="store-heading text-3xl sm:text-4xl lg:text-5xl">
                {title}
            </h2>
            {subtitle && <p className="text-store-muted mx-auto mt-3 max-w-xl">{subtitle}</p>}
        </div>
    );
}
