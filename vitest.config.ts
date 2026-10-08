import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

// Pruebas del frontend (movimiento de los temas). No afectan al build de Vite.
export default defineConfig({
    esbuild: { jsx: 'automatic' },
    resolve: { alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) } },
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.{ts,tsx}'],
    },
});
