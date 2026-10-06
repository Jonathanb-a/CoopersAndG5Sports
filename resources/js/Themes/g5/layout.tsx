import { Container, HeaderActions, MobileMenu, SearchField, StoreLogo, WhatsAppLink } from '@/components/store';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { MotionConfig } from 'motion/react';
import { type ReactNode, useState } from 'react';
import { ThemeContext, type ThemeMotion } from '../context';
import './theme.css';

// Entradas rápidas y con empuje.
const motion: ThemeMotion = {
    initial: { opacity: 0, x: -32 },
    animate: { opacity: 1, x: 0 },
    transition: { duration: 0.28, ease: [0.34, 1.56, 0.64, 1] },
};

const FONTS = 'https://fonts.bunny.net/css?family=anton:400|barlow:400,500,600,700';

/** Layout de Sports G5: cancha y calle. Fondo oscuro, acento turquesa, títulos gruesos en mayúsculas. */
export default function G5Layout({ children }: { children: ReactNode }) {
    const { store, categories } = usePage<SharedData>().props;
    const [menuOpen, setMenuOpen] = useState(false);
    const [searchOpen, setSearchOpen] = useState(false);
    const [root, setRoot] = useState<HTMLDivElement | null>(null);

    // Catálogo completo y una página por categoría.
    const navLinks = [
        { id: 'catalogo', name: 'Catálogo', href: '/catalogo' },
        ...categories.map((category) => ({ id: category.id, name: category.name, href: `/categoria/${category.slug}` })),
    ];
    const navLink = (item: (typeof navLinks)[number], className: string, onClick?: () => void) => (
        <Link href={item.href} className={className} onClick={onClick}>
            {item.name}
        </Link>
    );

    return (
        <ThemeContext.Provider value={{ name: 'g5', motion, root }}>
            <MotionConfig reducedMotion="user">
                <Head>
                    <link rel="preconnect" href="https://fonts.bunny.net" />
                    <link rel="stylesheet" href={FONTS} />
                </Head>

                <div ref={setRoot} data-theme="g5" className="bg-store-bg font-store-body text-store-text flex min-h-screen flex-col antialiased">
                    <a
                        href="#contenido"
                        className="focus:bg-store-accent focus:text-store-action-text sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:px-4 focus:py-2 focus:font-bold"
                    >
                        Saltar al contenido
                    </a>

                    <header className="border-store-accent bg-store-surface sticky top-0 z-30 border-b-[length:var(--store-border-width)]">
                        <Container className="flex min-h-16 items-center gap-3 py-2 lg:min-h-20 lg:gap-6">
                            <button
                                type="button"
                                aria-label="Abrir menú"
                                onClick={() => setMenuOpen(true)}
                                className="text-store-text hover:text-store-link focus-visible:outline-store-accent -ml-2 inline-flex size-11 items-center justify-center focus-visible:outline-2 lg:hidden"
                            >
                                <Menu className="size-7" />
                            </button>

                            <Link href="/" aria-label={`${store.name}, inicio`} className="mr-auto lg:mr-0">
                                <StoreLogo theme={store.theme} name={store.name} textClassName="text-2xl text-store-accent lg:text-4xl" />
                            </Link>

                            <nav aria-label="Categorías" className="hidden flex-1 lg:block">
                                <ul className="flex flex-wrap gap-x-5 gap-y-0.5">
                                    {navLinks.map((item) => (
                                        <li key={item.id}>
                                            {navLink(
                                                item,
                                                'store-heading inline-block text-lg text-store-text transition-transform duration-150 hover:-skew-x-6 hover:text-store-link',
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </nav>

                            <SearchField className="hidden w-48 lg:flex xl:w-60" />

                            <HeaderActions className="-mr-2" searchOpen={searchOpen} onToggleSearch={() => setSearchOpen((open) => !open)} />
                        </Container>

                        {searchOpen && (
                            <div className="border-store-border border-t-2 lg:hidden">
                                <Container className="py-3">
                                    <SearchField />
                                </Container>
                            </div>
                        )}
                    </header>

                    <MobileMenu open={menuOpen} onOpenChange={setMenuOpen} container={root} title="Menú de categorías" className="bg-store-surface">
                        <p className="store-heading text-store-accent mt-2 text-4xl">{store.name}</p>
                        <nav aria-label="Categorías" className="mt-8 flex-1 overflow-y-auto">
                            <ul className="flex flex-col gap-1">
                                {navLinks.map((item) => (
                                    <li key={item.id}>
                                        {navLink(
                                            item,
                                            'store-heading block border-l-4 border-store-border py-3 pl-4 text-2xl hover:border-store-accent hover:text-store-link',
                                            () => setMenuOpen(false),
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </nav>
                        <WhatsAppLink
                            url={store.whatsapp_url}
                            className="text-store-link mt-6 inline-flex items-center gap-2 font-bold underline-offset-4 hover:underline"
                        />
                    </MobileMenu>

                    <main id="contenido" className="flex-1">
                        {children}
                    </main>

                    <footer className="store-paint border-store-accent bg-store-surface border-t-[length:var(--store-border-width)]">
                        <Container className="grid gap-8 py-12 sm:grid-cols-2 sm:items-center">
                            <div>
                                <p className="store-heading text-store-accent text-5xl sm:text-6xl">{store.name}</p>
                                <p className="text-store-muted mt-3">Calzado y artículos deportivos para la cancha y la calle.</p>
                            </div>
                            <div className="sm:text-right">
                                <WhatsAppLink
                                    url={store.whatsapp_url}
                                    className="store-transition store-lift bg-store-action text-store-action-text hover:bg-store-action-hover inline-flex h-12 items-center gap-2 border-[length:var(--store-border-width)] border-transparent px-6 font-bold shadow-(--store-shadow)"
                                />
                            </div>
                        </Container>
                        <div className="border-store-border border-t-2">
                            <Container className="text-store-muted py-4 text-sm">
                                © {new Date().getFullYear()} {store.name}. Todos los derechos reservados.
                            </Container>
                        </div>
                    </footer>
                </div>
            </MotionConfig>
        </ThemeContext.Provider>
    );
}
