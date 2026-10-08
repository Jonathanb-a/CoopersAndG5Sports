import { ThemeContext } from '@/Themes/context';
import { coppersMotion, coppersRevealMotion } from '@/Themes/coppers/motion';
import { g5Motion, g5RevealMotion } from '@/Themes/g5/motion';
import { type ProductSize } from '@/types';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import fs from 'node:fs';
import path from 'node:path';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { BuyBar, CartBuyBar, ProductBuyBar } from './buy-bar';

const state = vi.hoisted(() => ({ reduced: false, url: '/producto/camisa' }));

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

function coppers(children: ReactNode) {
    return (
        <ThemeContext.Provider value={{ name: 'coppers', motion: coppersRevealMotion, motionSet: coppersMotion, root: null }}>
            {children}
        </ThemeContext.Provider>
    );
}

function g5(children: ReactNode) {
    return <ThemeContext.Provider value={{ name: 'g5', motion: g5RevealMotion, motionSet: g5Motion, root: null }}>{children}</ThemeContext.Provider>;
}

const size = (name: string, status: ProductSize['status'], id = 1): ProductSize => ({
    variant_id: id,
    size: name,
    status,
    selectable: status !== 'sold-out',
});

const available = [size('M', 'available', 1), size('L', 'last-piece', 2)];

function productBar(props: Partial<Parameters<typeof ProductBuyBar>[0]> = {}) {
    const onAdd = vi.fn();
    const onChooseSize = vi.fn();
    const view = render(
        coppers(
            <ProductBuyBar
                price="599.00"
                sizes={available}
                chosen={null}
                adding={false}
                added={false}
                onAdd={onAdd}
                onChooseSize={onChooseSize}
                {...props}
            />,
        ),
    );

    return { ...view, onAdd, onChooseSize, bar: view.container.querySelector<HTMLElement>('[data-buy-bar]') };
}

beforeEach(() => {
    state.reduced = false;
    state.url = '/producto/camisa';
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: 390 });
});

afterEach(cleanup);

describe('barra de compra: solo en celular y sin tapar contenido', () => {
    it('se oculta desde el ancho de tableta (md:hidden) y respeta el área segura de iOS', () => {
        const { bar } = productBar();

        expect(bar!.className).toContain('md:hidden');
        expect(bar!.className).toContain('safe-area-inset-bottom');
    });

    it('es sticky (ocupa su propio lugar), no fixed: no tapa el final de la página ni necesita espacio extra', () => {
        const { bar } = productBar();

        expect(bar!.className).toMatch(/\bsticky\b/);
        expect(bar!.className).toContain('bottom-0');
        expect(bar!.className).not.toMatch(/\bfixed\b/);
    });

    it('queda debajo del encabezado y del cajón del carrito', () => {
        const { bar } = productBar();
        const drawer = fs.readFileSync(path.resolve(__dirname, 'mobile-menu.tsx'), 'utf8');

        expect(bar!.className).toContain('z-20');
        // El fondo del cajón es z-40 y el cajón z-50.
        expect(drawer).toContain('z-40');
        expect(drawer).toContain('z-50');
    });

    it('usa los colores, la tipografía y los botones del tema, con botón táctil de 44 px o más', () => {
        const { bar } = productBar();
        const button = bar!.querySelector('button')!;

        expect(bar!.className).toContain('bg-store-surface');
        expect(bar!.className).toContain('border-store-border');
        expect(bar!.className).toContain('font-store-body');
        expect(button.className).toContain('rounded-store');
        expect(button.className).toContain('bg-store-action');
        expect(button.className).toMatch(/\bh-(11|12|14)\b/);
    });

    it('las páginas ocultan su botón normal solo en celular (en pantalla grande no cambia nada)', () => {
        const product = fs.readFileSync(path.resolve(__dirname, '../../pages/product.tsx'), 'utf8');
        const cart = fs.readFileSync(path.resolve(__dirname, '../../pages/cart.tsx'), 'utf8');

        expect(product).toContain('<ProductBuyBar');
        expect(product).toContain("'max-md:hidden'");
        expect(cart).toContain('<CartBuyBar');
        expect(cart).toContain('max-md:hidden');
    });
});

