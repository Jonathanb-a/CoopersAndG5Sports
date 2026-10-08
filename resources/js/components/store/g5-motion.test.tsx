import { ThemeContext } from '@/Themes/context';
import { coppersMotion, coppersRevealMotion } from '@/Themes/coppers/motion';
import { g5Motion, g5RevealMotion } from '@/Themes/g5/motion';
import { cleanup, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AddToCartLabel } from './add-to-cart-label';
import { ProductCard } from './product-card';
import { Reveal, ScrollReveal } from './reveal';
import { SectionHeading } from './section-heading';
import { Tag } from './tag';

const state = vi.hoisted(() => ({ reduced: false, url: '/' }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: state.url }),
    Link: ({ children, href, ...rest }: { children: ReactNode; href: string }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

vi.mock('motion/react', async (original) => ({
    ...(await original<typeof import('motion/react')>()),
    useReducedMotion: () => state.reduced,
}));

function g5(children: ReactNode) {
    return <ThemeContext.Provider value={{ name: 'g5', motion: g5RevealMotion, motionSet: g5Motion, root: null }}>{children}</ThemeContext.Provider>;
}

function coppers(children: ReactNode) {
    return (
        <ThemeContext.Provider value={{ name: 'coppers', motion: coppersRevealMotion, motionSet: coppersMotion, root: null }}>
            {children}
        </ThemeContext.Provider>
    );
}

function setViewport(width: number) {
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: width });
}

