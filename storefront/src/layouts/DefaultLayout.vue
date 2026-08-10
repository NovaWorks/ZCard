<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed, watchEffect } from 'vue'
import { useI18n } from 'vue-i18n'
import AppHeader from '@/components/AppHeader.vue'
import AppFooter from '@/components/AppFooter.vue'
import NoticeModal from '@/components/NoticeModal.vue'
import { useSettingsStore } from '@/stores/settings'
import { getStorefrontSettings } from '@/api/settings'
import { on as onEvent } from '@/utils/eventBus'
import { setSeo } from '@/utils/seo'
import AppIcon from '@/components/AppIcon.vue'

const { t } = useI18n()
const settings = useSettingsStore()
const maintenanceMode = ref(false)
const maintenanceMessage = ref('')
const noticeModalRef = ref<InstanceType<typeof NoticeModal> | null>(null)

// 监听顶部公告入口点击事件 → 打开公告弹窗
let offNotice: (() => void) | null = null
onMounted(() => {
  offNotice = onEvent('notice:open', () => noticeModalRef.value?.open())
})
onUnmounted(() => offNotice?.())

// SEO:站点默认标题/描述/关键词(商品等页面会覆盖标题与描述)
watchEffect(() => {
  const config = settings.config
  if (config) {
    setSeo({
      title: config.site_name || 'ZCard',
      description: config.site_description || '',
      keywords: config.site_keywords || '',
      type: 'website',
    })
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
      <div class="text-6xl mb-4 inline-flex"><AppIcon name="ri:tools-line" class="w-16 h-16" /></div>
      <h1 class="text-xl font-bold text-ink mb-2">{{ t('layout.maintenanceTitle') }}</h1>
      <p class="text-sm text-ink-muted">{{ maintenanceMessage }}</p>
    </div>
  </div>

  <!-- 正常页面 -->
  <div v-else class="min-h-screen flex flex-col bg-surface">
    <AppHeader />
    <main class="flex-1">
      <RouterView />
    </main>
    <AppFooter />
    <!-- 公告弹窗:首次访问弹出(有公告内容时);顶部公告入口可随时打开 -->
    <NoticeModal ref="noticeModalRef" />
  </div>
</template>
