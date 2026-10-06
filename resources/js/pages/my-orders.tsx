import { buttonVariants } from '@/components/store/button';
import { Container } from '@/components/store/container';
import { OrderStatusBadge, type OrderStatusValue } from '@/components/store/order-status-badge';
import { Reveal } from '@/components/store/reveal';
import { formatMoney } from '@/lib/store';
import { cn } from '@/lib/utils';
import StoreLayout from '@/Themes';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, Package } from 'lucide-react';

interface OrderRow {
    number: string;
    date: string;
    total: string;
    status: OrderStatusValue;
    status_label: string;
}

interface MyOrdersProps {
    orders: OrderRow[];
    pagination: { current: number; last: number; total: number; prev_url: string | null; next_url: string | null };
}

export default function MyOrders(props: MyOrdersProps) {
    const { store } = usePage<SharedData>().props;

    return (
        <StoreLayout>
            <Head title={`Mis pedidos · ${store.name}`} />
            <MyOrdersContent {...props} />
        </StoreLayout>
    );
}

/** Pedidos del cliente en esta tienda, del más reciente al más viejo. */
function MyOrdersContent({ orders, pagination }: MyOrdersProps) {
    const { store } = usePage<SharedData>().props;

    return (
        <Container className="max-w-3xl py-8 sm:py-12 lg:py-16">
            <Reveal>
                <h1 className="store-heading text-4xl sm:text-5xl lg:text-6xl">Mis pedidos</h1>
                <p className="text-store-muted mt-2">Tus pedidos en {store.name}.</p>
            </Reveal>

            {orders.length === 0 ? (
                <div className="store-surface mt-8 px-6 py-14 text-center sm:py-20">
                    <Package className="text-store-muted mx-auto size-10" aria-hidden="true" />
                    <h2 className="store-heading mt-4 text-3xl sm:text-4xl">Aún no tienes pedidos</h2>
                    <p className="text-store-muted mx-auto mt-3 max-w-md">Cuando apartes algo en {store.name} lo verás aquí.</p>
                    <Link href="/catalogo" className={cn(buttonVariants({ size: 'lg' }), 'mt-8')}>
                        Ver el catálogo
                    </Link>
                </div>
            ) : (
                <>
                    <ul className="mt-8 space-y-3">
                        {orders.map((order) => (
                            <li key={order.number}>
                                <Link
                                    href={`/mi-cuenta/pedidos/${order.number}`}
                                    className="store-surface store-transition store-lift focus-visible:outline-store-accent flex items-center gap-4 p-4 focus-visible:outline-2 sm:p-5"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="font-store-body text-lg font-bold">{order.number}</p>
                                        <p className="text-store-muted text-sm">{order.date}</p>
                                    </div>
                                    <div className="flex flex-col items-end gap-2">
                                        <OrderStatusBadge status={order.status} label={order.status_label} />
                                        <p className="font-bold">{formatMoney(order.total)}</p>
                                    </div>
                                    <ChevronRight className="text-store-muted size-5 shrink-0" aria-hidden="true" />
                                </Link>
                            </li>
                        ))}
                    </ul>

                    {pagination.last > 1 && (
                        <nav aria-label="Paginación" className="mt-8 flex items-center justify-between gap-4">
                            {pagination.prev_url ? (
                                <Link href={pagination.prev_url} rel="prev" className={buttonVariants({ variant: 'outline', size: 'sm' })}>
                                    Anteriores
                                </Link>
                            ) : (
                                <span />
                            )}
                            <p className="text-store-muted text-sm">
                                Página {pagination.current} de {pagination.last}
                            </p>
                            {pagination.next_url ? (
                                <Link href={pagination.next_url} rel="next" className={buttonVariants({ variant: 'outline', size: 'sm' })}>
                                    Siguientes
                                </Link>
                            ) : (
                                <span />
                            )}
                        </nav>
                    )}
                </>
            )}
        </Container>
    );
}
