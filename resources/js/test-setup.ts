import { afterEach, vi } from 'vitest';

/**
 * jsdom no trae IntersectionObserver. Este de prueba no cruza nada por su cuenta: una prueba hace que
 * todo «entre en pantalla» con `intersectAll()`. Para probar un navegador sin observer se usa
 * `vi.stubGlobal('IntersectionObserver', undefined)`.
 */
class FakeIntersectionObserver {
    static instances = new Set<FakeIntersectionObserver>();
    private elements = new Set<Element>();

    constructor(private callback: IntersectionObserverCallback) {
        FakeIntersectionObserver.instances.add(this);
    }

    observe(element: Element) {
        this.elements.add(element);
    }

    unobserve(element: Element) {
        this.elements.delete(element);
    }

    disconnect() {
        this.elements.clear();
        FakeIntersectionObserver.instances.delete(this);
    }

    observed() {
        return [...this.elements];
    }

    takeRecords() {
        return [];
    }

    fire() {
        const entries = [...this.elements].map(
            (target) => ({ target, isIntersecting: true, intersectionRatio: 1 }) as unknown as IntersectionObserverEntry,
        );

        if (entries.length > 0) {
            this.callback(entries, this as unknown as IntersectionObserver);
        }
    }
}

/** Hace que todo lo observado entre en pantalla. */
export function intersectAll() {
    for (const observer of [...FakeIntersectionObserver.instances]) {
        observer.fire();
    }
}

vi.stubGlobal('IntersectionObserver', FakeIntersectionObserver);

afterEach(() => {
    FakeIntersectionObserver.instances.clear();
    vi.stubGlobal('IntersectionObserver', FakeIntersectionObserver);
});

/** Los elementos que algún observer está vigilando ahora mismo. */
export function observedElements(): Element[] {
    return [...FakeIntersectionObserver.instances].flatMap((observer) => observer.observed());
}
