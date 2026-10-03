import { Container } from '@/components/store';
import { Reveal } from '@/components/store/reveal';
import StoreLayout from '@/Themes';
import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';

/** Inicio de la tienda: por ahora solo la bienvenida dentro del layout del tema. */
export default function Home() {
    const { store } = usePage<SharedData>().props;

    return (
        <StoreLayout>
            <Head title={store.name} />

            <section className="store-paint">
                <Container className="py-20 text-center sm:py-28 lg:py-40">
                    <Reveal>
                        <p className="text-store-muted text-sm font-semibold tracking-[0.2em] uppercase">Tienda en línea</p>
                    </Reveal>
                    <Reveal delay={0.08}>
                        <h1 className="store-heading mt-4 text-5xl sm:text-6xl lg:text-8xl">Bienvenido a {store.name}</h1>
                    </Reveal>
                    <Reveal delay={0.16}>
                        <p className="text-store-muted mx-auto mt-6 max-w-xl text-lg">
                            Estamos preparando todo para ti. Muy pronto podrás conocer nuestro catálogo.
                        </p>
                    </Reveal>
                </Container>
            </section>
        </StoreLayout>
    );
}
