import { StoreButton } from '@/components/store/button';
import { Container } from '@/components/store/container';
import { Field, TextAreaField } from '@/components/store/form';
import { Reveal } from '@/components/store/reveal';
import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';
import StoreLayout from '@/Themes';
import { type CartLine, type CustomerAddress, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { type FormEvent, type ReactNode } from 'react';

interface DeliveryOption {
    value: string;
    label: string;
    description: string;
    cost: string;
    needs_address: boolean;
    note: string | null;
}

interface CheckoutProps {
    cart: { lines: CartLine[]; count: number; subtotal: string; discount: string; coupon: string | null };
    deliveryOptions: DeliveryOption[];
    addresses: CustomerAddress[];
    phone: string | null;
    reserveHours: number;
    /** Aviso cuando el cliente ya tiene el máximo de apartados sin pagar; con él no se puede apartar. */
    reservationLimit: string | null;
}

const emptyAddress = { street: '', neighborhood: '', city: '', state: '', zip: '', notes: '' };

type AddressKey = keyof typeof emptyAddress;

export default function Checkout(props: CheckoutProps) {
    const { store } = usePage<SharedData>().props;

    return (
        <StoreLayout>
            <Head title={`Finalizar compra · ${store.name}`} />
            <CheckoutContent {...props} />
        </StoreLayout>
    );
}

function Step({ number, title, children }: { number: number; title: string; children: ReactNode }) {
    const id = `paso-${number}`;

    return (
        <section aria-labelledby={id} className="store-surface p-5 sm:p-7">
            <h2 id={id} className="store-heading flex items-baseline gap-3 text-2xl sm:text-3xl">
                <span className="text-store-muted text-lg" aria-hidden="true">
                    {number}.
                </span>
                {title}
            </h2>
            <div className="mt-5">{children}</div>
        </section>
    );
}

/** Checkout: resumen, entrega, contacto y total. Los importes se muestran aquí, pero el servidor los vuelve a calcular. */
function CheckoutContent({ cart, deliveryOptions, addresses, phone, reserveHours, reservationLimit }: CheckoutProps) {
    const form = useForm<{
        delivery_method: string;
        address_id: number | null;
        address: typeof emptyAddress;
        phone: string;
        notes: string;
    }>({
        delivery_method: deliveryOptions.length === 1 ? deliveryOptions[0].value : '',
        address_id: addresses[0]?.id ?? null,
        address: emptyAddress,
        phone: phone ?? '',
        notes: '',
    });

    // Los errores de la dirección llegan como "address.street", "address.zip"...
    const errors = form.errors as Record<string, string | undefined>;

    const delivery = deliveryOptions.find((option) => option.value === form.data.delivery_method) ?? null;
    const needsAddress = delivery?.needs_address ?? false;
    const newAddress = form.data.address_id === null;

    const subtotal = Number(cart.subtotal);
    const discount = Number(cart.discount);
    const shipping = delivery ? Number(delivery.cost) : 0;
    const total = subtotal - discount + shipping;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/checkout', { preserveScroll: true });
    };

    const addressField = (name: AddressKey) => ({
        name: `address[${name}]`,
        value: form.data.address[name],
        onChange: (event: { target: { value: string } }) => form.setData('address', { ...form.data.address, [name]: event.target.value }),
        error: errors[`address.${name}`],
    });

    const choice = (selected: boolean) =>
        cn(
            'rounded-store flex cursor-pointer items-start gap-3 border-[length:var(--store-border-width)] p-4 transition-colors',
            selected ? 'border-store-accent bg-store-bg' : 'border-store-border hover:border-store-text',
        );

    return (
        <Container className="py-8 sm:py-12 lg:py-16">
            <Reveal>
                <h1 className="store-heading text-4xl sm:text-5xl lg:text-6xl">Finalizar compra</h1>
                <p className="text-store-muted mt-2">
                    <Link href="/carrito" className="text-store-link font-medium underline underline-offset-4">
                        Volver al carrito
                    </Link>
                </p>
            </Reveal>

            <form
                onSubmit={submit}
                noValidate
                className="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start xl:grid-cols-[minmax(0,1fr)_24rem]"
            >
                <div className="space-y-6">
                    <Step number={1} title="Tu pedido">
                        <ul className="divide-store-border border-store-border divide-y border-y">
                            {cart.lines.map((line) => (
                                <li key={line.variant_id} className="flex gap-3 py-3">
                                    <div className="bg-store-photo rounded-store relative size-16 shrink-0 overflow-hidden">
                                        {line.image_url ? (
                                            <img
                                                src={line.image_url}
                                                alt=""
                                                className="absolute inset-0 m-auto max-h-full max-w-full object-contain"
                                            />
                                        ) : (
                                            <span
                                                className="store-heading text-store-muted absolute inset-0 flex items-center justify-center text-2xl"
                                                aria-hidden="true"
                                            >
                                                {line.name.charAt(0)}
                                            </span>
                                        )}
                                    </div>
                                    <div className="min-w-0 flex-1 text-sm">
                                        <p className="font-medium">{line.name}</p>
                                        <p className="text-store-muted">
                                            Talla {line.size} · {line.quantity} × {formatMoney(line.unit_price)}
                                        </p>
                                        {line.backorder_quantity > 0 && (
                                            <p className="mt-1">
                                                <span className="font-semibold">
                                                    {line.backorder_quantity} {line.backorder_quantity === 1 ? 'pieza' : 'piezas'} sobre pedido.
                                                </span>{' '}
                                                {line.backorder_days
                                                    ? `Llega en ${line.backorder_days} días hábiles.`
                                                    : 'Te confirmamos el tiempo de entrega.'}
                                            </p>
                                        )}
                                    </div>
                                    <p className="text-sm font-bold">{formatMoney(line.line_total)}</p>
                                </li>
                            ))}
                        </ul>
                        <dl className="mt-4 space-y-1 text-sm">
                            <div className="flex justify-between">
                                <dt>Subtotal</dt>
                                <dd>{formatMoney(cart.subtotal)}</dd>
                            </div>
                            {cart.coupon && (
                                <div className="flex justify-between">
                                    <dt>Cupón {cart.coupon}</dt>
                                    <dd>− {formatMoney(cart.discount)}</dd>
                                </div>
                            )}
                        </dl>
                    </Step>

                    <Step number={2} title="Entrega">
                        <fieldset>
                            <legend className="sr-only">Método de entrega</legend>
                            <div className="space-y-3">
                                {deliveryOptions.map((option) => (
                                    <label key={option.value} className={choice(form.data.delivery_method === option.value)}>
                                        <input
                                            type="radio"
                                            name="delivery_method"
                                            value={option.value}
                                            checked={form.data.delivery_method === option.value}
                                            onChange={() => form.setData('delivery_method', option.value)}
                                            className="accent-store-action mt-1 size-5 shrink-0"
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="flex flex-wrap items-baseline justify-between gap-x-3 font-semibold">
                                                {option.label}
                                                <span>{Number(option.cost) === 0 ? 'Gratis' : formatMoney(option.cost)}</span>
                                            </span>
                                            <span className="text-store-muted block text-sm">{option.description}</span>
                                            {option.note && <span className="mt-1 block text-sm font-medium">{option.note}.</span>}
                                        </span>
                                    </label>
                                ))}
                            </div>
                            {errors.delivery_method && (
                                <p role="alert" className="mt-2 text-sm font-medium text-red-600 dark:text-red-400">
                                    {errors.delivery_method}
                                </p>
                            )}
                        </fieldset>

                        {needsAddress && (
                            <fieldset className="border-store-border mt-6 border-t pt-5">
                                <legend className="font-store-body text-base font-semibold">Dirección de entrega</legend>
                                <div className="mt-3 space-y-3">
                                    {addresses.map((address) => (
                                        <label key={address.id} className={choice(form.data.address_id === address.id)}>
                                            <input
                                                type="radio"
                                                name="address_id"
                                                value={address.id}
                                                checked={form.data.address_id === address.id}
                                                onChange={() => form.setData('address_id', address.id)}
                                                className="accent-store-action mt-1 size-5 shrink-0"
                                            />
                                            <span className="text-sm leading-relaxed">
                                                {address.street}, {address.neighborhood}, C.P. {address.zip}
                                                <br />
                                                {address.city}, {address.state}
                                                {address.notes && <span className="text-store-muted block">Referencias: {address.notes}</span>}
                                            </span>
                                        </label>
                                    ))}
                                    {addresses.length > 0 && (
                                        <label className={choice(newAddress)}>
                                            <input
                                                type="radio"
                                                name="address_id"
                                                value=""
                                                checked={newAddress}
                                                onChange={() => form.setData('address_id', null)}
                                                className="accent-store-action mt-1 size-5 shrink-0"
                                            />
                                            <span className="font-semibold">Usar una dirección nueva</span>
                                        </label>
                                    )}
                                </div>
                                {(errors.address_id || errors.address) && (
                                    <p role="alert" className="mt-2 text-sm font-medium text-red-600 dark:text-red-400">
                                        {errors.address_id ?? errors.address}
                                    </p>
                                )}

                                {newAddress && (
                                    <div className="mt-4 space-y-4">
                                        <Field label="Calle y número" autoComplete="address-line1" required {...addressField('street')} />
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <Field label="Colonia" autoComplete="address-line2" required {...addressField('neighborhood')} />
                                            <Field
                                                label="Código postal"
                                                autoComplete="postal-code"
                                                inputMode="numeric"
                                                maxLength={5}
                                                required
                                                {...addressField('zip')}
                                            />
                                            <Field label="Ciudad" autoComplete="address-level2" required {...addressField('city')} />
                                            <Field label="Estado" autoComplete="address-level1" required {...addressField('state')} />
                                        </div>
                                        <Field label="Referencias" hint="Color de la casa, entre qué calles… (opcional)" {...addressField('notes')} />
                                        <p className="text-store-muted text-sm">Guardamos esta dirección en tu cuenta para la próxima vez.</p>
                                    </div>
                                )}
                            </fieldset>
                        )}
                    </Step>

                    <Step number={3} title="Contacto">
                        <div className="space-y-4">
                            <Field
                                label="Teléfono de contacto"
                                type="tel"
                                name="phone"
                                autoComplete="tel"
                                inputMode="tel"
                                required
                                hint="Para avisarte de tu pedido."
                                value={form.data.phone}
                                onChange={(event) => form.setData('phone', event.target.value)}
                                error={errors.phone}
                            />
                            <TextAreaField
                                label="Notas (opcional)"
                                name="notes"
                                maxLength={1000}
                                hint="Algo que debamos saber de tu pedido."
                                value={form.data.notes}
                                onChange={(event) => form.setData('notes', event.target.value)}
                                error={errors.notes}
                            />
                        </div>
                    </Step>
                </div>

                <aside aria-label="Total" className="store-surface p-5 sm:p-6 lg:sticky lg:top-32">
                    <h2 className="store-heading flex items-baseline gap-3 text-2xl sm:text-3xl">
                        <span className="text-store-muted text-lg" aria-hidden="true">
                            4.
                        </span>
                        Total
                    </h2>
                    <dl className="mt-5 space-y-2 text-sm">
                        <div className="flex justify-between">
                            <dt>Subtotal</dt>
                            <dd>{formatMoney(subtotal)}</dd>
                        </div>
                        {cart.coupon && (
                            <div className="flex justify-between">
                                <dt>Descuento ({cart.coupon})</dt>
                                <dd>− {formatMoney(discount)}</dd>
                            </div>
                        )}
                        <div className="flex justify-between">
                            <dt>Envío</dt>
                            <dd>{delivery ? (shipping === 0 ? 'Gratis' : formatMoney(shipping)) : 'Elige la entrega'}</dd>
                        </div>
                        <div className="border-store-border flex items-baseline justify-between border-t pt-3 text-lg font-bold">
                            <dt>Total</dt>
                            <dd aria-live="polite">{formatMoney(total)}</dd>
                        </div>
                    </dl>

                    {reservationLimit && (
                        <div role="alert" className="rounded-store mt-6 border border-red-600 px-4 py-3 text-sm">
                            <p className="font-medium text-red-600 dark:text-red-400">{reservationLimit}</p>
                            <Link href="/mi-cuenta/pedidos" className="text-store-link mt-2 inline-block font-medium underline underline-offset-4">
                                Ver mis pedidos
                            </Link>
                        </div>
                    )}

                    <StoreButton type="submit" size="lg" className="mt-6 w-full" disabled={form.processing || reservationLimit !== null}>
                        {form.processing ? 'Apartando…' : 'Apartar pedido'}
                    </StoreButton>
                    <p className="text-store-muted mt-3 text-xs leading-relaxed">
                        Pagas por transferencia. Apartamos tus piezas durante {reserveHours} horas; al confirmar verás los datos para pagar.
                    </p>
                    {form.hasErrors && (
                        <p role="alert" className="mt-3 text-sm font-medium text-red-600 dark:text-red-400">
                            Revisa los datos marcados antes de continuar.
                        </p>
                    )}
                </aside>
            </form>
        </Container>
    );
}
