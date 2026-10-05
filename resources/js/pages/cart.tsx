import { buttonVariants, StoreButton } from '@/components/store/button';
import { Container } from '@/components/store/container';
import { Field, FormStatus } from '@/components/store/form';
import { Reveal } from '@/components/store/reveal';
import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';
import StoreLayout from '@/Themes';
import { type CartLine, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Info, ShoppingBag } from 'lucide-react';
import { type FormEvent, useId, useState } from 'react';

interface CartProps {
    cart: {
        lines: CartLine[];
        count: number;
        subtotal: string;
        discount: string;
        total: string;
        coupon: { code: string; description: string } | null;
    };
    /** Cambios desde que se agregó algo (se agotó, cambió el precio, venció el cupón...). */
    notices: string[];
    status?: string;
}

export default function Cart(props: CartProps) {
    const { store } = usePage<SharedData>().props;

    return (
        <StoreLayout>
            <Head title={`Carrito · ${store.name}`} />
            <CartContent {...props} />
        </StoreLayout>
    );
}

/** Página del carrito: líneas, cupón y totales estimados. Todo viene calculado por el servidor. */
function CartContent({ cart, notices, status }: CartProps) {
    const { errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const [confirmingClear, setConfirmingClear] = useState(false);
    const empty = cart.lines.length === 0;

    return (
        <Container className="py-8 sm:py-12 lg:py-16">
            <Reveal>
                <h1 className="store-heading text-4xl sm:text-5xl lg:text-6xl">Tu carrito</h1>
                {!empty && (
                    <p className="text-store-muted mt-2">
                        {cart.count} {cart.count === 1 ? 'pieza' : 'piezas'}
                    </p>
                )}
            </Reveal>

            <div className="mt-6 space-y-3" aria-live="polite">
                {notices.length > 0 && (
                    <div role="status" className="store-surface px-4 py-3">
                        <p className="flex items-center gap-2 text-sm font-semibold">
                            <Info className="size-4 shrink-0" aria-hidden="true" />
                            Actualizamos tu carrito
                        </p>
                        <ul className="mt-2 list-disc space-y-1 pl-6 text-sm">
                            {notices.map((notice) => (
                                <li key={notice}>{notice}</li>
                            ))}
                        </ul>
                    </div>
                )}
                {errors.cart && (
                    <p role="alert" className="rounded-store border border-red-600 px-4 py-3 text-sm font-medium text-red-600 dark:text-red-400">
                        {errors.cart}
                    </p>
                )}
                <FormStatus>{status}</FormStatus>
            </div>

            {empty ? (
                <div className="store-surface mt-8 px-6 py-14 text-center sm:py-20">
                    <ShoppingBag className="text-store-muted mx-auto size-10" aria-hidden="true" />
                    <h2 className="store-heading mt-4 text-3xl sm:text-4xl">Tu carrito está vacío</h2>
                    <p className="text-store-muted mx-auto mt-3 max-w-md">Cuando agregues productos los verás aquí.</p>
                    <Link href="/catalogo" className={cn(buttonVariants({ size: 'lg' }), 'mt-8')}>
                        Ver el catálogo
                    </Link>
                </div>
            ) : (
                <div className="mt-8 grid gap-8 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start xl:grid-cols-[minmax(0,1fr)_24rem]">
                    <section aria-label="Productos en tu carrito">
                        <ul className="divide-store-border border-store-border divide-y border-y">
                            {cart.lines.map((line) => (
                                <CartLineItem key={line.variant_id} line={line} />
                            ))}
                        </ul>

                        <div className="mt-4 flex flex-wrap items-center gap-3 text-sm">
                            <Link href="/catalogo" className="text-store-link font-medium underline underline-offset-4">
                                Seguir comprando
                            </Link>
                            {confirmingClear ? (
                                <span role="alert" className="flex flex-wrap items-center gap-2">
                                    <span className="font-semibold">¿Vaciar el carrito?</span>
                                    <StoreButton
                                        size="sm"
                                        onClick={() => router.delete('/carrito', { preserveScroll: true, onFinish: () => setConfirmingClear(false) })}
                                    >
                                        Sí, vaciar
                                    </StoreButton>
                                    <StoreButton size="sm" variant="outline" onClick={() => setConfirmingClear(false)}>
                                        Cancelar
                                    </StoreButton>
                                </span>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => setConfirmingClear(true)}
                                    className="text-store-muted hover:text-store-text underline underline-offset-4"
                                >
                                    Vaciar carrito
                                </button>
                            )}
                        </div>
                    </section>

                    <aside aria-label="Resumen" className="store-surface p-5 sm:p-6 lg:sticky lg:top-32">
                        <h2 className="store-heading text-2xl sm:text-3xl">Resumen</h2>
                        <CouponBox coupon={cart.coupon} />

                        <dl className="mt-6 space-y-2 text-sm">
                            <div className="flex justify-between">
                                <dt>Subtotal</dt>
                                <dd>{formatMoney(cart.subtotal)}</dd>
                            </div>
                            {cart.coupon && (
                                <div className="flex justify-between">
                                    <dt>Descuento ({cart.coupon.code})</dt>
                                    <dd>− {formatMoney(cart.discount)}</dd>
                                </div>
                            )}
                            <div className="border-store-border flex items-baseline justify-between border-t pt-3 text-lg font-bold">
                                <dt>Total estimado</dt>
                                <dd>{formatMoney(cart.total)}</dd>
                            </div>
                        </dl>
                        <p className="text-store-muted mt-2 text-xs">El envío se calcula en el siguiente paso.</p>

                        <Link href="/checkout" className={cn(buttonVariants({ size: 'lg' }), 'mt-6 w-full')}>
                            Continuar con la compra
                        </Link>
                    </aside>
                </div>
            )}
        </Container>
    );
}

