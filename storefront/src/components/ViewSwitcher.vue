<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSettingsStore } from '@/stores/settings'
const { t } = useI18n()
const settings = useSettingsStore()
const views = computed(() => [
  { key: 'grid', icon: '⊞', label: t('view.grid') },
  { key: 'list', icon: '☰', label: t('view.list') },
  { key: 'dual', icon: '▦', label: t('view.dual') },
] as const)
</script>

<template>
  <div class="inline-flex bg-surface-subtle rounded-field p-0.5">
    <button
      v-for="v in views" :key="v.key"
      @click="settings.setView(v.key)"
      :class="['px-3 py-1.5 text-sm rounded-[6px] transition', settings.effectiveView === v.key ? 'bg-white text-primary shadow-sm font-medium' : 'text-ink-muted hover:text-ink-soft']"
      :title="v.label"
    >{{ v.icon }}</button>
  </div>
</template>
