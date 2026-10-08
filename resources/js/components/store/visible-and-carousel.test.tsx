import { intersectAll, observedElements } from '@/test-setup';
import { ThemeContext } from '@/Themes/context';
import { coppersMotion, coppersRevealMotion } from '@/Themes/coppers/motion';
import { g5Motion, g5RevealMotion } from '@/Themes/g5/motion';
import { type StoreBanner } from '@/types';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import fs from 'node:fs';
import path from 'node:path';
import { type ReactNode } from 'react';
import { renderToString } from 'react-dom/server';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { BannerCarousel } from './banner-carousel';
import { BuyBar } from './buy-bar';
import { PageFade } from './page-fade';
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

const style = (element: Element | null | undefined) => element?.getAttribute('style') ?? '';

const banner = (id: number, extra: Partial<StoreBanner> = {}): StoreBanner => ({
    id,
    image_url: `/banner-${id}.jpg`,
    title: `Banner ${id}`,
    link_url: null,
    ...extra,
});

beforeEach(() => {
    state.reduced = false;
    state.url = '/';
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: 1280 });
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

describe('todo contenido animado termina visible', () => {
    it('lo que entra al cargar acaba sin estilos de animación (visible y en su sitio)', async () => {
        render(
            g5(
                <>
                    <Reveal>
                        <p>Uno</p>
                    </Reveal>
                    <PageFade>
                        <p>Dos</p>
                    </PageFade>
                    <BuyBar name="demo" label="Precio" amount="10">
                        <button type="button">Comprar</button>
                    </BuyBar>
                </>,
            ),
        );

        const blocks = [screen.getByText('Uno').parentElement, screen.getByText('Dos').parentElement, document.querySelector('[data-buy-bar]')];

        // Arranca oculto (solo opacity y transform)...
        expect(style(blocks[0])).toContain('opacity: 0');
        // ...y termina limpio.
        await waitFor(() => blocks.forEach((block) => expect(style(block)).toBe('')), { timeout: 3000 });
    });

    it('lo que entra con scroll espera oculto y, al llegar a la pantalla, termina visible', async () => {
        render(
            g5(
                <ScrollReveal>
                    <p>Abajo</p>
                </ScrollReveal>,
            ),
        );

        const block = screen.getByText('Abajo').parentElement!;

        expect(style(block)).toContain('opacity: 0');
        act(() => intersectAll());
        await waitFor(() => expect(style(block)).toBe(''), { timeout: 3000 });
    });

    it('el título con corte observa su CAJA (no el texto recortado) y termina revelado', async () => {
        const { container } = render(g5(<SectionHeading title="Compra por categoría" />));
        const box = container.querySelector('[data-heading-cut]')!;
        const text = box.querySelector('span')!;

        // El texto empieza debajo de la caja; un observer puesto en él nunca dispararía (queda recortado).
        expect(style(text)).toContain('translateY(100%)');
        expect(observedElements()).toContain(box);
        expect(observedElements()).not.toContain(text);

        act(() => intersectAll());
        await waitFor(() => expect(style(text)).toBe(''), { timeout: 3000 });
        expect(text.textContent).toBe('Compra por categoría');
    });

    it('la caja del corte deja espacio para los acentos de las mayúsculas', () => {
        const { container } = render(g5(<SectionHeading title="Categoría" />));

        expect(container.querySelector('[data-heading-cut]')!.className).toMatch(/pt-\[0\.2em\]/);
    });

    it('sin IntersectionObserver nada se esconde ni truena: el contenido se ve tal cual', () => {
        vi.stubGlobal('IntersectionObserver', undefined);

        const { container } = render(
            g5(
                <>
                    <ScrollReveal>
                        <p>Abajo</p>
                    </ScrollReveal>
                    <SectionHeading title="Lo más nuevo" />
                    <Tag kind="sold-out" />
                </>,
            ),
        );

        expect(style(screen.getByText('Abajo').parentElement)).toBe('');
        expect(style(container.querySelector('[data-heading-cut] span'))).toBe('');
        expect(container.querySelector('[data-stamp]')).toBeNull();
        expect(screen.getByText('Lo más nuevo')).toBeTruthy();
    });

    it('el HTML del servidor (sin JavaScript) sale visible: sin opacity ni transform', () => {
        for (const wrap of [g5, coppers]) {
            const html = renderToString(
                wrap(
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
                        <PageFade>
                            <p>Cuatro</p>
                        </PageFade>
                        <SectionHeading title="Cinco" />
                        <Tag kind="last-piece" />
                        <BuyBar name="demo" label="Precio" amount="10">
                            <button type="button">Comprar</button>
                        </BuyBar>
                    </>,
                ),
            );

            expect(html).not.toMatch(/opacity\s*:\s*0/);
            expect(html).not.toContain('transform');
            expect(html).not.toContain('data-stamp="pending"');
            expect(html).toContain('Cinco');
        }
    });

    it('el título del hero es fluido y no puede desbordar en 360 px', () => {
        const home = fs.readFileSync(path.resolve(__dirname, '../../pages/home.tsx'), 'utf8');
        const hero = home.match(/<h1 className="(store-heading[^"]+)">/)?.[1] ?? '';

        // clamp con vw: a 360 px son 40 px de letra; además reparte líneas y parte palabras largas.
        expect(hero).toMatch(/text-\[clamp\(2\.5rem,11vw,6rem\)\]/);
        expect(hero).toContain('text-balance');
        expect(hero).toContain('break-words');
        expect(hero).not.toMatch(/whitespace-nowrap|truncate/);

        // A 360 px: la palabra más larga («BIENVENIDO», 10 letras a ~0.55 em en Anton) cabe en los 328 px útiles.
        const fontSize = Math.min(Math.max(40, 0.11 * 360), 96);
        expect(10 * 0.55 * fontSize).toBeLessThan(360 - 32);
    });
});

