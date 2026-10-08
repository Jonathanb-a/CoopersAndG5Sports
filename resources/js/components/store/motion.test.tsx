import { ThemeContext, type ThemeMotion } from '@/Themes/context';
import { coppersMotion, coppersRevealMotion } from '@/Themes/coppers/motion';
import { cleanup, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AddToCartLabel } from './add-to-cart-label';
import { PageFade } from './page-fade';
import { ProductCard } from './product-card';
import { RetractingHeader } from './retracting-header';
import { Reveal, ScrollReveal } from './reveal';

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

const g5Motion: ThemeMotion = {
    initial: { opacity: 0, x: -32 },
    animate: { opacity: 1, x: 0 },
    transition: { duration: 0.28, ease: [0.34, 1.56, 0.64, 1] },
};

function coppers(children: ReactNode) {
    return (
        <ThemeContext.Provider value={{ name: 'coppers', motion: coppersRevealMotion, motionSet: coppersMotion, root: null }}>
            {children}
        </ThemeContext.Provider>
    );
}

function g5(children: ReactNode) {
    return <ThemeContext.Provider value={{ name: 'g5', motion: g5Motion, root: null }}>{children}</ThemeContext.Provider>;
}

function setViewport(width: number) {
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: width });
}

beforeEach(() => {
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

const style = (element: HTMLElement) => element.getAttribute('style') ?? '';

describe('Reveal en Copper', () => {
    it('empieza invisible y desplazado unos píxeles (solo opacity y transform)', () => {
        render(
            coppers(
                <Reveal>
                    <p>Hola</p>
                </Reveal>,
            ),
        );

        const box = screen.getByText('Hola').parentElement!;

        expect(style(box)).toContain('opacity: 0');
        expect(style(box)).toContain(`translateY(${coppersMotion.reveal.distance}px)`);
        expect(style(box)).not.toMatch(/height|width|margin|top|left/);
    });

    it('con prefers-reduced-motion no aplica ningún movimiento', () => {
        state.reduced = true;
        render(
            coppers(
                <>
                    <Reveal>
                        <p>Uno</p>
                    </Reveal>
                    <ScrollReveal>
                        <p>Dos</p>
                    </ScrollReveal>
                </>,
            ),
        );

        for (const text of ['Uno', 'Dos']) {
            const box = screen.getByText(text).parentElement!;

            expect(style(box)).not.toContain('transform');
            expect(style(box)).not.toContain('opacity');
        }
    });

    it('no anima el checkout ni los formularios', () => {
        for (const url of ['/checkout', '/login', '/mi-cuenta']) {
            state.url = url;
            const { unmount } = render(
                coppers(
                    <Reveal>
                        <p>Pagar</p>
                    </Reveal>,
                ),
            );

            expect(style(screen.getByText('Pagar').parentElement!), url).toBe('');
            unmount();
        }
    });

    it('en celular el desplazamiento es menor', () => {
        setViewport(375);
        render(
            coppers(
                <Reveal>
                    <p>Hola</p>
                </Reveal>,
            ),
        );

        expect(style(screen.getByText('Hola').parentElement!)).toContain(`translateY(${coppersMotion.reveal.mobileDistance}px)`);
    });
});

describe('temas sin movimiento propio (G5)', () => {
    it('conservan su animación de siempre', () => {
        render(
            g5(
                <Reveal>
                    <p>Hola</p>
                </Reveal>,
            ),
        );

        expect(style(screen.getByText('Hola').parentElement!)).toContain('translateX(-32px)');
    });

    it('la tarjeta no agrega zoom ni segunda foto', () => {
        const { container } = render(g5(<ProductCard name="Tenis" price="100.00" imageUrl="/a.jpg" secondImageUrl="/b.jpg" />));

        expect(container.querySelector('[data-photo="second"]')).toBeNull();
        expect(container.innerHTML).not.toContain('card-zoom');
    });
});

describe('tarjeta de producto en Copper', () => {
    it('con cursor de escritorio tiene zoom leve y segunda foto', () => {
        const { container } = render(coppers(<ProductCard name="Camisa" price="100.00" imageUrl="/a.jpg" secondImageUrl="/b.jpg" />));

        expect(container.querySelector('[data-photo="second"]')).not.toBeNull();
        expect(container.querySelector<HTMLElement>('[data-photo="main"]')!.getAttribute('style')).toContain(
            `--card-zoom: ${coppersMotion.card.scale}`,
        );
    });

    it('sin segunda foto solo hace el zoom', () => {
        const { container } = render(coppers(<ProductCard name="Camisa" price="100.00" imageUrl="/a.jpg" />));

        expect(container.querySelector('[data-photo="second"]')).toBeNull();
        expect(container.querySelector('[data-photo="main"]')!.className).toContain('card-zoom');
    });

    it('con prefers-reduced-motion no hay zoom ni cambio de foto', () => {
        state.reduced = true;
        const { container } = render(coppers(<ProductCard name="Camisa" price="100.00" imageUrl="/a.jpg" secondImageUrl="/b.jpg" />));

        expect(container.querySelector('[data-photo="second"]')).toBeNull();
        expect(container.querySelector('[data-photo="main"]')!.className).not.toContain('card-zoom');
        expect(container.querySelector('[data-photo="main"]')!.getAttribute('style')).toBeNull();
    });

    it('en celular no hay efectos de hover', () => {
        setViewport(375);
        const { container } = render(coppers(<ProductCard name="Camisa" price="100.00" imageUrl="/a.jpg" secondImageUrl="/b.jpg" />));

        expect(container.querySelector('[data-photo="second"]')).toBeNull();
        expect(container.querySelector('[data-photo="main"]')!.className).not.toContain('card-zoom');
    });
});

describe('fundido de página', () => {
    it('empieza transparente y solo anima opacity', () => {
        render(
            coppers(
                <PageFade>
                    <p>Contenido</p>
                </PageFade>,
            ),
        );

        const box = screen.getByText('Contenido').parentElement!;

        expect(style(box)).toContain('opacity: 0');
        expect(style(box)).not.toContain('transform');
    });

    it('con prefers-reduced-motion o en el checkout no envuelve nada', () => {
        state.reduced = true;
        const first = render(
            coppers(
                <PageFade>
                    <p>Contenido</p>
                </PageFade>,
            ),
        );
        expect(style(screen.getByText('Contenido').parentElement!)).toBe('');
        first.unmount();

        state.reduced = false;
        state.url = '/checkout';
        render(
            coppers(
                <PageFade>
                    <p>Pagar</p>
                </PageFade>,
            ),
        );
        expect(style(screen.getByText('Pagar').parentElement!)).toBe('');
    });
});

describe('encabezado', () => {
    it('queda a la vista al cargar y no se recoge con movimiento reducido ni en celular', () => {
        const { container, unmount } = render(coppers(<RetractingHeader>Encabezado</RetractingHeader>));
        expect(container.querySelector('header')!.dataset.retracted).toBe('false');
        unmount();

        state.reduced = true;
        const reduced = render(coppers(<RetractingHeader>Encabezado</RetractingHeader>));
        expect(reduced.container.querySelector('header')!.dataset.retracted).toBe('false');
        reduced.unmount();

        state.reduced = false;
        setViewport(375);
        const mobile = render(coppers(<RetractingHeader>Encabezado</RetractingHeader>));
        expect(mobile.container.querySelector('header')!.dataset.retracted).toBe('false');
    });
});

describe('botón de agregar al carrito', () => {
    it('Copper confirma con «Agregado»', () => {
        render(coppers(<AddToCartLabel soldOut={false} adding={false} added />));

        expect(screen.getByRole('status').textContent).toContain('Agregado');
    });

    it('con prefers-reduced-motion la confirmación sigue ahí, sin transición', () => {
        state.reduced = true;
        render(coppers(<AddToCartLabel soldOut={false} adding={false} added />));

        expect(screen.getByRole('status').textContent).toContain('Agregado');
    });

    it('G5 queda con su texto de siempre', () => {
        render(g5(<AddToCartLabel soldOut={false} adding={false} added />));

        expect(screen.queryByRole('status')).toBeNull();
        expect(screen.getByText('Agregar al carrito')).toBeTruthy();
    });

    it('agotado y agregando conservan sus textos', () => {
        const sold = render(coppers(<AddToCartLabel soldOut adding={false} added={false} />));
        expect(screen.getByText('Agotado')).toBeTruthy();
        sold.unmount();

        render(coppers(<AddToCartLabel soldOut={false} adding added={false} />));
        expect(screen.getByText('Agregando…')).toBeTruthy();
    });
});
