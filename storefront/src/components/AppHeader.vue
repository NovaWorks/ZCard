<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { usePreferencesStore } from '@/stores/preferences'

const router = useRouter()
const authStore = useAuthStore()
const settings = useSettingsStore()
const prefs = usePreferencesStore()
const { t } = useI18n()
// 确保配置已加载(直达子页时 config 可能为 null)
onMounted(() => {
  settings.load()
  prefs.load()
})
const siteName = computed(() => settings.config?.site_name || 'ZCard')
const siteLogo = computed(() => settings.config?.site_logo || '')
// 货币切换:set 后整页刷新以重新拉取价格
const currencySel = computed({
  get: () => prefs.currency,
  set: (code: string) => {
    prefs.setCurrency(code)
    location.reload()
  },
})
// 语言切换:vue-i18n 响应式重渲染,无需刷新
const langSel = computed({
  get: () => prefs.language || 'zh',
  set: (v: string) => prefs.setLanguage(v),
})

async function logout() {
  await authStore.logout()
  router.push('/')
}

function languageLabel(code: string): string {
  const labels: Record<string, string> = { zh: '中文', en: 'English' }
  return labels[code] || code
}
</script>

<template>
  <!-- 顶部品牌条 (深蓝渐变) -->
  <div class="bg-gradient-to-r from-primary-hover to-primary text-white text-xs">
    <div class="max-w-6xl mx-auto px-4 h-8 flex items-center justify-between">
      <span class="opacity-90">{{ t('nav.brandBar.slogan') }}</span>
      <div class="flex items-center gap-3 opacity-90">
        <span>{{ t('nav.brandBar.securePay') }}</span>
        <span>{{ t('nav.brandBar.privacy') }}</span>
      </div>
    </div>
  </div>

  <!-- 主导航 -->
  <header class="bg-white border-b border-border">
    <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
      <RouterLink to="/" class="flex items-center gap-2">
        <!-- Logo (优先使用自定义 logo,否则用首字母方块) -->
        <img v-if="siteLogo" :src="siteLogo" :alt="siteName" class="w-9 h-9 rounded-[10px] object-cover shadow-sm" />
        <span v-else class="w-9 h-9 bg-gradient-to-br from-primary to-primary-hover rounded-[10px] text-white font-extrabold flex items-center justify-center text-lg shadow-sm">Z</span>
        <span class="text-xl font-extrabold text-ink tracking-tight">{{ siteName }}</span>
      </RouterLink>
      <nav class="flex items-center gap-1 text-sm">
        <RouterLink to="/" class="px-3 py-1.5 rounded-field text-ink-soft hover:text-primary hover:bg-primary-light transition">{{ t('nav.home') }}</RouterLink>
        <RouterLink to="/orders/query" class="px-3 py-1.5 rounded-field text-ink-soft hover:text-primary hover:bg-primary-light transition">{{ t('nav.orders') }}</RouterLink>
        <!-- 货币切换器 -->
        <select v-model="currencySel" class="px-2 py-1 ml-1 rounded-field border border-border text-xs text-ink-soft bg-white">
          <option v-for="c in prefs.currencies" :key="c.code" :value="c.code">{{ c.code }}</option>
        </select>
        <!-- 语言切换器 (vue-i18n 响应式,无需刷新) -->
        <select v-model="langSel" class="px-2 py-1 ml-1 rounded-field border border-border text-xs text-ink-soft bg-white">
          <option v-for="lang in prefs.languages" :key="lang" :value="lang">{{ languageLabel(lang) }}</option>
        </select>
        <template v-if="authStore.isLoggedIn">
          <RouterLink to="/orders/mine" class="px-3 py-1.5 rounded-field text-ink-soft hover:text-primary hover:bg-primary-light transition">{{ t('nav.mine') }}</RouterLink>
          <RouterLink v-if="settings.config?.distribution_enabled" to="/distribution" class="px-3 py-1.5 rounded-field text-ink-soft hover:text-primary hover:bg-primary-light transition">{{ t('nav.distribution') }}</RouterLink>
          <span class="px-2 text-ink-muted">|</span>
          <span class="text-ink font-medium px-1">{{ authStore.user?.username }}</span>
          <button @click="logout" class="ml-1 px-3 py-1.5 rounded-field text-ink-soft hover:text-danger hover:bg-red-50 transition">{{ t('nav.logout') }}</button>
        </template>
        <template v-else>
          <RouterLink to="/login" class="px-3 py-1.5 rounded-field text-primary hover:bg-primary-light transition">{{ t('nav.login') }}</RouterLink>
          <RouterLink to="/register"
            class="ml-1 px-4 py-1.5 rounded-field bg-primary text-white hover:bg-primary-hover transition shadow-sm">{{ t('nav.register') }}</RouterLink>
        </template>
      </nav>
    </div>
  </header>
</template>
