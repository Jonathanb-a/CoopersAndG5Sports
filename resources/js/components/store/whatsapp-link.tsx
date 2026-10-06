import { whatsappUrl } from '@/lib/store';
import { MessageCircle } from 'lucide-react';
import { type ComponentProps } from 'react';

interface WhatsAppLinkProps extends Omit<ComponentProps<'a'>, 'href'> {
    /** Enlace wa.me de la tienda (store.whatsapp_url). */
    url: string | null;
    label?: string;
}

/** Enlace a WhatsApp de la tienda. No se muestra si la tienda no tiene número. */
export function WhatsAppLink({ url, label = 'Escríbenos por WhatsApp', children, ...props }: WhatsAppLinkProps) {
    const href = url ? whatsappUrl(url, 'Hola, me gustaría más información.') : null;

    if (!href) {
        return null;
    }

    return (
        <a href={href} target="_blank" rel="noopener noreferrer" {...props}>
            {children ?? (
                <>
                    <MessageCircle className="size-5" aria-hidden="true" />
                    {label}
                </>
            )}
        </a>
    );
}
