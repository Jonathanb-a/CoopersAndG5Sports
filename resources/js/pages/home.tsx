import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';

interface Product {
    id: number;
    name: string;
    price: string;
}

const money = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' });

export default function Home({ products }: { products: Product[] }) {
    const { store } = usePage<SharedData>().props;

    return (
        <>
            <Head title="Inicio" />
            <main className="mx-auto max-w-2xl p-6">
                <h1 className="mb-4 text-2xl font-semibold">{store.name}</h1>
                <ul className="divide-y">
                    {products.map((product) => (
                        <li key={product.id} className="flex justify-between py-2">
                            <span>{product.name}</span>
                            <span>{money.format(Number(product.price))}</span>
                        </li>
                    ))}
                </ul>
            </main>
        </>
    );
}
