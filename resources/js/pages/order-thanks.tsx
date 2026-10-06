import { buttonVariants } from '@/components/store/button';
import { Container } from '@/components/store/container';
import { Reveal } from '@/components/store/reveal';
import { Tag } from '@/components/store/tag';
import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';
import StoreLayout from '@/Themes';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';

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
        coupon: string | null;
        subtotal: string;
        discount: string;
        shipping_cost: string;
        total: string;
        expires_at: string | null;
        expires_at_text: string | null;
        items: OrderItem[];
    };
    bankInfo: string | null;
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
function OrderThanksContent({ order, bankInfo }: OrderThanksProps) {
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
                    </ol>
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
                    {address && (
                        <address className="text-store-text mt-2 text-sm leading-relaxed not-italic">
                            {address.name}
                            <br />
                            {address.street}, {address.neighborhood}, C.P. {address.zip}
                            <br />
                            {address.city}, {address.state}
                            {address.notes && <span className="text-store-muted block">Referencias: {address.notes}</span>}
                            {address.phone && <span className="block">Tel. {address.phone}</span>}
                        </address>
                    )}
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
