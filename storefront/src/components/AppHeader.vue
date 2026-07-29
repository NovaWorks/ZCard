<script setup lang="ts">
import { RouterLink, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()

async function logout() {
  await authStore.logout()
  router.push('/')
}
</script>

<template>
  <header class="bg-white border-b shadow-card">
    <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
      <RouterLink to="/" class="text-xl font-bold text-primary">ZCard</RouterLink>
      <nav class="space-x-4 text-ink-soft">
        <RouterLink to="/">首页</RouterLink>
        <RouterLink to="/orders/query">订单查询</RouterLink>
        <template v-if="authStore.isLoggedIn">
          <span class="text-ink">{{ authStore.user?.username }}</span>
          <button @click="logout" class="text-primary">退出</button>
        </template>
        <template v-else>
          <RouterLink to="/login">登录</RouterLink>
          <RouterLink to="/register">注册</RouterLink>
        </template>
      </nav>
    </div>
  </header>
</template>
