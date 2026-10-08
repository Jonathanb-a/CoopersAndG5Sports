import { cleanup, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CoppersLayout from './layout';

const state = vi.hoisted(() => ({ reduced: false, url: '/' }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: state.url,
        props: {
            store: { id: 1, name: "Copper's", slug: 'coppers', theme: 'coppers', whatsapp_phone: null, whatsapp_url: null },
            categories: [{ id: 1, name: 'Camisas', slug: 'camisas' }],
            miniCart: { count: 0, subtotal: '0.00', lines: [] },
            auth: { customer: null },
            googleEnabled: false,
        },
    }),
    Head: () => null,
    router: { post: () => undefined },
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

beforeEach(() => {
    state.reduced = false;
    state.url = '/';
    window.matchMedia = ((query: string) => ({
        matches: false,
        media: query,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
    })) as unknown as typeof window.matchMedia;
});

afterEach(cleanup);

describe('layout de Copper', () => {
    it('renderiza encabezado, contenido y pie sin errores', () => {
        const { container } = render(
            <CoppersLayout>
                <p>Contenido de la página</p>
            </CoppersLayout>,
        );

        expect(container.querySelector('header')).not.toBeNull();
        expect(container.querySelector('main')).not.toBeNull();
        expect(container.querySelector('footer')).not.toBeNull();
        expect(screen.getByText('Contenido de la página')).toBeTruthy();
        // Las duraciones del cajón salen de motion.ts como variables de CSS.
        expect(container.querySelector<HTMLElement>('[data-theme="coppers"]')!.style.getPropertyValue('--motion-drawer-duration')).toBe('400ms');
    });

    it('con prefers-reduced-motion el contenido se ve de inmediato, sin fundido', () => {
        state.reduced = true;
        const { container } = render(
            <CoppersLayout>
                <p>Contenido de la página</p>
            </CoppersLayout>,
        );

        const main = container.querySelector('main')!;

        expect(main.innerHTML).not.toContain('opacity');
        expect(main.innerHTML).not.toContain('transform');
        expect(container.querySelector('header')!.getAttribute('style') ?? '').not.toContain('transform: translateY(-100%)');
    });

    it('en el checkout no hay fundido', () => {
        state.url = '/checkout';
        const { container } = render(
            <CoppersLayout>
                <p>Pagar</p>
            </CoppersLayout>,
        );

        expect(container.querySelector('main')!.innerHTML).not.toContain('opacity');
    });
});
