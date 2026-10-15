import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';

/**
 * «Al continuar aceptas el Aviso de privacidad y los Términos y condiciones». Cada nombre es un enlace
 * solo si esa página está publicada; si no, queda como texto.
 */
export function LegalConsent({ className }: { className?: string }) {
    const pages = usePage<SharedData>().props.footer?.pages ?? [];
    const url = (slug: string) => pages.find((page) => page.slug === slug)?.url ?? null;

    const item = (slug: string, label: string) => {
        const href = url(slug);

        return href ? (
            <Link href={href} target="_blank" className="text-store-link font-medium underline underline-offset-4">
                {label}
            </Link>
        ) : (
            <span>{label}</span>
        );
    };

    return (
        <p className={cn('text-store-muted text-sm leading-relaxed', className)} data-legal-consent="">
            Al continuar aceptas el {item('aviso-de-privacidad', 'Aviso de privacidad')} y los{' '}
            {item('terminos-y-condiciones', 'Términos y condiciones')}.
        </p>
    );
}
