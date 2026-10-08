import { cleanup, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import G5Layout from './layout';

const state = vi.hoisted(() => ({ reduced: false, url: '/' }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: state.url,
        props: {
            store: { id: 2, name: 'Sports G5', slug: 'g5', theme: 'g5', whatsapp_phone: null, whatsapp_url: null },
            categories: [{ id: 1, name: 'Tenis', slug: 'tenis' }],
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

describe('layout de Sports G5', () => {
    it('renderiza encabezado, contenido y pie sin errores', () => {
        const { container } = render(
            <G5Layout>
                <p>Contenido de la página</p>
            </G5Layout>,
        );

        expect(container.querySelector('header')).not.toBeNull();
        expect(container.querySelector('main')).not.toBeNull();
        expect(container.querySelector('footer')).not.toBeNull();
        expect(screen.getByText('Contenido de la página')).toBeTruthy();

        const root = container.querySelector<HTMLElement>('[data-theme="g5"]')!;

        // Las variables de CSS (cajón, sello, pulso y deriva) salen de Themes/g5/motion.ts.
        expect(root.style.getPropertyValue('--motion-drawer-duration')).toBe('260ms');
        expect(root.style.getPropertyValue('--motion-drift-duration')).toBe('28s');
        expect(root.style.getPropertyValue('--motion-stamp-duration')).toBe('280ms');
    });

    it('con prefers-reduced-motion el contenido se ve de inmediato y el encabezado no se mueve', () => {
        state.reduced = true;
        const { container } = render(
            <G5Layout>
                <p>Contenido de la página</p>
            </G5Layout>,
        );

        const main = container.querySelector('main')!;

        expect(main.innerHTML).not.toContain('opacity');
        expect(main.innerHTML).not.toContain('transform');
        expect(container.querySelector('header')!.dataset.retracted).toBe('false');
    });

    it('en el checkout y en los formularios no hay fundido', () => {
        for (const url of ['/checkout', '/login', '/mi-cuenta']) {
            state.url = url;
            const { container, unmount } = render(
                <G5Layout>
                    <p>Pagar</p>
                </G5Layout>,
            );

            expect(container.querySelector('main')!.innerHTML, url).not.toContain('opacity');
            unmount();
        }
    });

    it('fuera de esas rutas hace un fundido breve de entrada', () => {
        const { container } = render(
            <G5Layout>
                <p>Contenido de la página</p>
            </G5Layout>,
        );

        expect(container.querySelector('main')!.innerHTML).toContain('opacity: 0');
    });
});
