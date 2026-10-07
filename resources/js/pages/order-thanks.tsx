import { buttonVariants } from '@/components/store/button';
import { Container } from '@/components/store/container';
import { DeliveryAddress, PickupInfo, type PickupInfoData } from '@/components/store/delivery-info';
import { Reveal } from '@/components/store/reveal';
import { Tag } from '@/components/store/tag';
import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';
import StoreLayout from '@/Themes';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { CircleCheck, MessageCircle } from 'lucide-react';

interface OrderItem {
    id: number;
    name: string;
    size: string;
    quantity: number;
    unit_price: string;
    line_total: string;
    is_backorder: boolean;
}

interface OrderThanksProps {
    order: {
        number: string;
        status: string;
        delivery_method: string;
        delivery_label: string;
        shipping_address: Record<string, string | null> | null;
        notes: string | null;
        /** Día y hora que pidió el cliente para su entrega local. */
        delivery_preference: string | null;
        coupon: string | null;
        subtotal: string;
        discount: string;
        shipping_cost: string;
        total: string;
        expires_at: string | null;
        expires_at_text: string | null;
        items: OrderItem[];
    };
    /** Dónde y cuándo recoger; null si el pedido no se recoge en tienda. */
    pickup: PickupInfoData | null;
    bankInfo: string | null;
    /** Enlaces wa.me de la tienda del pedido con el mensaje ya escrito; null si la tienda no tiene WhatsApp. */
    whatsapp: { receipt_url: string | null; question_url: string | null };
}

export default function OrderThanks(props: OrderThanksProps) {
    const { store } = usePage<SharedData>().props;

    return (
        <StoreLayout>
            <Head title={`Pedido ${props.order.number} · ${store.name}`} />
            <OrderThanksContent {...props} />
        </StoreLayout>
    );
}

/** Confirmación del pedido apartado: número, cómo pagar por transferencia, fecha límite y resumen. */
function OrderThanksContent({ order, pickup, bankInfo, whatsapp }: OrderThanksProps) {
    const address = order.shipping_address;

    return (
        <Container className="max-w-3xl py-8 sm:py-12 lg:py-16">
            <Reveal className="text-center">
                <CircleCheck className="text-store-link mx-auto size-12" aria-hidden="true" />
                <h1 className="store-heading mt-4 text-4xl sm:text-5xl">¡Apartamos tu pedido!</h1>
                <p className="text-store-muted mt-3">Tu número de pedido es</p>
                <p className="store-heading mt-1 text-4xl sm:text-5xl">{order.number}</p>
            </Reveal>

            <div className="mt-8 space-y-6">
                <section aria-labelledby="pago" className="store-surface p-5 sm:p-7">
                    <h2 id="pago" className="store-heading text-2xl sm:text-3xl">
                        Cómo pagar
                    </h2>
                    <ol className="mt-4 list-decimal space-y-3 pl-5 leading-relaxed">
                        <li>
                            Haz una transferencia por <strong>{formatMoney(order.total)}</strong> a esta cuenta:
                            {bankInfo ? (
                                <p className="border-store-border bg-store-bg rounded-store mt-2 border-[length:var(--store-border-width)] px-4 py-3 font-medium whitespace-pre-line">
                                    {bankInfo}
                                </p>
                            ) : (
                                <span className="text-store-muted block">Te compartimos los datos de la cuenta por WhatsApp.</span>
                            )}
                        </li>
                        <li>
                            En el concepto de la transferencia escribe tu número de pedido:{' '}
                            <strong className="whitespace-nowrap">{order.number}</strong>.
                        </li>
                        {order.expires_at_text && (
                            <li>
                                Paga antes del{' '}
                                <strong>
                                    <time dateTime={order.expires_at ?? undefined}>{order.expires_at_text}</time>
                                </strong>
                                . Después de esa hora el apartado se cancela y las piezas vuelven a la venta.
                            </li>
                        )}
                        {whatsapp.receipt_url && <li>Mándanos tu comprobante por WhatsApp para confirmar tu pago.</li>}
                    </ol>

                    {(whatsapp.receipt_url || whatsapp.question_url) && (
                        <div className="mt-6 flex flex-col gap-3 sm:flex-row">
                            {whatsapp.receipt_url && (
                                <a
                                    href={whatsapp.receipt_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className={cn(buttonVariants({ size: 'lg' }), 'w-full sm:w-auto')}
                                >
                                    <MessageCircle className="size-5" aria-hidden="true" />
                                    Enviar comprobante por WhatsApp
                                </a>
                            )}
                            {whatsapp.question_url && (
                                <a
                                    href={whatsapp.question_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className={cn(buttonVariants({ variant: 'outline', size: 'lg' }), 'w-full sm:w-auto')}
                                >
                                    Tengo dudas
                                </a>
                            )}
                        </div>
                    )}
                </section>

                <section aria-labelledby="resumen" className="store-surface p-5 sm:p-7">
                    <h2 id="resumen" className="store-heading text-2xl sm:text-3xl">
                        Resumen
                    </h2>
                    <ul className="divide-store-border border-store-border mt-4 divide-y border-y">
                        {order.items.map((item) => (
                            <li key={item.id} className="flex items-start justify-between gap-4 py-3 text-sm">
                                <div className="min-w-0">
                                    <p className="font-medium">{item.name}</p>
                                    <p className="text-store-muted">
                                        Talla {item.size} · {item.quantity} × {formatMoney(item.unit_price)}
                                    </p>
                                    {item.is_backorder && <Tag kind="backorder" className="mt-1" />}
                                </div>
                                <p className="font-bold">{formatMoney(item.line_total)}</p>
                            </li>
                        ))}
                    </ul>
                    <dl className="mt-4 space-y-2 text-sm">
                        <div className="flex justify-between">
                            <dt>Subtotal</dt>
                            <dd>{formatMoney(order.subtotal)}</dd>
                        </div>
                        {Number(order.discount) > 0 && (
                            <div className="flex justify-between">
                                <dt>Descuento{order.coupon ? ` (${order.coupon})` : ''}</dt>
                                <dd>− {formatMoney(order.discount)}</dd>
                            </div>
                        )}
                        <div className="flex justify-between">
                            <dt>Envío</dt>
                            <dd>{Number(order.shipping_cost) === 0 ? 'Gratis' : formatMoney(order.shipping_cost)}</dd>
                        </div>
                        <div className="border-store-border flex items-baseline justify-between border-t pt-3 text-lg font-bold">
                            <dt>Total</dt>
                            <dd>{formatMoney(order.total)}</dd>
                        </div>
                    </dl>
                </section>

                <section aria-labelledby="entrega" className="store-surface p-5 sm:p-7">
                    <h2 id="entrega" className="store-heading text-2xl sm:text-3xl">
                        Entrega
                    </h2>
                    <p className="mt-3 font-semibold">{order.delivery_label}</p>
                    {pickup && <PickupInfo pickup={pickup} className="mt-3" />}
                    {address && <DeliveryAddress address={address} preference={order.delivery_preference} showPhone />}
                    {order.notes && (
                        <p className="text-store-muted mt-3 text-sm">
                            <span className="text-store-text font-semibold">Notas:</span> {order.notes}
                        </p>
                    )}
                </section>

                <p className="text-center">
                    <Link href="/catalogo" className={cn(buttonVariants({ variant: 'outline' }))}>
                        Seguir comprando
                    </Link>
                </p>
            </div>
        </Container>
    );
}
