import { ThemeContext } from '@/Themes/context';
import { coppersMotion, coppersRevealMotion } from '@/Themes/coppers/motion';
import { type StoreFooter } from '@/types';
import { cleanup, render, screen } from '@testing-library/react';
import fs from 'node:fs';
import path from 'node:path';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { FooterDetails } from './footer-details';
import { LegalConsent } from './legal-consent';

const state = vi.hoisted(() => ({
    footer: undefined as StoreFooter | undefined,
    whatsapp: 'https://wa.me/527551112222' as string | null,
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: '/',
        props: {
            store: { id: 1, name: "Copper's", slug: 'coppers', theme: 'coppers', whatsapp_phone: '7551112222', whatsapp_url: state.whatsapp },
            footer: state.footer,
        },
    }),
    Link: ({ children, href, ...rest }: { children: ReactNode; href: string }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

function wrap(children: ReactNode) {
    return (
        <ThemeContext.Provider value={{ name: 'coppers', motion: coppersRevealMotion, motionSet: coppersMotion, root: null }}>
            {children}
        </ThemeContext.Provider>
    );
}

const footer = (pages: string[], extra: Partial<StoreFooter> = {}): StoreFooter => ({
    pages: pages.map((slug) => ({ slug, title: slug, url: `/pagina/${slug}` })),
    address: null,
    hours: null,
    map_url: null,
    ...extra,
});

beforeEach(() => {
    state.footer = undefined;
    state.whatsapp = 'https://wa.me/527551112222';
});

afterEach(cleanup);

describe('pie de página', () => {
    it('solo enlaza las páginas publicadas que le manda el servidor, con alto táctil', () => {
        state.footer = footer(['contacto', 'aviso-de-privacidad'], { address: 'Av. Principal 123', hours: '10 a 19 h' });
        const { container } = render(wrap(<FooterDetails headingClassName="h" linkClassName="l" />));

        const links = [...container.querySelectorAll('nav a')];

        expect(links.map((a) => a.getAttribute('href'))).toEqual(['/pagina/contacto', '/pagina/aviso-de-privacidad']);
        links.forEach((a) => expect(a.className).toContain('min-h-11'));
        expect(container.textContent).toContain('Av. Principal 123');
        expect(container.textContent).toContain('10 a 19 h');
    });

    it('sin páginas publicadas ni dirección no deja columnas vacías', () => {
        state.footer = footer([]);
        state.whatsapp = null;
        const { container } = render(wrap(<FooterDetails headingClassName="h" linkClassName="l" />));

        expect(container.querySelector('nav')).toBeNull();
        expect(container.querySelector('address')).toBeNull();
        expect(container.textContent).toBe('');
    });

    it('sin datos de pie (por ejemplo, una página de error temprana) no truena', () => {
        render(wrap(<FooterDetails headingClassName="h" linkClassName="l" />));

        expect(screen.getByText('Contacto')).toBeTruthy();
    });

    it('los dos temas usan el pie con sus propios estilos', () => {
        for (const theme of ['coppers', 'g5']) {
            expect(fs.readFileSync(path.resolve(__dirname, `../../Themes/${theme}/layout.tsx`), 'utf8'), theme).toContain('<FooterDetails');
        }
    });
});

describe('consentimiento', () => {
    it('enlaza el aviso y los términos cuando están publicados', () => {
        state.footer = footer(['aviso-de-privacidad', 'terminos-y-condiciones']);
        const { container } = render(<LegalConsent />);

        expect(container.textContent).toBe('Al continuar aceptas el Aviso de privacidad y los Términos y condiciones.');
        expect(screen.getByRole('link', { name: 'Aviso de privacidad' }).getAttribute('href')).toBe('/pagina/aviso-de-privacidad');
        expect(screen.getByRole('link', { name: 'Términos y condiciones' }).getAttribute('href')).toBe('/pagina/terminos-y-condiciones');
    });

    it('sin publicar quedan como texto, sin enlace', () => {
        state.footer = footer(['terminos-y-condiciones']);
        const { container } = render(<LegalConsent />);

        expect(container.textContent).toBe('Al continuar aceptas el Aviso de privacidad y los Términos y condiciones.');
        expect(screen.queryByRole('link', { name: 'Aviso de privacidad' })).toBeNull();
        expect(screen.getByRole('link', { name: 'Términos y condiciones' })).toBeTruthy();
    });

    it('aparece en el registro y en el checkout', () => {
        for (const page of ['auth/register.tsx', 'checkout.tsx']) {
            expect(fs.readFileSync(path.resolve(__dirname, `../../pages/${page}`), 'utf8'), page).toContain('<LegalConsent');
        }
    });
});

describe('páginas de contenido y de error', () => {
    const read = (file: string) => fs.readFileSync(path.resolve(__dirname, `../../pages/${file}`), 'utf8');

    it('animan con Reveal (que termina visible aunque falle el observer) y llevan título y descripción', () => {
        for (const file of ['store-page.tsx', 'error.tsx']) {
            const source = read(file);

            expect(source, file).toContain('<Reveal');
            expect(source, file).not.toMatch(/from 'motion\/react'/);
            expect(source, file).toContain('<Head title=');
            expect(source, file).toContain('name="description"');
        }
    });

    it('la página de error tiene botón para volver al inicio', () => {
        expect(read('error.tsx')).toMatch(/href="\/"[\s\S]{0,140}Volver al inicio/);
    });
});
