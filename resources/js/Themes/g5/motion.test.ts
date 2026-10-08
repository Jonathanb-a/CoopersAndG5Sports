import { describe, expect, it } from 'vitest';
import { coppersMotion } from '../coppers/motion';
import { bannerPreset, isQuietPath, revealPreset, staggerDelay } from '../motion';
import { g5Motion, g5MotionVars } from './motion';

const desktop = { reduced: false, mobile: false };
const mobile = { reduced: false, mobile: true };
const reduced = { reduced: true, mobile: false };

describe('movimiento de Sports G5', () => {
    it('tiene entradas rápidas de 180 a 320 ms', () => {
        const seconds = [
            g5Motion.reveal.duration,
            g5Motion.reveal.mobileDuration,
            g5Motion.card.duration,
            g5Motion.header.duration,
            g5Motion.drawer.duration,
            g5Motion.page.duration,
            g5Motion.addToCart.fade,
            g5Motion.tag!.duration,
            g5Motion.heading!.duration,
            g5Motion.button!.pulseDuration,
        ];

        for (const value of seconds) {
            expect(value).toBeGreaterThanOrEqual(0.18);
            expect(value).toBeLessThanOrEqual(0.32);
        }
    });

    it('tiene desplazamientos marcados de 16 a 32 px', () => {
        for (const px of [g5Motion.reveal.distance, g5Motion.reveal.mobileDistance, g5Motion.banner!.distance]) {
            expect(px).toBeGreaterThanOrEqual(16);
            expect(px).toBeLessThanOrEqual(32);
        }
    });

    it('el easing tiene un poco de empuje: se pasa de 1 apenas', () => {
        const [, y1, , y2] = g5Motion.ease;

        expect(y1).toBeGreaterThan(1);
        expect(y1).toBeLessThanOrEqual(1.3);
        expect(y2).toBeLessThanOrEqual(1.3);
    });

    it('su escalonado es más corto y enérgico que el de Copper', () => {
        expect(g5Motion.reveal.stagger).toBeLessThan(coppersMotion.reveal.stagger);
        expect(g5Motion.reveal.maxStagger).toBeLessThan(coppersMotion.reveal.maxStagger);
        expect(staggerDelay(g5Motion, desktop, 5)).toBe(g5Motion.reveal.maxStagger);
    });

    it('la foto de la tarjeta se acerca más que en Copper y la tarjeta se inclina y se eleva', () => {
        expect(g5Motion.card.scale).toBeGreaterThan(coppersMotion.card.scale);
        expect(g5Motion.card.tilt).toBeGreaterThan(0);
        expect(g5Motion.card.lift).toBeGreaterThan(0);
        expect(g5Motion.card.tagStraighten).toBeGreaterThan(0);
    });

    it('tiene la misma estructura que Copper y las secciones propias de G5', () => {
        for (const key of Object.keys(coppersMotion) as (keyof typeof coppersMotion)[]) {
            expect(g5Motion, key).toHaveProperty(key);
        }

        for (const key of ['tag', 'heading', 'banner', 'button', 'backdrop'] as const) {
            expect(g5Motion[key], key).toBeDefined();
        }
    });

    it('la deriva de las manchas es lenta y muy sutil', () => {
        expect(g5Motion.backdrop!.duration).toBeGreaterThanOrEqual(20);
        expect(Math.abs(g5Motion.backdrop!.x)).toBeLessThanOrEqual(5);
        expect(Math.abs(g5Motion.backdrop!.y)).toBeLessThanOrEqual(5);
    });

    it('con prefers-reduced-motion no hay movimiento', () => {
        expect(revealPreset(g5Motion, reduced)).toBeNull();
        expect(bannerPreset(g5Motion, reduced)).toBeNull();
        expect(staggerDelay(g5Motion, reduced, 0.1)).toBe(0);
    });

    it('en celular es más ligero: menos desplazamiento, sin escalonado y banner sin escalado', () => {
        const light = revealPreset(g5Motion, mobile)!;
        const full = revealPreset(g5Motion, desktop)!;

        expect(light.initial.y).toBeLessThan(full.initial.y);
        expect(light.transition.duration).toBeLessThan(full.transition.duration);
        expect(staggerDelay(g5Motion, mobile, 0.1)).toBe(0);
        expect(bannerPreset(g5Motion, mobile)!.initial.scale).toBeUndefined();
        expect(bannerPreset(g5Motion, desktop)!.initial.scale).toBe(g5Motion.banner!.scale);
    });

    it('anima solo opacity y transform', () => {
        const preset = revealPreset(g5Motion, desktop)!;

        expect(Object.keys(preset.initial).sort()).toEqual(['opacity', 'y']);
        expect(Object.keys(bannerPreset(g5Motion, desktop)!.initial).sort()).toEqual(['opacity', 'scale', 'y']);
    });

    it('no anima el checkout, el carrito, los pedidos, mi cuenta ni los formularios', () => {
        for (const url of [
            '/checkout',
            '/carrito',
            '/pedido/G5-00001/gracias',
            '/mi-cuenta',
            '/mi-cuenta/pedidos',
            '/login',
            '/register',
            '/forgot-password',
            '/reset-password/abc',
        ]) {
            expect(isQuietPath(g5Motion, url), url).toBe(true);
        }

        for (const url of ['/', '/catalogo', '/categoria/tenis', '/producto/jersey']) {
            expect(isQuietPath(g5Motion, url), url).toBe(false);
        }
    });

    it('las variables de CSS salen de los mismos valores', () => {
        expect(g5MotionVars['--motion-drawer-duration']).toBe('260ms');
        expect(g5MotionVars['--motion-drift-duration']).toBe('28s');
        expect(g5MotionVars['--motion-pulse-scale']).toBe(String(g5Motion.button!.pulseScale));
    });
});

describe('Copper sigue sin cambios', () => {
    it('no tiene las secciones propias de G5', () => {
        for (const key of ['tag', 'heading', 'banner', 'button', 'backdrop'] as const) {
            expect(coppersMotion[key], key).toBeUndefined();
        }

        expect(coppersMotion.card.tilt).toBeUndefined();
        expect(coppersMotion.card.lift).toBeUndefined();
        expect(coppersMotion.addToCart.pop).toBeUndefined();
    });

    it('conserva sus valores', () => {
        expect(coppersMotion.ease).toEqual([0.22, 1, 0.36, 1]);
        expect(coppersMotion.reveal).toEqual({
            duration: 0.45,
            distance: 12,
            stagger: 0.06,
            maxStagger: 0.24,
            mobileDuration: 0.35,
            mobileDistance: 8,
        });
        expect(coppersMotion.card).toEqual({ scale: 1.03, duration: 0.5 });
        expect(coppersMotion.page.duration).toBe(0.3);
        expect(bannerPreset(coppersMotion, desktop)).toBeNull();
    });
});
