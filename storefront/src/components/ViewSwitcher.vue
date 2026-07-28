<script setup lang="ts">
import { useSettingsStore } from '@/stores/settings'
const settings = useSettingsStore()
const views = [
  { key: 'grid', icon: '⊞', label: '网格' },
  { key: 'list', icon: '☰', label: '列表' },
  { key: 'dual', icon: '▦', label: '双栏' },
] as const
</script>

<template>
  <div class="inline-flex border border-gray-200 rounded-lg overflow-hidden">
    <button
      v-for="v in views" :key="v.key"
      @click="settings.setView(v.key)"
      :class="['px-3 py-1.5 text-sm', settings.effectiveView === v.key ? 'bg-primary text-white' : 'bg-white text-gray-500 hover:bg-gray-50']"
      :title="v.label"
    >{{ v.icon }}</button>
  </div>
</template>
