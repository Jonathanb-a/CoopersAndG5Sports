import { whatsappUrl } from '@/lib/store';
import { MessageCircle } from 'lucide-react';
import { type ComponentProps } from 'react';

interface WhatsAppLinkProps extends Omit<ComponentProps<'a'>, 'href'> {
    phone: string | null;
    label?: string;
}

/** Enlace a WhatsApp de la tienda. No se muestra si la tienda no tiene número. */
export function WhatsAppLink({ phone, label = 'Escríbenos por WhatsApp', children, ...props }: WhatsAppLinkProps) {
    const href = phone ? whatsappUrl(phone, 'Hola, me gustaría más información.') : null;

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
