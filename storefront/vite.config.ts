import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import Icons from 'unplugin-icons/vite'

export default defineConfig(({ mode }) => {
  // 读取环境变量:生产用 VITE_BASE_URL=/storefront/,开发默认 /
  // 与 sysadmin 保持一致(见 sysadmin/.env.production),确保 base 与 outDir 路径层一致
  const env = loadEnv(mode, process.cwd(), '')
  const base = env.VITE_BASE_URL ?? (mode === 'production' ? '/storefront/' : '/')

  return {
    plugins: [
      vue(),
      tailwindcss(),
      Icons({ compiler: 'vue3', autoInstall: false }),
      {
        // SPA 入口 HTML 注入 no-cache meta:更新后浏览器不再用缓存的旧 index.html
        // 引用已删除的旧 hash JS(NoCacheHtml 中间件只对走 Laravel 的请求生效,
        // 静态服务环境下 index.html 不经框架,必须靠产物自带防缓存标记)。
        name: 'inject-no-cache-meta',
        transformIndexHtml(html: string) {
          return html.replace(
            '<head>',
            '<head>\n    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate" />\n    <meta http-equiv="Pragma" content="no-cache" />\n    <meta http-equiv="Expires" content="0" />',
          )
        },
      },
    ],
    // 资源 URL 前缀:必须与 outDir 一致,否则 index.html 引用 /assets/...
    // 而物理产物在 public/storefront/assets/...,差一层 → 404 / 白屏
    base,
    resolve: {
      alias: { '@': '/src' },
    },
    // 生产编译输出到 public/storefront/（Laravel 直接服务）
    build: {
      outDir: '../public/storefront',
      emptyOutDir: true,
    },
    server: {
      port: 5173,
      proxy: {
        '/api': {
          target: 'http://localhost:8092',
          changeOrigin: true,
        },
        '/storage': {
          target: 'http://localhost:8092',
          changeOrigin: true,
        },
      },
    },
  }
})
