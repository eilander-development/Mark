import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue(),
        tailwindcss(),
        VitePWA({
            disable: true,
            registerType: 'autoUpdate',
            injectRegister: false,
            filename: 'vite-sw.js',
            outDir: 'public',
            scope: '/',
            base: '/',
            manifest: false,
        }),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**', '**/marker/**'],
        },
    },
});
