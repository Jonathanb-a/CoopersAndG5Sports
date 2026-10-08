import { describe, expect, it } from 'vitest';
import { coppersMotion } from './coppers/motion';
import { isQuietPath, revealPreset, staggerDelay } from './motion';

const desktop = { reduced: false, mobile: false };

describe("movimiento de Copper's", () => {
    it('usa entradas de 300 a 500 ms, desplazamientos de 8 a 16 px y nada que rebote', () => {
        const { reveal, card, header, drawer, page, ease } = coppersMotion;

        for (const seconds of [reveal.duration, reveal.mobileDuration, card.duration, header.duration, drawer.duration, page.duration]) {
            expect(seconds).toBeGreaterThanOrEqual(0.3);
            expect(seconds).toBeLessThanOrEqual(0.5);
        }

        for (const px of [reveal.distance, reveal.mobileDistance]) {
            expect(px).toBeGreaterThanOrEqual(8);
            expect(px).toBeLessThanOrEqual(16);
        }

        // Un easing que rebota pasa de 1 o baja de 0; este (ease-out) se queda dentro.
        expect(ease[1]).toBeGreaterThanOrEqual(0);
        expect(ease[1]).toBeLessThanOrEqual(1);
        expect(ease[3]).toBeLessThanOrEqual(1);
        // El acercamiento de la foto es muy leve.
        expect(card.scale).toBeLessThanOrEqual(1.05);
    });

    it('con prefers-reduced-motion no hay movimiento ni escalonado', () => {
        const reduced = { reduced: true, mobile: false };

        expect(revealPreset(coppersMotion, reduced)).toBeNull();
        expect(staggerDelay(coppersMotion, reduced, 0.2)).toBe(0);
    });

    it('la aparición anima solo opacity y transform (y)', () => {
        const preset = revealPreset(coppersMotion, desktop)!;

        expect(Object.keys(preset.initial).sort()).toEqual(['opacity', 'y']);
        expect(Object.keys(preset.animate).sort()).toEqual(['opacity', 'y']);
        expect(preset.initial.y).toBe(coppersMotion.reveal.distance);
    });

    it('en celular es más ligera: menos desplazamiento, más corta y sin escalonado', () => {
        const mobile = { reduced: false, mobile: true };
        const light = revealPreset(coppersMotion, mobile)!;
        const full = revealPreset(coppersMotion, desktop)!;

        expect(light.initial.y).toBeLessThan(full.initial.y);
        expect(light.transition.duration).toBeLessThan(full.transition.duration);
        expect(staggerDelay(coppersMotion, mobile, 0.2)).toBe(0);
    });

    it('el escalonado en escritorio tiene tope', () => {
        expect(staggerDelay(coppersMotion, desktop, 0.1)).toBe(0.1);
        expect(staggerDelay(coppersMotion, desktop, 5)).toBe(coppersMotion.reveal.maxStagger);
        expect(staggerDelay(coppersMotion, desktop, -1)).toBe(0);
    });

    it('no anima el checkout, el carrito ni los formularios', () => {
        for (const url of [
            '/checkout',
            '/checkout?x=1',
            '/carrito',
            '/login',
            '/register',
            '/forgot-password',
            '/reset-password/abc',
            '/mi-cuenta',
            '/mi-cuenta/pedidos/CP-00001',
            '/pedido/CP-00001/gracias',
        ]) {
            expect(isQuietPath(coppersMotion, url), url).toBe(true);
        }

        for (const url of ['/', '/catalogo', '/categoria/camisas', '/producto/camisa-lino']) {
            expect(isQuietPath(coppersMotion, url), url).toBe(false);
        }
    });
});
