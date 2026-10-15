import { Container } from '@/components/store/container';
import { Reveal } from '@/components/store/reveal';
import StoreLayout from '@/Themes';
import { Head } from '@inertiajs/react';

interface StorePageProps {
    page: { slug: string; title: string; html: string };
    meta: { title: string; description: string };
}

/**
 * Página de contenido o legal de la tienda (/pagina/{slug}). El HTML ya viene saneado por el servidor
 * (SafeHtml: sin scripts ni atributos peligrosos) y el aspecto lo pone el tema (.store-prose).
 */
export default function StorePage({ page, meta }: StorePageProps) {
    return (
        <StoreLayout>
            <Head title={meta.title}>
                <meta name="description" content={meta.description} />
            </Head>
            <Container as="section" className="max-w-3xl py-10 sm:py-14 lg:py-20">
                <Reveal>
                    <h1 className="store-heading text-4xl text-balance break-words sm:text-5xl">{page.title}</h1>
                </Reveal>
                <Reveal delay={0.08}>
                    <div className="store-prose mt-8" dangerouslySetInnerHTML={{ __html: page.html }} />
                </Reveal>
            </Container>
        </StoreLayout>
    );
}