describe('carrusel de banners sin controles', () => {
    const three = [banner(1, { link_url: '/catalogo' }), banner(2, { title: null }), banner(3)];
    const carousel = () => document.querySelector<HTMLElement>('[data-carousel]')!;
    const hover = (type: 'pointerover' | 'pointerout', pointerType: string) => {
        const event = new MouseEvent(type, { bubbles: true });

        Object.defineProperty(event, 'pointerType', { value: pointerType });
        act(() => {
            carousel().dispatchEvent(event);
        });
    };
    const advance = (ms: number) => act(() => vi.advanceTimersByTime(ms));

    it('no tiene flechas, puntos ni botón de pausa en el DOM', () => {
        render(g5(<BannerCarousel banners={three} />));

        expect(carousel().querySelectorAll('button, [role="button"], [role="tab"], [role="tablist"]').length).toBe(0);
        expect(carousel().innerHTML).not.toMatch(/anterior|siguiente|Pausar|Reanudar|Ir al banner/);
    });

    it('avanza solo cada 5 segundos y da la vuelta', () => {
        vi.useFakeTimers();
        render(g5(<BannerCarousel banners={three} />));

        expect(carousel().dataset.index).toBe('0');
        advance(4900);
        expect(carousel().dataset.index).toBe('0');
        advance(200);
        expect(carousel().dataset.index).toBe('1');
        advance(5000);
        expect(carousel().dataset.index).toBe('2');
        advance(5000);
        expect(carousel().dataset.index).toBe('0');
    });

    it('la transición dura entre 0.5 y 0.8 s: fundido en Copper, deslizamiento en G5', () => {
        expect(coppersMotion.carousel).toEqual({ interval: 5, duration: 0.8, effect: 'fade' });
        expect(g5Motion.carousel.effect).toBe('slide');

        for (const set of [coppersMotion, g5Motion]) {
            expect(set.carousel.interval).toBe(5);
            expect(set.carousel.duration).toBeGreaterThanOrEqual(0.5);
            expect(set.carousel.duration).toBeLessThanOrEqual(0.8);
        }

        // Copper's más sutil (más lento y sin desplazamiento) que G5.
        expect(coppersMotion.carousel.duration).toBeGreaterThan(g5Motion.carousel.duration);
    });

    it('se detiene con el cursor encima y se reanuda al quitarlo', () => {
        vi.useFakeTimers();
        render(coppers(<BannerCarousel banners={three} />));

        hover('pointerover', 'mouse');
        expect(carousel().dataset.autoplay).toBe('off');
        advance(12000);
        expect(carousel().dataset.index).toBe('0');

        hover('pointerout', 'mouse');
        expect(carousel().dataset.autoplay).toBe('on');
        advance(5100);
        expect(carousel().dataset.index).toBe('1');
    });

    it('un toque no cuenta como cursor: en celular no se queda pausado', () => {
        vi.useFakeTimers();
        render(coppers(<BannerCarousel banners={three} />));

        hover('pointerover', 'touch');
        expect(carousel().dataset.autoplay).toBe('on');
    });

    it('se detiene mientras el dedo toca y se reanuda al soltar', () => {
        vi.useFakeTimers();
        render(g5(<BannerCarousel banners={three} />));

        fireEvent.touchStart(carousel(), { touches: [{ clientX: 200, clientY: 100 }] });
        expect(carousel().dataset.autoplay).toBe('off');
        advance(12000);
        expect(carousel().dataset.index).toBe('0');

        // Suelta casi en el mismo punto: no es un deslizamiento.
        fireEvent.touchEnd(carousel(), { changedTouches: [{ clientX: 205, clientY: 100 }] });
        expect(carousel().dataset.index).toBe('0');
        expect(carousel().dataset.autoplay).toBe('on');
        advance(5100);
        expect(carousel().dataset.index).toBe('1');
    });

    it('en celular se cambia deslizando con el dedo; un gesto vertical es scroll y no cambia', () => {
        render(g5(<BannerCarousel banners={three} />));
        const swipe = (fromX: number, toX: number, toY = 100) => {
            fireEvent.touchStart(carousel(), { touches: [{ clientX: fromX, clientY: 100 }] });
            fireEvent.touchEnd(carousel(), { changedTouches: [{ clientX: toX, clientY: toY }] });
        };

        swipe(300, 80);
        expect(carousel().dataset.index).toBe('1');
        swipe(80, 300);
        expect(carousel().dataset.index).toBe('0');
        swipe(80, 300);
        expect(carousel().dataset.index).toBe('2');
        swipe(200, 150, 400);
        expect(carousel().dataset.index).toBe('2');
        expect(carousel().className).toContain('touch-pan-y');
    });

    it('con la pestaña oculta se pausa', () => {
        vi.useFakeTimers();
        render(g5(<BannerCarousel banners={three} />));

        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
        act(() => {
            document.dispatchEvent(new Event('visibilitychange'));
        });
        expect(carousel().dataset.autoplay).toBe('off');
        advance(12000);
        expect(carousel().dataset.index).toBe('0');

        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' });
        act(() => {
            document.dispatchEvent(new Event('visibilitychange'));
        });
        expect(carousel().dataset.autoplay).toBe('on');
    });

    it('con prefers-reduced-motion no avanza solo: queda el primero, y el swipe sigue sirviendo', () => {
        vi.useFakeTimers();
        state.reduced = true;
        render(g5(<BannerCarousel banners={three} />));

        expect(carousel().dataset.autoplay).toBe('off');
        advance(30000);
        expect(carousel().dataset.index).toBe('0');

        fireEvent.touchStart(carousel(), { touches: [{ clientX: 300, clientY: 100 }] });
        fireEvent.touchEnd(carousel(), { changedTouches: [{ clientX: 80, clientY: 100 }] });
        expect(carousel().dataset.index).toBe('1');
    });

    it('con un solo banner no hay carrusel, temporizador ni nada extra', () => {
        vi.useFakeTimers();
        render(g5(<BannerCarousel banners={[banner(1)]} />));

        expect(carousel().dataset.carousel).toBe('single');
        expect(carousel().dataset.index).toBeUndefined();
        expect(carousel().getAttribute('aria-roledescription')).toBeNull();
        expect(carousel().querySelectorAll('[data-banner]').length).toBe(1);
        expect(vi.getTimerCount()).toBe(0);
        expect(carousel().querySelectorAll('button').length).toBe(0);
    });

    it('sin banners no pinta nada (no deja hueco)', () => {
        const { container } = render(g5(<BannerCarousel banners={[]} />));

        expect(container.innerHTML).toBe('');
    });

    it('solo el primer banner carga con prioridad; lleva ancho, alto y proporción fija', () => {
        vi.useFakeTimers();
        render(g5(<BannerCarousel banners={three} />));

        const first = carousel().querySelector('img')!;

        expect(first.getAttribute('loading')).toBe('eager');
        expect(first.getAttribute('fetchpriority')).toBe('high');
        expect(first.getAttribute('width')).toBe('1600');
        expect(first.getAttribute('height')).toBe('1000');
        // 16:10 en celular, 21:9 desde tableta.
        expect(carousel().firstElementChild!.className).toMatch(/aspect-\[16\/10\].*md:aspect-\[21\/9\]/);

        advance(5100);
        const second = carousel().querySelector('[data-banner="2"] img')!;

        expect(second.getAttribute('loading')).toBe('lazy');
        expect(second.getAttribute('fetchpriority')).toBe('auto');
    });

    it('el título se lee sobre la imagen (degradado) y el banner con enlace es un enlace', () => {
        render(g5(<BannerCarousel banners={three} />));

        const current = carousel().querySelector('[data-current]')!;
        const title = current.querySelector('p')!;

        expect(current.tagName).toBe('A');
        expect(current.getAttribute('href')).toBe('/catalogo');
        expect(title.textContent).toBe('Banner 1');
        expect(title.className).toContain('text-white');
        expect(title.parentElement!.className).toMatch(/bg-gradient-to-t.*from-black\/80/);
    });
});
