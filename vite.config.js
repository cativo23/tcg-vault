import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/css/revamp.css',
                'resources/css/collection.css',
                'resources/css/sets.css',
                'resources/js/collection.js',
                'resources/css/card.css',
                'resources/js/card.js',
                'resources/css/activity.css',
                'resources/js/activity.js',
                'resources/js/landing-stage.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
});
