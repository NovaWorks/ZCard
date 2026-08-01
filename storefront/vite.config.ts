import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [vue(), tailwindcss()],
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
})
