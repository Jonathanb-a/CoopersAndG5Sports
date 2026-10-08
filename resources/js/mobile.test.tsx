import { cleanup, render, screen } from '@testing-library/react';
import fs from 'node:fs';
import path from 'node:path';
import { type ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ThemeContext } from './Themes/context';
import { coppersRevealMotion } from './Themes/coppers/motion';
import { buttonVariants } from './components/store/button';
import { Field } from './components/store/form';
import { HeaderActions } from './components/store/header-actions';
import { SearchField } from './components/store/search-field';

// Reglas para celular (ver CLAUDE.md): tamaño táctil de 44 px, 16 px en campos, colores de error con
// contraste y sombras que no se animan. Son pruebas de atributos y clases, no visuales.

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: '/', props: { miniCart: { count: 2 }, auth: { customer: null }, filters: undefined } }),
    router: { get: () => undefined, post: () => undefined },
    Link: ({ children, href, ...rest }: { children: ReactNode; href: string }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

afterEach(cleanup);

const root = path.resolve(__dirname);
const read = (file: string) => fs.readFileSync(path.join(root, file), 'utf8');
const sources = (dir: string) =>
    fs
        .readdirSync(path.join(root, dir), { recursive: true, encoding: 'utf8' })
        .filter((file) => file.endsWith('.tsx') && !file.endsWith('.test.tsx'))
        .map((file) => ({ file: `${dir}/${file}`, text: read(`${dir}/${file}`) }));

describe('campos de formulario', () => {
    it('llevan texto de 16 px (text-base) y alto táctil, y dejan pasar el teclado correcto', () => {
        render(<Field label="Teléfono" type="tel" inputMode="tel" autoComplete="tel" />);

        const input = screen.getByLabelText('Teléfono');

        expect(input.getAttribute('type')).toBe('tel');
        expect(input.getAttribute('inputmode')).toBe('tel');
        expect(input.getAttribute('autocomplete')).toBe('tel');
        expect(input.className).toContain('text-base');
        expect(input.className).toContain('h-11');
    });

    it('el buscador tiene campo de 16 px y botón táctil', () => {
        const { container } = render(<SearchField />);

        expect(container.querySelector('input')!.className).toMatch(/h-11.*text-base|text-base.*h-11/);
        expect(container.querySelector('button')!.className).toContain('size-11');
    });

    it('ningún input, select ni textarea suelto baja de 16 px', () => {
        const offenders: string[] = [];

        for (const { file, text } of [...sources('pages'), ...sources('components/store')]) {
            for (const match of text.matchAll(/<(input|select|textarea)\b/g)) {
                // La etiqueta de apertura: hasta su cierre (/> o el </select>), sin cortarse en las flechas =>.
                const rest = text.slice(match.index, match.index + 900);
                const attrs = rest.split(/\/>|<\/select>|<\/textarea>/)[0];

                // Casillas, opciones y campos ocultos visualmente (sr-only) no escriben texto.
                if (/type="(radio|checkbox|hidden)"/.test(attrs) || attrs.includes('sr-only')) {
                    continue;
                }

                if (!/text-base|text-lg|text-xl/.test(attrs) && !/\{\.\.\.props\}/.test(attrs.slice(0, 120))) {
                    offenders.push(`${file}: <${match[1]}>`);
                }
            }
        }
        expect(offenders).toEqual([]);
    });
});

describe('tamaño táctil', () => {
    it('los botones de la tienda miden al menos 44 px de alto en todos sus tamaños', () => {
        for (const size of ['sm', 'md', 'lg'] as const) {
            expect(buttonVariants({ size }), size).toMatch(/\bh-(11|12|14)\b/);
        }
    });

    it('buscar, cuenta y carrito del encabezado son de 44 x 44', () => {
        const { container } = render(
            <ThemeContext.Provider value={{ name: 'coppers', motion: coppersRevealMotion, root: null }}>
                <HeaderActions onToggleSearch={() => undefined} />
            </ThemeContext.Provider>,
        );

        const controls = [...container.querySelectorAll('button, a')];

        expect(controls.length).toBeGreaterThanOrEqual(3);

        for (const control of controls) {
            expect(control.className, control.getAttribute('aria-label') ?? control.textContent ?? '').toMatch(/\bsize-11\b|\bh-11\b/);
        }
    });

    it('los enlaces sueltos de las páginas llevan alto mínimo (min-h-11) en celular', () => {
        expect(read('pages/auth/login.tsx')).toMatch(/forgot-password"[\s\S]{0,160}min-h-11/);
        expect(read('pages/cart.tsx')).toContain('min-h-11');
    });
});

describe('checkout', () => {
    const checkout = read('pages/checkout.tsx');

    it('pide el teléfono con teclado de teléfono y el apartado va en una barra fija en celular', () => {
        expect(checkout).toMatch(/type="tel"[\s\S]{0,80}autoComplete="tel"[\s\S]{0,40}inputMode="tel"/);
        // Barra fija con total y botón; el botón de la columna del total solo se ve en pantallas grandes.
        expect(checkout).toContain('data-checkout-bar');
        expect(checkout).toMatch(/sticky bottom-0[^"]*lg:hidden/);
        expect(checkout).toContain('max-lg:hidden');
        expect(checkout).toContain('safe-area-inset-bottom');
    });

    it('los correos usan type=email y autocomplete', () => {
        for (const file of ['pages/auth/login.tsx', 'pages/auth/register.tsx', 'pages/auth/forgot-password.tsx']) {
            expect(read(file), file).toMatch(/type="email"/);
            expect(read(file), file).toMatch(/autoComplete="(email|username)"/);
        }
    });
});

describe('colores y sombras', () => {
    it('los errores usan el color del tema (con contraste AA) y no un rojo fijo', () => {
        const offenders = [...sources('pages'), ...sources('components/store'), ...sources('Themes')]
            .filter(({ text }) => /\b(text|border|bg)-red-\d00\b/.test(text.replace(/bg-red-700 text-white/g, '')))
            .map(({ file }) => file);

        // order-status-badge usa rojo 700 con texto blanco (contraste AA); no es un mensaje de error.
        expect(offenders.filter((file) => !file.endsWith('order-status-badge.tsx'))).toEqual([]);
        expect(read('Themes/coppers/theme.css')).toContain('--store-error');
        expect(read('Themes/g5/theme.css')).toContain('--store-error');
    });

    it('ninguna transición anima box-shadow, y la sombra de store-lift cambia solo de opacidad', () => {
        const css = [read('../css/store.css'), read('Themes/coppers/theme.css'), read('Themes/g5/theme.css')].join('\n');

        const transitions = [...css.matchAll(/transition(?:-property)?\s*:([^;]+);/g)].map((match) => match[1]);

        expect(transitions.filter((value) => value.includes('box-shadow'))).toEqual([]);
        expect(css).toMatch(/\.store-lift::after\s*\{[^}]*box-shadow:[^}]*opacity:\s*0/s);
        expect(css).toMatch(/\.store-lift:hover::after[^{]*\{[^}]*opacity:\s*1/s);
        // El elemento se mueve con transform; no se anima el filtro ni el desenfoque.
        expect(css).not.toMatch(/transition[^;]*(filter|blur)/);
    });

    it('las tarjetas con store-lift no recortan su sombra (el overflow-hidden va adentro)', () => {
        for (const file of ['components/store/product-card.tsx', 'components/store/category-card.tsx']) {
            const text = read(file);
            const outer = text.match(/store-lift[^'"`]*['"`]/)?.[0] ?? '';

            expect(outer, file).not.toContain('overflow-hidden');
            expect(text, file).toContain('overflow-hidden rounded-[inherit]');
        }
    });

    it('las imágenes de las tarjetas llevan ancho y alto', () => {
        expect(read('components/store/product-card.tsx')).toMatch(/data-photo="main"[\s\S]{0,60}width=\{600\}[\s\S]{0,30}height=\{600\}/);
        expect(read('components/store/category-card.tsx')).toMatch(/width=\{800\}\s+height=\{600\}/);
    });
});
