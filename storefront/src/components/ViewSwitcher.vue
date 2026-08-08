<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSettingsStore } from '@/stores/settings'
import AppIcon from '@/components/AppIcon.vue'
const { t } = useI18n()
const settings = useSettingsStore()
const views = computed(() => [
  { key: 'grid', icon: 'ri:layout-grid-line', label: t('view.grid') },
  { key: 'list', icon: 'ri:menu-line', label: t('view.list') },
  { key: 'dual', icon: 'ri:layout-2-line', label: t('view.dual') },
] as const)
</script>

<template>
  <div class="inline-flex bg-surface-subtle rounded-field p-0.5">
    <button
      v-for="v in views" :key="v.key"
      @click="settings.setView(v.key)"
      :class="['px-3 py-1.5 text-sm rounded-[6px] transition inline-flex items-center gap-1', settings.effectiveView === v.key ? 'bg-white text-primary shadow-sm font-medium' : 'text-ink-muted hover:text-ink-soft']"
      :title="v.label"
    ><AppIcon :name="v.icon" class="w-4 h-4" /></button>
  </div>
</template>
