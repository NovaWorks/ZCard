import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { fontsource } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            // issue #20:改用 fontsource 提供方,字体直接从已安装的
            // @fontsource/instrument-sans 读取,构建不再依赖 fonts.bunny.net 可用性
            // (受限网络/CDN 故障/DNS 问题不再阻断发布;字体与 400/500/600 字重保持不变)。
            fonts: [
                fontsource('Instrument Sans', {
                    weights: [400, 500, 600],
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
