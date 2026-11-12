import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { fontsource } from 'laravel-vite-plugin/fonts';
import { defineConfig, lazyPlugins } from 'vite-plus';

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
            ],
            refresh: true,
            // Downloaded at build time and served from this app, so no visitor
            // address reaches a font CDN.
            fonts: [
                fontsource('Barlow', { weights: [400, 500, 600] }),
                fontsource('Barlow Semi Condensed', { weights: [500, 600], preload: [{ weight: 600 }] }),
                fontsource('Spline Sans Mono', { weights: [400, 500], preload: false }),
            ],
        }),
        tailwindcss(),
    ]),
    server: {
        cors: true,
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/storage/framework/views/**',
                '**/vendor/**',
            ],
        },
    },
});