describe('barra del producto', () => {
    it('sin talla elegida dice «Elige tu talla» y lleva al selector en vez de agregar', () => {
        const { onAdd, onChooseSize } = productBar();

        fireEvent.click(screen.getByRole('button', { name: 'Elige tu talla' }));

        expect(onChooseSize).toHaveBeenCalledTimes(1);
        expect(onAdd).not.toHaveBeenCalled();
    });

    it('con talla elegida dice «Agregar al carrito» y agrega', () => {
        const { onAdd, onChooseSize } = productBar({ chosen: available[0] });

        fireEvent.click(screen.getByRole('button', { name: /Agregar al carrito/ }));

        expect(onAdd).toHaveBeenCalledTimes(1);
        expect(onChooseSize).not.toHaveBeenCalled();
    });

    it('muestra el precio', () => {
        const { bar } = productBar();

        expect(bar!.textContent).toContain('Precio');
        expect(bar!.textContent).toContain('599');
    });

    it('agotado: botón «Agotado» deshabilitado', () => {
        const { onAdd, onChooseSize } = productBar({ sizes: [size('M', 'sold-out', 1), size('L', 'sold-out', 2)] });
        const button = screen.getByRole('button', { name: /Agotado/ });

        expect(button).toHaveProperty('disabled', true);
        fireEvent.click(button);
        expect(onAdd).not.toHaveBeenCalled();
        expect(onChooseSize).not.toHaveBeenCalled();
    });

    it('sobre pedido: conserva el texto de agregar y lo avisa junto al precio', () => {
        const backorder = size('M', 'backorder', 1);
        const { bar, onAdd } = productBar({ sizes: [backorder], chosen: backorder });

        expect(bar!.textContent).toContain('Sobre pedido');
        fireEvent.click(screen.getByRole('button', { name: /Agregar al carrito/ }));
        expect(onAdd).toHaveBeenCalledTimes(1);
    });

    it('mientras agrega se deshabilita, y después confirma «Agregado»', () => {
        const adding = productBar({ chosen: available[0], adding: true });
        expect(screen.getByRole('button', { name: /Agregando/ })).toHaveProperty('disabled', true);
        adding.unmount();

        productBar({ chosen: available[0], added: true });
        expect(screen.getByRole('status').textContent).toContain('Agregado');
    });

    it('un error (por ejemplo, ya no hay piezas) se ve en la misma barra', () => {
        productBar({ chosen: available[0], error: 'Solo queda 1 pieza en talla M.' });

        expect(screen.getByRole('alert').textContent).toBe('Solo queda 1 pieza en talla M.');
    });

    it('un producto sin tallas registradas no tiene barra', () => {
        const { bar } = productBar({ sizes: [] });

        expect(bar).toBeNull();
    });
});

describe('barra del carrito', () => {
    it('muestra el total y «Continuar con la compra» hacia el checkout', () => {
        state.url = '/carrito';
        const { container } = render(coppers(<CartBuyBar count={2} total="1198.00" />));
        const bar = container.querySelector('[data-buy-bar="cart"]')!;
        const link = screen.getByRole('link', { name: 'Continuar con la compra' });

        expect(bar.textContent).toContain('1,198');
        expect(link.getAttribute('href')).toBe('/checkout');
        expect(link.className).toMatch(/\bh-(11|12|14)\b/);
        expect(bar.className).toContain('md:hidden');
    });

    it('con el carrito vacío no se muestra', () => {
        state.url = '/carrito';
        const { container } = render(coppers(<CartBuyBar count={0} total="0.00" />));

        expect(container.querySelector('[data-buy-bar]')).toBeNull();
        expect(container.innerHTML).toBe('');
    });
});

describe('animación de la barra', () => {
    const style = (element: Element | null) => element?.getAttribute('style') ?? '';

    it('entra suave solo con opacity y transform, con los valores del tema', () => {
        for (const [wrap, set] of [
            [coppers, coppersMotion],
            [g5, g5Motion],
        ] as const) {
            const { container, unmount } = render(
                wrap(
                    <BuyBar name="demo" label="Precio" amount="10">
                        <button type="button">Comprar</button>
                    </BuyBar>,
                ),
            );
            const css = style(container.querySelector('[data-buy-bar]'));

            expect(css).toContain('opacity: 0');
            expect(css).toContain(`translateY(${set.reveal.mobileDistance}px)`);
            // Nada más que opacity y transform.
            expect(
                css
                    .replace(/opacity:[^;]+;?/, '')
                    .replace(/transform:[^;]+;?/, '')
                    .trim(),
            ).toBe('');
            unmount();
        }
    });

    it('con prefers-reduced-motion aparece sin movimiento', () => {
        state.reduced = true;
        const { bar } = productBar();

        expect(style(bar)).toBe('');
    });

    it('en el carrito (ruta de pago) no se anima', () => {
        state.url = '/carrito';
        const { container } = render(coppers(<CartBuyBar count={1} total="599.00" />));

        expect(style(container.querySelector('[data-buy-bar]'))).toBe('');
    });
});
