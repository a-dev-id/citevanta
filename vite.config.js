import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/sass/app.scss', 'resources/js/app.js', 'resources/sass/public.scss', 'resources/js/public.js'],
            refresh: true,
        }),
    ],
    css: {
        preprocessorOptions: {
            scss: {
                // Bootstrap 5.3 still uses @import and global Sass functions.
                quietDeps: true,
                silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'if-function'],
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**', '**/vendor/**', '**/.claude/**'],
        },
    },
});
