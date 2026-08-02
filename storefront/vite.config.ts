import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig(({ mode }) => {
  // 读取环境变量:生产用 VITE_BASE_URL=/storefront/,开发默认 /
  // 与 sysadmin 保持一致(见 sysadmin/.env.production),确保 base 与 outDir 路径层一致
  const env = loadEnv(mode, process.cwd(), '')
  const base = env.VITE_BASE_URL ?? (mode === 'production' ? '/storefront/' : '/')

  return {
    plugins: [vue(), tailwindcss()],
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
      },
    },
  }
})
