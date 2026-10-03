import { BannerCarousel } from '@/components/store/banner-carousel';
import { CategoryCard } from '@/components/store/category-card';
import { Container } from '@/components/store/container';
import { ProductCard } from '@/components/store/product-card';
import { Reveal, ScrollReveal } from '@/components/store/reveal';
import { SectionHeading } from '@/components/store/section-heading';
import StoreLayout from '@/Themes';
import { type CategoryCard as CategoryCardData, type NewestProduct, type SharedData, type StoreBanner } from '@/types';
import { Head, usePage } from '@inertiajs/react';

interface HomeProps {
    banners: StoreBanner[];
    categoryCards: CategoryCardData[];
    newest: NewestProduct[];
    about: string | null;
}

/** Inicio de la tienda: carrusel, accesos a categorías, lo más nuevo y «Quiénes somos». */
export default function Home({ banners, categoryCards, newest, about }: HomeProps) {
    const { store } = usePage<SharedData>().props;

    return (
        <StoreLayout>
            <Head title={store.name} />

            {banners.length > 0 ? (
                <>
                    <h1 className="sr-only">Bienvenido a {store.name}</h1>
                    <Container className="pt-4 sm:pt-6">
                        <BannerCarousel banners={banners} />
                    </Container>
                </>
            ) : (
                <section className="store-paint">
                    <Container className="py-20 text-center sm:py-28 lg:py-40">
                        <Reveal>
                            <p className="text-store-muted text-sm font-semibold tracking-[0.2em] uppercase">Tienda en línea</p>
                        </Reveal>
                        <Reveal delay={0.08}>
                            <h1 className="store-heading mt-4 text-5xl sm:text-6xl lg:text-8xl">Bienvenido a {store.name}</h1>
                        </Reveal>
                    </Container>
                </section>
            )}

            {categoryCards.length > 0 && (
                <Container as="section" className="py-12 sm:py-16 lg:py-24" aria-labelledby="categorias">
                    <ScrollReveal>
                        <SectionHeading id="categorias" title="Compra por categoría" />
                    </ScrollReveal>
                    <div className="grid grid-cols-2 gap-3 sm:gap-6 lg:grid-cols-4">
                        {categoryCards.map((category, position) => (
                            <ScrollReveal key={category.id} delay={Math.min(position, 7) * 0.05}>
                                <CategoryCard category={category} />
                            </ScrollReveal>
                        ))}
                    </div>
                </Container>
            )}

            {newest.length > 0 && (
                <Container as="section" className="py-12 sm:py-16 lg:py-24">
                    <ScrollReveal>
                        <SectionHeading title="Lo más nuevo" subtitle="Lo último que llegó a la tienda." />
                    </ScrollReveal>
                    <div className="grid grid-cols-2 gap-3 sm:gap-6 lg:grid-cols-4">
                        {newest.map((product, position) => (
                            <ScrollReveal key={product.id} delay={Math.min(position, 7) * 0.05}>
                                <ProductCard name={product.name} price={product.price} imageUrl={product.image_url} tag={product.tag} />
                            </ScrollReveal>
                        ))}
                    </div>
                </Container>
            )}

            {about && (
                <section className="border-store-border bg-store-surface border-y">
                    <Container className="max-w-3xl py-14 text-center sm:py-20">
                        <ScrollReveal>
                            <SectionHeading title="Quiénes somos" />
                            <div className="text-store-text space-y-4 text-lg leading-relaxed">
                                {about.split(/\n{2,}/).map((paragraph, position) => (
                                    <p key={position} className="whitespace-pre-line">
                                        {paragraph}
                                    </p>
                                ))}
                            </div>
                        </ScrollReveal>
                    </Container>
                </section>
            )}
        </StoreLayout>
    );
}
