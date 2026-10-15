import { cn } from '@/lib/utils';
import { type SharedData, type StoreFooter } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Clock, MapPin } from 'lucide-react';
import { WhatsAppLink } from './whatsapp-link';

const EMPTY: StoreFooter = { pages: [], address: null, hours: null, map_url: null };

interface FooterDetailsProps {
    /** Estilo de los títulos de columna (del tema). */
    headingClassName: string;
    /** Estilo de los enlaces (del tema); siempre con alto táctil de 44 px. */
    linkClassName: string;
    /** Muestra la columna de WhatsApp (G5 ya tiene su botón grande en el pie). */
    showWhatsApp?: boolean;
    className?: string;
}

/**
 * Columnas del pie de página: enlaces a las páginas publicadas, la tienda (dirección y horario) y
 * WhatsApp. Lo que no existe no se muestra: una página sin publicar no tiene enlace y una columna
 * vacía no sale. El aspecto lo pone cada tema con las clases que recibe.
 */
export function FooterDetails({ headingClassName, linkClassName, showWhatsApp = true, className }: FooterDetailsProps) {
    const { store, footer = EMPTY } = usePage<SharedData>().props;
    const link = cn('inline-flex min-h-11 items-center gap-2', linkClassName);
    const hasPlace = Boolean(footer.address || footer.hours);

    return (
        <div className={cn('grid gap-8 sm:grid-cols-3', className)} data-footer-details="">
            {footer.pages.length > 0 && (
                <nav aria-label="Información de la tienda">
                    <p className={headingClassName}>Información</p>
                    <ul className="mt-2">
                        {footer.pages.map((page) => (
                            <li key={page.slug}>
                                <Link href={page.url} className={link}>
                                    {page.title}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </nav>
            )}

            {hasPlace && (
                <div>
                    <p className={headingClassName}>Visítanos</p>
                    <address className="mt-3 space-y-2 not-italic">
                        {footer.address && (
                            <p className="flex items-start gap-2">
                                <MapPin className={cn('size-5 shrink-0', footer.map_url ? 'mt-3' : 'mt-0.5')} aria-hidden="true" />
                                {footer.map_url ? (
                                    <a href={footer.map_url} target="_blank" rel="noopener noreferrer" className={link}>
                                        {footer.address}
                                    </a>
                                ) : (
                                    <span>{footer.address}</span>
                                )}
                            </p>
                        )}
                        {footer.hours && (
                            <p className="flex items-start gap-2">
                                <Clock className="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                                <span>{footer.hours}</span>
                            </p>
                        )}
                    </address>
                </div>
            )}

            {showWhatsApp && store.whatsapp_url && (
                <div>
                    <p className={headingClassName}>Contacto</p>
                    <WhatsAppLink url={store.whatsapp_url} className={cn(link, 'mt-2')} />
                </div>
            )}
        </div>
    );
}
