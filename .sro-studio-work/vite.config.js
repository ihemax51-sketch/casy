import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/css/casy-redesign.css', 'resources/css/player-tool.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
});