function CartLineItem({ line }: { line: CartLine }) {
    const selectId = useId();
    // 1 hasta el máximo que se puede llevar de esa talla (siempre incluye la cantidad actual).
    const options = Array.from({ length: Math.max(line.max_quantity, line.quantity) }, (_, index) => index + 1);

    const changeQuantity = (quantity: number) => router.patch(`/carrito/${line.variant_id}`, { quantity }, { preserveScroll: true });

    return (
        <li className="flex gap-4 py-5">
            <Link
                href={`/producto/${line.slug}`}
                className="bg-store-photo rounded-store focus-visible:outline-store-accent relative size-24 shrink-0 overflow-hidden focus-visible:outline-2 sm:size-28"
                aria-label={`Ver ${line.name}`}
            >
                {line.image_url ? (
                    <img src={line.image_url} alt="" className="absolute inset-0 m-auto max-h-full max-w-full object-contain" />
                ) : (
                    <span className="store-heading text-store-muted absolute inset-0 flex items-center justify-center text-4xl" aria-hidden="true">
                        {line.name.charAt(0)}
                    </span>
                )}
            </Link>

            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-start justify-between gap-x-4">
                    <div className="min-w-0">
                        {line.brand && <p className="text-store-muted text-xs font-semibold tracking-wider uppercase">{line.brand}</p>}
                        <h3 className="font-store-body text-base font-semibold">
                            <Link href={`/producto/${line.slug}`} className="hover:text-store-link">
                                {line.name}
                            </Link>
                        </h3>
                        <p className="text-store-muted mt-0.5 text-sm">
                            Talla {line.size}
                            {line.color && ` · ${line.color}`}
                        </p>
                        <p className="mt-1 text-sm">{formatMoney(line.unit_price)} c/u</p>
                    </div>
                    <p className="text-base font-bold">{formatMoney(line.line_total)}</p>
                </div>

                {line.backorder_quantity > 0 && (
                    <p role="note" className="bg-store-surface border-store-border rounded-store mt-2 inline-block border px-3 py-1.5 text-sm">
                        <span className="font-semibold">
                            {line.backorder_quantity} {line.backorder_quantity === 1 ? 'pieza' : 'piezas'} sobre pedido.
                        </span>{' '}
                        {line.backorder_days ? `Llega en ${line.backorder_days} días hábiles.` : 'Te confirmamos el tiempo de entrega.'}
                    </p>
                )}

                <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2">
                    <div className="flex items-center gap-2">
                        <label htmlFor={selectId} className="text-sm">
                            Cantidad
                        </label>
                        <select
                            id={selectId}
                            value={line.quantity}
                            onChange={(event) => changeQuantity(Number(event.target.value))}
                            className="rounded-store border-store-border bg-store-surface text-store-text focus-visible:outline-store-accent h-11 border-[length:var(--store-border-width)] px-3 text-sm focus-visible:outline-2"
                        >
                            {options.map((quantity) => (
                                <option key={quantity} value={quantity}>
                                    {quantity}
                                </option>
                            ))}
                        </select>
                    </div>
                    <button
                        type="button"
                        onClick={() => router.delete(`/carrito/${line.variant_id}`, { preserveScroll: true })}
                        className="text-store-link focus-visible:outline-store-accent min-h-11 text-sm font-medium underline underline-offset-4 focus-visible:outline-2"
                        aria-label={`Quitar ${line.name}, talla ${line.size}`}
                    >
                        Quitar
                    </button>
                </div>
            </div>
        </li>
    );
}

/** Campo «Código de descuento» con aplicar y quitar. El servidor dice si vale y por qué no. */
function CouponBox({ coupon }: { coupon: { code: string; description: string } | null }) {
    const form = useForm({ code: '' });

    const apply = (event: FormEvent) => {
        event.preventDefault();
        form.post('/carrito/cupon', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    if (coupon) {
        return (
            <div className="mt-5">
                <p className="text-sm font-semibold">Código de descuento</p>
                <div className="border-store-border rounded-store mt-1.5 flex items-center justify-between gap-3 border-[length:var(--store-border-width)] px-3 py-2">
                    <p className="min-w-0 text-sm">
                        <span className="font-bold">{coupon.code}</span>
                        <span className="text-store-muted block">{coupon.description}</span>
                    </p>
                    <StoreButton
                        size="sm"
                        variant="outline"
                        onClick={() => router.delete('/carrito/cupon', { preserveScroll: true })}
                        aria-label={`Quitar el cupón ${coupon.code}`}
                    >
                        Quitar
                    </StoreButton>
                </div>
            </div>
        );
    }

    return (
        <form onSubmit={apply} className="mt-5" noValidate>
            <div className="flex items-start gap-2">
                <Field
                    wrapperClassName="min-w-0 flex-1"
                    label="Código de descuento"
                    name="code"
                    autoComplete="off"
                    autoCapitalize="characters"
                    spellCheck={false}
                    maxLength={50}
                    value={form.data.code}
                    onChange={(event) => form.setData('code', event.target.value)}
                    error={form.errors.code}
                />
                <StoreButton
                    type="submit"
                    variant="outline"
                    disabled={form.processing || form.data.code.trim() === ''}
                    className="mt-[26px] shrink-0"
                >
                    Aplicar
                </StoreButton>
            </div>
        </form>
    );
}
