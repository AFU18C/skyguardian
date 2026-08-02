import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/source-copying.css',
                'resources/css/collapsible-cards.css',
                'resources/css/topbar-metrics.css',
                'resources/css/source-health.css',
                'resources/css/group-channel.css',
                'resources/css/site-settings.css',
                'resources/css/public-site.css',
                'resources/css/login-page.css',
                'resources/js/app.js',
                'resources/js/group-channel-management.js',
                'resources/js/collapsible-cards.js',
                'resources/js/topbar-metrics.js',
                'resources/js/site-page-editor.js',
                'resources/js/site-settings.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                    optimizedFallbacks: false,
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
