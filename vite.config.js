import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite'; // <-- This line is required for v4

export default defineConfig({
    plugins: [
        tailwindcss(), // <-- This runs the v4 compiler
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // Keep any other template plugins here if you have them
        }),
    ],
});