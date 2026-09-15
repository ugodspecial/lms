import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        // Bound to all interfaces and permissive about the Host header so the
        // dev server works inside a container or behind a tunneling proxy —
        // the default 127.0.0.1-only binding is unreachable from outside.
        host: true,
        allowedHosts: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    build: {
        // Assets are committed to /public/build (ADR-13) because cPanel shared
        // hosting runs `git pull`, not `npm install`. Content-hashed names keep
        // browser caches correct across a deploy without a cache-bust query.
        //
        // build.manifest is deliberately NOT set here. laravel-vite-plugin pins
        // it to "manifest.json"; plain Vite 6+ defaults to ".vite/manifest.json".
        // Writing `manifest: true` accepts Vite's default and silently moves the
        // file out from under Illuminate\Foundation\Vite, which looks for
        // public/build/manifest.json and throws ViteManifestNotFoundException on
        // every page render. The build still emits a manifest — just where
        // Laravel expects it.
        assetsDir: 'assets',
        rollupOptions: {
            output: {
                entryFileNames: 'assets/[name]-[hash].js',
                chunkFileNames: 'assets/[name]-[hash].js',
                assetFileNames: 'assets/[name]-[hash][extname]',
            },
        },
    },
});
