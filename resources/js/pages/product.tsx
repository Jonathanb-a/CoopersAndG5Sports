import { AddToCartLabel } from '@/components/store/add-to-cart-label';
import { StoreButton } from '@/components/store/button';
import { ProductBuyBar } from '@/components/store/buy-bar';
import { Container } from '@/components/store/container';
import { MiniCartPanel } from '@/components/store/mini-cart';
import { ProductCard } from '@/components/store/product-card';
import { ProductGallery } from '@/components/store/product-gallery';
import { Reveal, ScrollReveal } from '@/components/store/reveal';
import { SectionHeading } from '@/components/store/section-heading';
import { SizeSelector } from '@/components/store/size-selector';
import { formatMoney, whatsappUrl } from '@/lib/store';
import { cn } from '@/lib/utils';
import StoreLayout from '@/Themes';
import { useTheme } from '@/Themes/context';
import { type ProductDetail, type SharedData, type StoreProduct } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface ProductPageProps {
    product: ProductDetail;
    related: StoreProduct[];
    whatsapp: { url: string | null; product_url: string };
}

export default function Product({ product, related, whatsapp }: ProductPageProps) {
    const { store } = usePage<SharedData>().props;

    return (
        <StoreLayout>
            <Head title={`${product.name} · ${store.name}`} />
            <ProductContent product={product} related={related} whatsapp={whatsapp} />
        </StoreLayout>
    );
}