beforeEach(() => {
    // jsdom no trae IntersectionObserver (lo usan las entradas al aparecer en pantalla); aquí nunca se cruza.
    vi.stubGlobal(
        'IntersectionObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
    state.reduced = false;
    state.url = '/';
    setViewport(1280);
    window.matchMedia = ((query: string) => ({
        matches: false,
        media: query,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
    })) as unknown as typeof window.matchMedia;
});

afterEach(cleanup);

const style = (element: Element | null) => element?.getAttribute('style') ?? '';

describe('aparición en G5', () => {
    it('sube con el desplazamiento marcado de G5 (solo opacity y transform)', () => {
        render(
            g5(
                <Reveal>
                    <p>Hola</p>
                </Reveal>,
            ),
        );

        const box = screen.getByText('Hola').parentElement!;

        expect(style(box)).toContain('opacity: 0');
        expect(style(box)).toContain(`translateY(${g5Motion.reveal.distance}px)`);
    });

    it('con prefers-reduced-motion no se mueve nada', () => {
        state.reduced = true;
        render(
            g5(
                <>
                    <Reveal>
                        <p>Uno</p>
                    </Reveal>
                    <ScrollReveal>
                        <p>Dos</p>
                    </ScrollReveal>
                    <Reveal emphasis="banner">
                        <p>Tres</p>
                    </Reveal>
                </>,
            ),
        );

        for (const text of ['Uno', 'Dos', 'Tres']) {
            expect(style(screen.getByText(text).parentElement)).toBe('');
        }
    });

    it('no anima el checkout, el carrito, los pedidos ni los formularios', () => {
        for (const url of ['/checkout', '/carrito', '/mi-cuenta/pedidos', '/login', '/register', '/forgot-password']) {
            state.url = url;
            const { unmount } = render(
                g5(
                    <Reveal>
                        <p>Pagar</p>
                    </Reveal>,
                ),
            );

            expect(style(screen.getByText('Pagar').parentElement), url).toBe('');
            unmount();
        }
    });

    it('en celular el desplazamiento es menor y el banner no se escala', () => {
        setViewport(375);
        render(
            g5(
                <>
                    <Reveal>
                        <p>Hola</p>
                    </Reveal>
                    <Reveal emphasis="banner">
                        <p>Banner</p>
                    </Reveal>
                </>,
            ),
        );

        expect(style(screen.getByText('Hola').parentElement)).toContain(`translateY(${g5Motion.reveal.mobileDistance}px)`);
        expect(style(screen.getByText('Banner').parentElement)).not.toContain('scale');
    });

    it('el banner entra más dinámico en escritorio: más desplazamiento y escalado', () => {
        render(
            g5(
                <Reveal emphasis="banner">
                    <p>Banner</p>
                </Reveal>,
            ),
        );

        const box = style(screen.getByText('Banner').parentElement);

        expect(box).toContain(`translateY(${g5Motion.banner!.distance}px)`);
        expect(box).toContain(`scale(${g5Motion.banner!.scale})`);
    });

    it('un bloque «bare» no se mueve porque su título anima por sí mismo', () => {
        render(
            g5(
                <ScrollReveal bare>
                    <p>Título</p>
                </ScrollReveal>,
            ),
        );

        expect(style(screen.getByText('Título').parentElement)).toBe('');
    });
});

describe('Copper sigue igual', () => {
    it('el bloque «bare» sí se anima y el banner no tiene entrada propia', () => {
        render(
            coppers(
                <>
                    <ScrollReveal bare>
                        <p>Título</p>
                    </ScrollReveal>
                    <Reveal emphasis="banner">
                        <p>Banner</p>
                    </Reveal>
                </>,
            ),
        );

        expect(style(screen.getByText('Título').parentElement)).toContain('opacity: 0');
        expect(style(screen.getByText('Banner').parentElement)).toBe('');
    });

    it('sus etiquetas, títulos y botón de agregar no llevan los efectos de G5', () => {
        const { container } = render(
            coppers(
                <>
                    <Tag kind="sold-out" />
                    <SectionHeading title="Novedades" />
                    <AddToCartLabel soldOut={false} adding={false} added />
                </>,
            ),
        );

        expect(container.querySelector('[data-stamp]')).toBeNull();
        expect(container.querySelector('[data-heading-cut]')).toBeNull();
        expect(style(screen.getByRole('status'))).not.toContain('scale');
    });

    it('su tarjeta no se inclina ni se eleva', () => {
        const { container } = render(coppers(<ProductCard name="Camisa" price="100.00" imageUrl="/a.jpg" tag="sold-out" />));

        expect(container.innerHTML).not.toContain('--card-tilt');
        expect(container.innerHTML).not.toContain('--tag-undo');
    });
});

describe('tarjeta de producto en G5', () => {
    it('se inclina y se eleva, la foto se acerca más y la etiqueta se endereza', () => {
        const { container } = render(g5(<ProductCard name="Tenis" price="100.00" imageUrl="/a.jpg" secondImageUrl="/b.jpg" tag="last-piece" />));

        const card = container.querySelector('article')!;
        const photo = container.querySelector('[data-photo="main"]')!;
        const tag = container.querySelector('[data-stamp]')!;

        expect(style(card)).toContain(`--card-tilt: ${g5Motion.card.tilt}deg`);
        expect(style(card)).toContain(`--card-lift: ${g5Motion.card.lift}px`);
        expect(style(photo)).toContain(`--card-zoom: ${g5Motion.card.scale}`);
        expect(g5Motion.card.scale).toBeGreaterThan(coppersMotion.card.scale);
        expect(style(tag)).toContain(`--tag-undo: ${g5Motion.card.tagStraighten}deg`);
        expect(container.querySelector('[data-photo="second"]')).not.toBeNull();
    });

    it('con prefers-reduced-motion no hay inclinación, zoom ni cambio de foto', () => {
        state.reduced = true;
        const { container } = render(g5(<ProductCard name="Tenis" price="100.00" imageUrl="/a.jpg" secondImageUrl="/b.jpg" tag="last-piece" />));

        expect(container.innerHTML).not.toContain('--card-tilt');
        expect(container.innerHTML).not.toContain('--card-zoom');
        expect(container.innerHTML).not.toContain('--tag-undo');
        expect(container.querySelector('[data-photo="second"]')).toBeNull();
    });

    it('en celular no hay efectos de hover', () => {
        setViewport(375);
        const { container } = render(g5(<ProductCard name="Tenis" price="100.00" imageUrl="/a.jpg" secondImageUrl="/b.jpg" tag="last-piece" />));

        expect(container.innerHTML).not.toContain('--card-tilt');
        expect(container.innerHTML).not.toContain('--card-zoom');
        expect(container.querySelector('[data-photo="second"]')).toBeNull();
    });
});

describe('etiquetas, títulos y botón de agregar en G5', () => {
    it('la etiqueta entra tipo sello: espera a verse (transparente) y no se anima con movimiento reducido', () => {
        const { container, unmount } = render(g5(<Tag kind="sold-out" />));

        expect(container.querySelector('[data-stamp]')!.getAttribute('data-stamp')).toBe('pending');
        expect(style(container.querySelector('[data-stamp]'))).toContain('opacity: 0');
        unmount();

        state.reduced = true;
        const calm = render(g5(<Tag kind="sold-out" />));
        expect(calm.container.querySelector('[data-stamp]')).toBeNull();
        expect(style(calm.container.querySelector('span'))).toBe('');
    });

    it('en celular la etiqueta aparece ya puesta', () => {
        setViewport(375);
        const { container } = render(g5(<Tag kind="backorder" />));

        expect(container.querySelector('[data-stamp]')).toBeNull();
    });

    it('el título sube con corte; con movimiento reducido o en una ruta de pago es un título normal', () => {
        const first = render(g5(<SectionHeading title="Novedades" />));
        expect(first.container.querySelector('[data-heading-cut]')).not.toBeNull();
        expect(style(first.container.querySelector('[data-heading-cut] > span'))).toContain('translateY(100%)');
        first.unmount();

        state.reduced = true;
        const calm = render(g5(<SectionHeading title="Novedades" />));
        expect(calm.container.querySelector('[data-heading-cut]')).toBeNull();
        calm.unmount();

        state.reduced = false;
        state.url = '/checkout';
        const quiet = render(g5(<SectionHeading title="Novedades" />));
        expect(quiet.container.querySelector('[data-heading-cut]')).toBeNull();
    });

    it('«Agregado» llega con un pop de escala; sin movimiento solo aparece', () => {
        const first = render(g5(<AddToCartLabel soldOut={false} adding={false} added />));
        expect(style(screen.getByRole('status'))).toContain(`scale(${g5Motion.addToCart.pop})`);
        first.unmount();

        state.reduced = true;
        render(g5(<AddToCartLabel soldOut={false} adding={false} added />));
        expect(style(screen.getByRole('status'))).not.toContain('scale');
        expect(screen.getByRole('status').textContent).toContain('Agregado');
    });
});
