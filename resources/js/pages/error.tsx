import { buttonVariants } from '@/components/store/button';
import { Container } from '@/components/store/container';
import { Reveal } from '@/components/store/reveal';
import { cn } from '@/lib/utils';
import StoreLayout from '@/Themes';
import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';

interface ErrorPageProps {
    status: number;
    title: string;
    message: string;
}

/** Páginas de error (404, 419, 500, 503) con el diseño de la tienda y un botón para volver al inicio. */
export default function ErrorPage({ status, title, message }: ErrorPageProps) {
    const { store } = usePage<SharedData>().props;

    return (
        <StoreLayout>
            <Head title={`${title} · ${store.name}`}>
                <meta name="description" content={message} />
            </Head>
            <Container as="section" className="max-w-2xl py-16 text-center sm:py-24" data-error-status={status}>
                <Reveal>
                    <p className="store-heading text-store-muted text-6xl sm:text-7xl">{status}</p>
                    <h1 className="store-heading mt-4 text-3xl text-balance break-words sm:text-5xl">{title}</h1>
                    <p className="text-store-muted mx-auto mt-4 max-w-md text-lg">{message}</p>
                    <a href="/" className={cn(buttonVariants({ size: 'lg' }), 'mt-8')}>
                        Volver al inicio
                    </a>
                </Reveal>
            </Container>
        </StoreLayout>
    );
}