/** Página de producto: galería, datos, talla, avisos, botones y productos relacionados. */
function ProductContent({ product, related, whatsapp }: ProductPageProps) {
    // Con una sola talla elegible no hay nada que decidir: ya viene elegida.
    const selectable = product.sizes.filter((item) => item.selectable);
    const [size, setSize] = useState<string | null>(selectable.length === 1 ? selectable[0].size : null);

    const chosen = product.sizes.find((item) => item.size === size) ?? null;

    const [adding, setAdding] = useState(false);
    const [addError, setAddError] = useState<string | null>(null);
    const [panelOpen, setPanelOpen] = useState(false);
    const { motionSet } = useTheme();
    const [added, setAdded] = useState(false);

    // Confirmación del botón (solo en temas con movimiento): se quita sola después de un momento.
    useEffect(() => {
        if (!added || !motionSet) {
            return;
        }

        const timer = window.setTimeout(() => setAdded(false), motionSet.addToCart.holdMs);

        return () => window.clearTimeout(timer);
    }, [added, motionSet]);

    // Agrega una pieza de la talla elegida. El servidor lee precio y stock de la base de datos: aquí solo va el id.
    const addToCart = () => {
        if (!chosen) {
            setAddError('Elige una talla para agregar al carrito.');

            return;
        }

        router.post(
            '/carrito',
            { variant_id: chosen.variant_id, quantity: 1 },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => {
                    setAdding(true);
                    setAddError(null);
                },
                onSuccess: () => {
                    setPanelOpen(true);
                    setAdded(true);
                },
                onError: (errors) => setAddError(errors.cart ?? 'No pudimos agregar el producto. Inténtalo de nuevo.'),
                onFinish: () => setAdding(false),
            },
        );
    };
    const soldOut = product.sizes.length > 0 && selectable.length === 0;

    // La barra de celular, sin talla elegida, trae aquí al cliente: al selector de tallas, con el foco en la primera elegible.
    const sizesRef = useRef<HTMLDivElement>(null);
    const goToSizes = () => {
        const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        sizesRef.current?.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'center' });
        sizesRef.current?.querySelector<HTMLInputElement>('input[type="radio"]:not(:disabled)')?.focus({ preventScroll: true });
    };

    // Mensaje para WhatsApp: producto, talla elegida y enlace.
    const message = [
        `Hola, me interesa ${product.brand ? `${product.brand} ` : ''}${product.name}${product.color ? ` (${product.color})` : ''}`,
        size ? `en talla ${size}` : null,
    ]
        .filter(Boolean)
        .join(' ');
    const whatsappHref = whatsapp.url ? whatsappUrl(whatsapp.url, `${message}.\n${whatsapp.product_url}`) : null;

    const backorderNotice = chosen?.status === 'backorder';

    return (
        <>
            <Container className="pt-6 sm:pt-8">
                <nav aria-label="Ruta" className="text-store-muted text-sm">
                    <ol className="flex flex-wrap items-center gap-x-2">
                        <li>
                            <Link
                                href="/catalogo"
                                className="hover:text-store-text inline-flex min-h-11 items-center underline-offset-4 hover:underline"
                            >
                                Catálogo
                            </Link>
                        </li>
                        {product.category && (
                            <li className="flex items-center gap-x-2">
                                <span aria-hidden="true">/</span>
                                <Link
                                    href={`/categoria/${product.category.slug}`}
                                    className="hover:text-store-text inline-flex min-h-11 items-center underline-offset-4 hover:underline"
                                >
                                    {product.category.name}
                                </Link>
                            </li>
                        )}
                    </ol>
                </nav>
            </Container>

            <Container as="section" className="py-6 sm:py-10 lg:py-14">
                <div className="grid gap-8 lg:grid-cols-2 lg:gap-14">
                    <Reveal>
                        <ProductGallery images={product.images} name={product.name} className="lg:sticky lg:top-32" />
                    </Reveal>

                    <Reveal delay={0.08}>
                        {product.brand && <p className="text-store-muted text-sm font-semibold tracking-wider uppercase">{product.brand}</p>}
                        <h1 className="store-heading mt-1 text-4xl text-balance break-words sm:text-5xl">{product.name}</h1>
                        {product.color && (
                            <p className="text-store-muted mt-2">
                                Color: <span className="text-store-text font-medium">{product.color}</span>
                            </p>
                        )}
                        <p className="font-store-body text-store-text mt-4 text-3xl font-bold">{formatMoney(product.price)}</p>

                        {product.description && (
                            <div className="text-store-text mt-6 space-y-3 leading-relaxed">
                                {product.description.split(/\n{2,}/).map((paragraph, position) => (
                                    <p key={position} className="whitespace-pre-line">
                                        {paragraph}
                                    </p>
                                ))}
                            </div>
                        )}

                        <div ref={sizesRef} className="border-store-border mt-8 scroll-mt-24 border-t pt-6">
                            {product.sizes.length > 0 ? (
                                <SizeSelector
                                    sizes={product.sizes}
                                    value={size}
                                    onChange={(next) => {
                                        setSize(next);
                                        setAddError(null);
                                    }}
                                />
                            ) : (
                                <p className="text-store-muted">Este producto todavía no tiene tallas registradas.</p>
                            )}

                            <div role="status" aria-live="polite">
                                {soldOut && <p className="text-store-text mt-4 font-semibold">Producto agotado por ahora.</p>}
                                {backorderNotice && (
                                    <p className="store-surface text-store-text mt-4 px-4 py-3 text-sm">
                                        <span className="font-semibold">Sobre pedido.</span>{' '}
                                        {product.backorder_days
                                            ? `Llega en ${product.backorder_days} días hábiles`
                                            : 'Te confirmamos el tiempo de entrega por WhatsApp'}
                                        .
                                    </p>
                                )}
                            </div>

                            <div className="mt-6 flex flex-col gap-3">
                                <div>
                                    <StoreButton
                                        size="lg"
                                        onClick={addToCart}
                                        disabled={soldOut || adding}
                                        aria-describedby={addError ? 'carrito-error' : undefined}
                                        // En celular el botón vive en la barra fija de abajo (ProductBuyBar).
                                        className={cn('w-full', product.sizes.length > 0 && 'max-md:hidden')}
                                    >
                                        <AddToCartLabel soldOut={soldOut} adding={adding} added={added} />
                                    </StoreButton>
                                    {addError && (
                                        <p
                                            id="carrito-error"
                                            role="alert"
                                            className={cn(
                                                'text-store-error mt-2 text-center text-sm font-medium',
                                                product.sizes.length > 0 && 'max-md:hidden',
                                            )}
                                        >
                                            {addError}
                                        </p>
                                    )}
                                </div>

                                {whatsappHref && (
                                    <StoreButton
                                        href={whatsappHref}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        variant="outline"
                                        size="lg"
                                        className="w-full"
                                    >
                                        <MessageCircle className="size-5" aria-hidden="true" />
                                        Preguntar por WhatsApp
                                    </StoreButton>
                                )}
                            </div>
                        </div>
                    </Reveal>
                </div>
            </Container>

            {related.length > 0 && (
                <Container as="section" className="py-10 sm:py-14 lg:py-20" aria-labelledby="relacionados">
                    <ScrollReveal bare>
                        <SectionHeading id="relacionados" title="También te puede gustar" />
                    </ScrollReveal>
                    <div className="grid grid-cols-2 gap-3 sm:gap-6 lg:grid-cols-4">
                        {related.map((item, position) => (
                            <ScrollReveal key={item.id} delay={position * 0.05}>
                                <ProductCard
                                    name={item.name}
                                    brand={item.brand}
                                    price={item.price}
                                    imageUrl={item.image_url}
                                    secondImageUrl={item.second_image_url}
                                    tag={item.tag}
                                    href={`/producto/${item.slug}`}
                                />
                            </ScrollReveal>
                        ))}
                    </div>
                </Container>
            )}
            <ProductBuyBar
                price={product.price}
                sizes={product.sizes}
                chosen={chosen}
                adding={adding}
                added={added}
                onAdd={addToCart}
                onChooseSize={goToSizes}
                error={addError}
            />
            <MiniCartPanel open={panelOpen} onOpenChange={setPanelOpen} />
        </>
    );
}
