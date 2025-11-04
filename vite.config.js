import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: {
                app: 'resources/js/app.js',
                stripe: 'resources/js/cs_stripe.js',
                authorize: 'resources/js/cs_authorize.js',
                css: 'resources/css/app.css',
            },
            refresh: true,
        }),
        tailwindcss(),
    ],
    build: {
        rollupOptions: {
            output: {
                entryFileNames: (chunk) => {
                    if (chunk.name === 'stripe') return 'assets/cs_stripe.js';
                    if (chunk.name === 'authorize') return 'assets/cs_authorize.js';
                    return 'assets/[name]-[hash].js';
                },
                assetFileNames: 'assets/[name].[ext]'
            }
        }
    }
});
