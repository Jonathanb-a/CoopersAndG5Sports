import { cn } from '@/lib/utils';
import { Clock, MapPin } from 'lucide-react';

export interface PickupInfoData {
    /** False mientras la tienda no capture dirección ni horario. */
    configured: boolean;
    address: string | null;
    hours: string | null;
    map_url: string | null;
    notes: string | null;
}

/**
 * Dónde y cuándo se recoge un pedido en la tienda. Si la tienda aún no captura sus datos, avisa que
 * se confirman por WhatsApp. Lo usan el checkout, la página de gracias y el detalle del pedido.
 */
export function PickupInfo({ pickup, className }: { pickup: PickupInfoData; className?: string }) {
    return (
        <div
            className={cn(
                'border-store-border bg-store-bg rounded-store border-[length:var(--store-border-width)] px-4 py-4 text-sm leading-relaxed',
                className,
            )}
        >
            {pickup.configured ? (
                <dl className="space-y-3">
                    {pickup.address && (
                        <div className="flex gap-3">
                            <MapPin className="text-store-link mt-0.5 size-5 shrink-0" aria-hidden="true" />
                            <div className="min-w-0">
                                <dt className="text-store-muted">Dirección de la tienda</dt>
                                <dd className="font-semibold break-words">{pickup.address}</dd>
                            </div>
                        </div>
                    )}
                    {pickup.hours && (
                        <div className="flex gap-3">
                            <Clock className="text-store-link mt-0.5 size-5 shrink-0" aria-hidden="true" />
                            <div className="min-w-0">
                                <dt className="text-store-muted">Horario para recoger</dt>
                                <dd className="font-semibold break-words">{pickup.hours}</dd>
                            </div>
                        </div>
                    )}
                    {pickup.notes && (
                        <div>
                            <dt className="text-store-muted">Indicaciones</dt>
                            <dd className="break-words whitespace-pre-line">{pickup.notes}</dd>
                        </div>
                    )}
                </dl>
            ) : (
                <p className="font-medium">Te confirmamos dirección y horario por WhatsApp.</p>
            )}
            {pickup.map_url && (
                <a
                    href={pickup.map_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-store-link mt-3 inline-flex min-h-11 items-center gap-2 font-semibold underline underline-offset-4"
                >
                    <MapPin className="size-4" aria-hidden="true" />
                    Ver en el mapa
                </a>
            )}
        </div>
    );
}

interface DeliveryAddressProps {
    address: Record<string, string | null>;
    showPhone?: boolean;
}

/**
 * A dónde va un pedido con entrega: quién recibe y el punto de entrega. Los pedidos de entrega local
 * solo traen el punto y la ciudad; los anteriores traen además colonia, código postal y estado.
 */
export function DeliveryAddress({ address, showPhone = false }: DeliveryAddressProps) {
    const place = [address.street, address.neighborhood, address.zip ? `C.P. ${address.zip}` : null].filter(Boolean).join(', ');
    const region = [address.city, address.state].filter(Boolean).join(', ');

    return (
        <address className="text-store-text mt-2 text-sm leading-relaxed not-italic">
            {address.name && <span className="block">Recibe: {address.name}</span>}
            <span className="block font-semibold break-words">{place}</span>
            {region && <span className="block">{region}</span>}
            {address.notes && <span className="text-store-muted block break-words">Referencias: {address.notes}</span>}
            {showPhone && address.phone && <span className="block">Tel. {address.phone}</span>}
        </address>
    );
}
