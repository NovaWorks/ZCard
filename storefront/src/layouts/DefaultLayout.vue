<script setup lang="ts">
import { ref, onMounted, computed, watchEffect } from 'vue'
import { useI18n } from 'vue-i18n'
import AppHeader from '@/components/AppHeader.vue'
import AppFooter from '@/components/AppFooter.vue'
import { useSettingsStore } from '@/stores/settings'
import { getStorefrontSettings } from '@/api/settings'

const { t } = useI18n()
const settings = useSettingsStore()
const maintenanceMode = ref(false)
const maintenanceMessage = ref('')

const siteNotice = computed(() => settings.config?.site_notice || '')

// SEO:动态设置标题和 meta description
watchEffect(() => {
  const config = settings.config
  if (config) {
    document.title = config.site_name || 'ZCard'
    // 设置 meta description
    let metaDesc = document.querySelector('meta[name="description"]')
    if (!metaDesc) {
      metaDesc = document.createElement('meta')
      metaDesc.setAttribute('name', 'description')
      document.head.appendChild(metaDesc)
    }
    metaDesc.setAttribute('content', config.site_description || config.site_name || 'ZCard')
  }
})

onMounted(async () => {
  try {
    const config = await getStorefrontSettings()
    settings.config = config
    settings.loaded = true
    maintenanceMode.value = !!config.maintenance_mode
    maintenanceMessage.value = config.maintenance_message || t('layout.maintenanceDefault')
  } catch (e: any) {
    // 维护模式时 API 返回 503
    if (e?.response?.status === 503) {
      maintenanceMode.value = true
      maintenanceMessage.value = e?.response?.data?.message || t('layout.maintenanceDefault')
    }
  }
})
</script>

<template>
  <!-- 维护模式 -->
  <div v-if="maintenanceMode" class="min-h-screen flex items-center justify-center bg-surface-subtle">
    <div class="bg-white rounded-card border border-border p-10 shadow-card text-center max-w-md">
      <div class="text-6xl mb-4">🔧</div>
      <h1 class="text-xl font-bold text-ink mb-2">{{ t('layout.maintenanceTitle') }}</h1>
      <p class="text-sm text-ink-muted">{{ maintenanceMessage }}</p>
    </div>
  </div>

  <!-- 正常页面 -->
  <div v-else class="min-h-screen flex flex-col bg-surface">
    <AppHeader />
    <!-- 店铺公告 -->
    <div v-if="siteNotice" class="bg-primary text-white text-center text-xs py-1.5 px-4">
      📢 {{ siteNotice }}
    </div>
    <main class="flex-1">
      <RouterView />
    </main>
    <AppFooter />
  </div>
</template>
