<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { getHealth, type HealthResp } from '@/api/health'

const health = ref<HealthResp | null>(null)
const err = ref('')
onMounted(async () => {
  try {
    health.value = await getHealth()
  } catch (e) {
    err.value = 'API 未连通（确保 Laravel 已启动）'
  }
})
</script>

<template>
  <div class="max-w-6xl mx-auto px-4 py-10">
    <div class="rounded-card bg-white shadow-card p-8 mb-6">
      <h1 class="text-3xl font-bold text-ink mb-2">ZCard 商城</h1>
      <p class="text-ink-muted">现代化、插件制虚拟发卡系统（Phase 0 骨架）</p>
      <div class="mt-4 flex gap-3 flex-wrap">
        <span class="px-3 py-1 rounded-field bg-primary text-white text-sm">主色 #2563EB</span>
        <span class="px-3 py-1 rounded-field bg-success text-white text-sm">成功</span>
        <span class="px-3 py-1 rounded-field bg-warning text-white text-sm">警告</span>
        <span class="px-3 py-1 rounded-field bg-danger text-white text-sm">危险</span>
        <span class="px-3 py-1 rounded-field bg-accent text-white text-sm">点缀</span>
      </div>
    </div>
    <div class="rounded-card bg-surface-subtle p-6 text-ink-soft">
      API 健康检查：
      <span v-if="health" class="text-success font-mono">{{ health.status }} · {{ health.time }}</span>
      <span v-else-if="err" class="text-danger">{{ err }}</span>
      <span v-else class="text-ink-muted">检测中…</span>
    </div>
  </div>
</template>
