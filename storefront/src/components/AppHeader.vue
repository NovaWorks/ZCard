<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <header class="bg-white border-b shadow-card">
    <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
      <RouterLink to="/" class="text-xl font-bold text-primary">ZCard</RouterLink>
      <nav class="space-x-4 text-ink-soft">
        <RouterLink to="/">首页</RouterLink>
        <RouterLink to="/login">登录</RouterLink>
        <RouterLink to="/register">注册</RouterLink>
      </nav>
    </div>
  </header>
</template>
