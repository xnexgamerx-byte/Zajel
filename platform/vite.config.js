import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // صفحات الدخول بملفّيها وحدهما (layouts/auth): لا تحمّل النظام كلّه
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/behaviors.js', 'resources/css/login.css', 'resources/js/login.js'],
            refresh: true,
            // الخطوط تُحمَّل من المتصفّح عبر <link> في القالب، لا وقت البناء:
            // ربط البناء بخادم خطوط خارجي يجعل النشر يفشل كلّما تعطّل ذلك الخادم.
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
