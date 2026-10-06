import { Container, HeaderActions, MobileMenu, SearchField, StoreLogo, WhatsAppLink } from '@/components/store';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { MotionConfig } from 'motion/react';
import { type ReactNode, useState } from 'react';
import { ThemeContext, type ThemeMotion } from '../context';
import './theme.css';

// Aparecer y deslizar suave, nada que rebote.
const motion: ThemeMotion = {
    initial: { opacity: 0, y: 14 },
    animate: { opacity: 1, y: 0 },
    transition: { duration: 0.7, ease: [0.22, 1, 0.36, 1] },
};

const FONTS = 'https://fonts.bunny.net/css?family=cormorant-garamond:500,600|inter:400,500,600,700';

/** Layout de Copper's: boutique. Logo centrado, mucho aire, líneas finas. */
export default function CoppersLayout({ children }: { children: ReactNode }) {
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
        <ThemeContext.Provider value={{ name: 'coppers', motion, root }}>
            <MotionConfig reducedMotion="user">
                <Head>
                    <link rel="preconnect" href="https://fonts.bunny.net" />
                    <link rel="stylesheet" href={FONTS} />
                </Head>

                <div
                    ref={setRoot}
                    data-theme="coppers"
                    className="bg-store-bg font-store-body text-store-text flex min-h-screen flex-col antialiased"
                >
                    <a
                        href="#contenido"
                        className="focus:bg-store-surface sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:px-4 focus:py-2"
                    >
                        Saltar al contenido
                    </a>

                    <header className="border-store-border bg-store-bg/95 sticky top-0 z-30 border-b backdrop-blur">
                        <Container className="grid h-16 grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2 sm:gap-4 lg:h-24">
                            <div className="flex items-center">
                                <button
                                    type="button"
                                    aria-label="Abrir menú"
                                    onClick={() => setMenuOpen(true)}
                                    className="text-store-text hover:text-store-link focus-visible:outline-store-accent -ml-2 inline-flex size-11 items-center justify-center focus-visible:outline-2 lg:hidden"
                                >
                                    <Menu className="size-6" />
                                </button>
                                <SearchField className="hidden w-64 lg:flex" />
                            </div>

                            <Link href="/" aria-label={`${store.name}, inicio`} className="text-center">
                                <StoreLogo theme={store.theme} name={store.name} textClassName="text-2xl lg:text-4xl" />
                            </Link>

                            <HeaderActions
                                className="-mr-2 justify-end"
                                searchOpen={searchOpen}
                                onToggleSearch={() => setSearchOpen((open) => !open)}
                            />
                        </Container>

                        {searchOpen && (
                            <div className="border-store-border border-t lg:hidden">
                                <Container className="py-3">
                                    <SearchField />
                                </Container>
                            </div>
                        )}

                        <nav aria-label="Categorías" className="border-store-border hidden border-t lg:block">
                            <Container>
                                <ul className="flex flex-wrap justify-center gap-x-10 gap-y-2 py-4">
                                    {navLinks.map((item) => (
                                        <li key={item.id}>
                                            {navLink(
                                                item,
                                                'text-xs font-medium tracking-[0.18em] text-store-text uppercase underline-offset-8 transition-colors hover:text-store-link hover:underline',
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </Container>
                        </nav>
                    </header>

                    <MobileMenu open={menuOpen} onOpenChange={setMenuOpen} container={root} title="Menú de categorías">
                        <p className="store-heading mt-2 text-3xl">{store.name}</p>
                        <nav aria-label="Categorías" className="mt-8 flex-1 overflow-y-auto">
                            <ul className="divide-store-border border-store-border divide-y border-y">
                                {navLinks.map((item) => (
                                    <li key={item.id}>
                                        {navLink(item, 'block py-4 text-sm tracking-[0.16em] uppercase hover:text-store-link', () =>
                                            setMenuOpen(false),
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </nav>
                        <WhatsAppLink
                            url={store.whatsapp_url}
                            className="text-store-link mt-6 inline-flex items-center gap-2 text-sm font-medium underline-offset-4 hover:underline"
                        />
                    </MobileMenu>

                    <main id="contenido" className="flex-1">
                        {children}
                    </main>

                    <footer className="border-store-border bg-store-surface border-t">
                        <Container className="grid gap-10 py-14 text-center sm:grid-cols-2 sm:text-left">
                            <div>
                                <p className="store-heading text-3xl">{store.name}</p>
                                <p className="text-store-muted mt-3 text-sm">Piezas originales, seleccionadas con cuidado.</p>
                            </div>
                            <div className="sm:text-right">
                                <p className="text-store-muted text-xs font-medium tracking-[0.18em] uppercase">Contacto</p>
                                <WhatsAppLink
                                    url={store.whatsapp_url}
                                    className="text-store-link mt-3 inline-flex items-center gap-2 text-sm font-medium underline-offset-4 hover:underline"
                                />
                            </div>
                        </Container>
                        <div className="border-store-border border-t">
                            <Container className="text-store-muted py-5 text-center text-xs">
                                © {new Date().getFullYear()} {store.name}. Todos los derechos reservados.
                            </Container>
                        </div>
                    </footer>
                </div>
            </MotionConfig>
        </ThemeContext.Provider>
    );
}
