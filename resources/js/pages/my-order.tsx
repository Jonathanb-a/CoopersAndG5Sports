import { buttonVariants, StoreButton } from '@/components/store/button';
import { Container } from '@/components/store/container';
import { DeliveryAddress, PickupInfo, type PickupInfoData } from '@/components/store/delivery-info';
import { FormStatus, TextAreaField } from '@/components/store/form';
import { ExpiringBadge, OrderStatusBadge, type OrderStatusValue } from '@/components/store/order-status-badge';
import { Reveal } from '@/components/store/reveal';
import { Tag } from '@/components/store/tag';
import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';
import StoreLayout from '@/Themes';
import { type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import { type FormEvent, type ReactNode, useState } from 'react';

interface OrderItem {
    id: number;
    name: string;
    size: string;
    quantity: number;
    unit_price: string;
    line_total: string;
    is_backorder: boolean;
    image_url: string | null;
}

interface MyOrderProps {
    order: {
        number: string;
        status: OrderStatusValue;
        status_label: string;
        expiring_label: string | null;
        date: string;
        delivery_label: string;
        /** "transfer" o "cash". */
        payment_method: string;
        shipping_address: Record<string, string | null> | null;
        notes: string | null;
        coupon: string | null;
        subtotal: string;
        discount: string;
        shipping_cost: string;
        total: string;
        tracking: { carrier: string | null; number: string } | null;
        expires_at: string | null;
        expires_at_text: string | null;
        items: OrderItem[];
        /** Solo estados y fechas: sin notas internas ni nombres del personal. */
        timeline: { label: string; date: string }[];
    };
    can: { cancel: boolean; request_cancellation: boolean };
    /** Entrega a domicilio (pedidos locales): fecha acordada o aviso de intento fallido. */
    delivery: { scheduled: { date: string; window: string | null } | null; failed: boolean; reschedule_url: string | null };
    /** Dónde y cuándo recoger; null si el pedido no se recoge en tienda. */
    pickup: PickupInfoData | null;
    cancellationRequested: boolean;
    bankInfo: string | null;
    whatsapp: { receipt_url: string | null; question_url: string | null };
    status?: string;
}

export default function MyOrder(props: MyOrderProps) {
    const { store } = usePage<SharedData>().props;

    return (
        <StoreLayout>
            <Head title={`Pedido ${props.order.number} · ${store.name}`} />
            <MyOrderContent {...props} />
        </StoreLayout>
    );
}

function Section({ id, title, children }: { id: string; title: string; children: ReactNode }) {
    return (
        <section aria-labelledby={id} className="store-surface p-5 sm:p-7">
            <h2 id={id} className="store-heading text-2xl sm:text-3xl">
                {title}
            </h2>
            <div className="mt-4">{children}</div>
        </section>
    );
}

function WhatsAppButton({ href, children, primary = false }: { href: string; children: ReactNode; primary?: boolean }) {
    return (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            className={cn(buttonVariants({ variant: primary ? 'primary' : 'outline', size: 'lg' }), 'w-full sm:w-auto')}
        >
            <MessageCircle className="size-5" aria-hidden="true" />
            {children}
        </a>
    );
}

/** Detalle de un pedido del cliente: estado, pago (si está apartado), líneas, entrega, historial y cancelación. */
function MyOrderContent({ order, can, delivery, pickup, cancellationRequested, bankInfo, whatsapp, status }: MyOrderProps) {
    const { errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const address = order.shipping_address;
    const reserved = order.status === 'apartado';
    // En efectivo se paga al recibir o recoger: el recordatorio vale hasta que el pedido se entrega o se cancela.
    const cash = order.payment_method === 'cash';
    const cashPending = cash && ['apartado', 'pagado', 'enviado'].includes(order.status);

    return (
        <Container className="max-w-3xl py-8 sm:py-12 lg:py-16">
            <Reveal>
                <p className="text-sm">
                    <Link href="/mi-cuenta/pedidos" className="text-store-link font-medium underline underline-offset-4">
                        Mis pedidos
                    </Link>
                </p>
                <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2">
                    <h1 className="store-heading text-4xl sm:text-5xl">Pedido {order.number}</h1>
                    <OrderStatusBadge status={order.status} label={order.status_label} />
                    {order.expiring_label && <ExpiringBadge label={order.expiring_label} />}
                </div>
                <p className="text-store-muted mt-2">{order.date}</p>
            </Reveal>

            <div className="mt-6 space-y-3" aria-live="polite">
                <FormStatus>{status}</FormStatus>
                {errors.order && (
                    <p role="alert" className="rounded-store border border-red-600 px-4 py-3 text-sm font-medium text-red-600 dark:text-red-400">
                        {errors.order}
                    </p>
                )}
                {cancellationRequested && (
                    <p role="status" className="store-surface px-4 py-3 text-sm">
                        <span className="font-semibold">Solicitud en revisión.</span> Pediste cancelar este pedido; te avisamos en cuanto la
                        revisemos.
                    </p>
                )}
            </div>

            <div className="mt-6 space-y-6">
                {cashPending && (
                    <Section id="pago-efectivo" title="Pago en efectivo">
                        <p className="leading-relaxed">
                            Pagas <strong>{formatMoney(order.total)}</strong> en efectivo{' '}
                            {pickup ? 'cuando pases por tu pedido a la tienda' : 'al recibir tu pedido'}.
                        </p>
                        <p className="border-store-border bg-store-bg rounded-store mt-3 border-[length:var(--store-border-width)] px-4 py-3 font-medium">
                            Ten el efectivo a la mano {pickup ? 'cuando recojas tu pedido' : 'a la hora de la entrega'}. No necesitas hacer
                            transferencia ni mandar comprobante.
                        </p>
                        {order.expires_at_text && (
                            <p className="text-store-muted mt-3 text-sm">
                                Te contactamos por WhatsApp para confirmar tu pedido. Si no se confirma antes del{' '}
                                <strong className="text-store-text">{order.expires_at_text}</strong>, el apartado se cancela y las piezas vuelven a la
                                venta.
                            </p>
                        )}
                    </Section>
                )}

                {reserved && !cash && (
                    <Section id="pago" title="Cómo pagar">
                        <ol className="list-decimal space-y-3 pl-5 leading-relaxed">
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
                                En el concepto escribe tu número de pedido: <strong className="whitespace-nowrap">{order.number}</strong>.
                            </li>
                            {order.expires_at_text && (
                                <li>
                                    Paga antes del{' '}
                                    <strong>
                                        <time dateTime={order.expires_at ?? undefined}>{order.expires_at_text}</time>
                                    </strong>
                                    . Después de esa hora el apartado se cancela.
                                </li>
                            )}
                        </ol>
                        {whatsapp.receipt_url && (
                            <div className="mt-5">
                                <WhatsAppButton href={whatsapp.receipt_url} primary>
                                    Enviar comprobante por WhatsApp
                                </WhatsAppButton>
                            </div>
                        )}
                    </Section>
                )}

                {delivery.scheduled && (
                    <Section id="entrega-programada" title="Entrega programada">
                        <p className="text-lg font-semibold first-letter:uppercase">{delivery.scheduled.date}</p>
                        {delivery.scheduled.window && <p className="text-store-muted mt-1">Horario: {delivery.scheduled.window}</p>}
                    </Section>
                )}

                {delivery.failed && (
                    <Section id="entrega-fallida" title="No pudimos entregarte">
                        <p>No pudimos entregarte. Te contactaremos para reprogramar.</p>
                        {delivery.reschedule_url && (
                            <div className="mt-4">
                                <WhatsAppButton href={delivery.reschedule_url} primary>
                                    Reprogramar por WhatsApp
                                </WhatsAppButton>
                            </div>
                        )}
                    </Section>
                )}

                {order.tracking && (
                    <Section id="rastreo" title="Rastreo">
                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            {order.tracking.carrier && (
                                <div>
                                    <dt className="text-store-muted">Paquetería</dt>
                                    <dd className="font-semibold">{order.tracking.carrier}</dd>
                                </div>
                            )}
                            <div>
                                <dt className="text-store-muted">Número de guía</dt>
                                <dd className="font-semibold break-all select-all">{order.tracking.number}</dd>
                            </div>
                        </dl>
                    </Section>
                )}

                <Section id="productos" title="Tu pedido">
                    <ul className="divide-store-border border-store-border divide-y border-y">
                        {order.items.map((item) => (
                            <li key={item.id} className="flex gap-3 py-3">
                                <div className="bg-store-photo rounded-store relative size-16 shrink-0 overflow-hidden">
                                    {item.image_url ? (
                                        <img
                                            src={item.image_url}
                                            alt=""
                                            loading="lazy"
                                            className="absolute inset-0 m-auto max-h-full max-w-full object-contain"
                                        />
                                    ) : (
                                        <span
                                            className="store-heading text-store-muted absolute inset-0 flex items-center justify-center text-2xl"
                                            aria-hidden="true"
                                        >
                                            {item.name.charAt(0)}
                                        </span>
                                    )}
                                </div>
                                <div className="min-w-0 flex-1 text-sm">
                                    <p className="font-medium">{item.name}</p>
                                    <p className="text-store-muted">
                                        Talla {item.size} · {item.quantity} × {formatMoney(item.unit_price)}
                                    </p>
                                    {item.is_backorder && <Tag kind="backorder" className="mt-1" />}
                                </div>
                                <p className="text-sm font-bold">{formatMoney(item.line_total)}</p>
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
                                <dt>Descuento{order.coupon ? ` (cupón ${order.coupon})` : ''}</dt>
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
                </Section>

                <Section id="entrega" title="Entrega">
                    <p className="font-semibold">{order.delivery_label}</p>
                    {pickup && <PickupInfo pickup={pickup} className="mt-3" />}
                    {!pickup && address?.street && <DeliveryAddress address={address} />}
                    {order.notes && (
                        <p className="text-store-muted mt-3 text-sm">
                            <span className="text-store-text font-semibold">Tus notas:</span> {order.notes}
                        </p>
                    )}
                </Section>

                {order.timeline.length > 0 && (
                    <Section id="historial" title="Seguimiento">
                        <ol className="border-store-border ml-2 space-y-4 border-l-2 pl-5">
                            {order.timeline.map((step, position) => (
                                <li key={position} className="relative">
                                    <span
                                        className={cn(
                                            'absolute top-1.5 -left-[1.6rem] size-3 rounded-full',
                                            position === order.timeline.length - 1 ? 'bg-store-accent' : 'bg-store-border',
                                        )}
                                        aria-hidden="true"
                                    />
                                    <p className="font-semibold">{step.label}</p>
                                    <p className="text-store-muted text-sm">{step.date}</p>
                                </li>
                            ))}
                        </ol>
                    </Section>
                )}

                <CancelBox number={order.number} can={can} />

                {/* La duda por WhatsApp está siempre, en cualquier estado del pedido. */}
                <Section id="ayuda" title="¿Necesitas ayuda?">
                    <p className="text-store-muted text-sm">
                        {cancellationRequested
                            ? 'Si quieres agregar algo a tu solicitud, escríbenos por WhatsApp.'
                            : 'Para dudas, cambios o devoluciones, escríbenos por WhatsApp.'}
                    </p>
                    {whatsapp.question_url && (
                        <div className="mt-4">
                            <WhatsAppButton href={whatsapp.question_url}>Tengo una duda sobre mi pedido</WhatsAppButton>
                        </div>
                    )}
                </Section>
            </div>
        </Container>
    );
}

interface CancelBoxProps {
    number: string;
    can: MyOrderProps['can'];
}

/**
 * Apartado: cancelar (con confirmación y motivo opcional). Pagado: solo solicitar la cancelación.
 * Enviado, entregado o cancelado: nada; queda la sección de ayuda por WhatsApp, que va siempre.
 */
function CancelBox({ number, can }: CancelBoxProps) {
    const [open, setOpen] = useState(false);
    const cancelForm = useForm({ reason: '' });
    const requestForm = useForm({ note: '' });

    const cancel = (event: FormEvent) => {
        event.preventDefault();
        cancelForm.post(`/mi-cuenta/pedidos/${number}/cancelar`, { preserveScroll: true, onSuccess: () => setOpen(false) });
    };

    const request = (event: FormEvent) => {
        event.preventDefault();
        requestForm.post(`/mi-cuenta/pedidos/${number}/solicitar-cancelacion`, { preserveScroll: true, onSuccess: () => setOpen(false) });
    };

    if (can.cancel) {
        return (
            <Section id="cancelar" title="¿Ya no lo quieres?">
                {open ? (
                    <form onSubmit={cancel} className="space-y-4" noValidate>
                        <p role="alert" className="font-semibold">
                            ¿Cancelar el pedido {number}? Tus piezas vuelven a la venta y esto no se puede deshacer.
                        </p>
                        <TextAreaField
                            label="Motivo (opcional)"
                            name="reason"
                            maxLength={500}
                            value={cancelForm.data.reason}
                            onChange={(event) => cancelForm.setData('reason', event.target.value)}
                            error={cancelForm.errors.reason}
                        />
                        <div className="flex flex-wrap gap-2">
                            <StoreButton type="submit" disabled={cancelForm.processing}>
                                {cancelForm.processing ? 'Cancelando…' : 'Sí, cancelar pedido'}
                            </StoreButton>
                            <StoreButton variant="outline" onClick={() => setOpen(false)}>
                                No, conservarlo
                            </StoreButton>
                        </div>
                    </form>
                ) : (
                    <>
                        <p className="text-store-muted text-sm">Mientras no lo pagues puedes cancelarlo aquí mismo.</p>
                        <StoreButton variant="outline" className="mt-4" onClick={() => setOpen(true)}>
                            Cancelar pedido
                        </StoreButton>
                    </>
                )}
            </Section>
        );
    }

    if (can.request_cancellation) {
        return (
            <Section id="cancelar" title="¿Necesitas cancelarlo?">
                {open ? (
                    <form onSubmit={request} className="space-y-4" noValidate>
                        <TextAreaField
                            label="Cuéntanos por qué"
                            name="note"
                            maxLength={500}
                            hint="Opcional. Nos ayuda a revisar tu solicitud."
                            value={requestForm.data.note}
                            onChange={(event) => requestForm.setData('note', event.target.value)}
                            error={requestForm.errors.note}
                        />
                        <div className="flex flex-wrap gap-2">
                            <StoreButton type="submit" disabled={requestForm.processing}>
                                {requestForm.processing ? 'Enviando…' : 'Enviar solicitud'}
                            </StoreButton>
                            <StoreButton variant="outline" onClick={() => setOpen(false)}>
                                Cerrar
                            </StoreButton>
                        </div>
                    </form>
                ) : (
                    <>
                        <p className="text-store-muted text-sm">
                            Tu pedido ya está pagado, así que no se cancela solo: nos mandas la solicitud y la revisamos.
                        </p>
                        <StoreButton variant="outline" className="mt-4" onClick={() => setOpen(true)}>
                            Solicitar cancelación
                        </StoreButton>
                    </>
                )}
            </Section>
        );
    }

    return null;
}
