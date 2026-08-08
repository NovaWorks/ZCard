<script setup lang="ts">
import { computed, ref, onMounted } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { usePreferencesStore } from '@/stores/preferences'
import { useCartStore } from '@/stores/cart'
import { emit } from '@/utils/eventBus'
import AppIcon from '@/components/AppIcon.vue'

const router = useRouter()
const authStore = useAuthStore()
const settings = useSettingsStore()
const prefs = usePreferencesStore()
const cart = useCartStore()
const { t } = useI18n()
// 确保配置已加载(直达子页时 config 可能为 null)
onMounted(() => {
  settings.load()
  prefs.load()
})
const siteName = computed(() => settings.config?.site_name || 'ZCard')
const siteLogo = computed(() => settings.config?.site_logo || '')
// 有公告内容时显示顶部公告入口(点击重新弹出公告弹窗)
const hasNotice = computed(() => !!settings.config?.site_notice?.trim())
function openNotice() {
  emit('notice:open')
}
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

/** 移动端菜单展开状态 */
const mobileMenuOpen = ref(false)
</script>

<template>
  <!-- 顶部品牌条 (深蓝渐变) -->
  <div class="bg-gradient-to-r from-primary-hover to-primary text-white text-xs">
    <div class="max-w-6xl mx-auto px-4 h-8 flex items-center justify-between overflow-hidden">
      <span class="opacity-90 truncate flex items-center gap-2 min-w-0">
        <span class="truncate">{{ t('nav.brandBar.slogan') }}</span>
      </span>
      <div class="hidden sm:flex items-center gap-3 opacity-90 shrink-0">
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
      <!-- 桌面端导航 (md 以上显示) -->
      <nav class="hidden md:flex items-center gap-1 text-sm">
        <RouterLink to="/" class="px-3 py-1.5 rounded-field text-ink-soft hover:text-primary hover:bg-primary-light transition">{{ t('nav.home') }}</RouterLink>
        <RouterLink to="/orders/query" class="px-3 py-1.5 rounded-field text-ink-soft hover:text-primary hover:bg-primary-light transition">{{ t('nav.orders') }}</RouterLink>
        <!-- 购物车入口(角标显示数量) -->
        <RouterLink to="/checkout?cart=1" class="relative px-2 py-1.5 rounded-field text-ink-soft hover:text-primary hover:bg-primary-light transition">
          <AppIcon name="ri:shopping-cart-2-line" class="w-5 h-5" />
          <span
            v-if="cart.totalQty > 0"
            class="absolute -top-0.5 -right-0.5 min-w-[16px] h-4 px-1 rounded-full bg-danger text-white text-[10px] font-bold flex items-center justify-center"
          >{{ cart.totalQty > 99 ? '99+' : cart.totalQty }}</span>
        </RouterLink>
        <template v-if="authStore.isLoggedIn">
          <RouterLink to="/orders/mine" class="px-3 py-1.5 rounded-field text-ink-soft hover:text-primary hover:bg-primary-light transition">{{ t('nav.mine') }}</RouterLink>
          <RouterLink v-if="settings.config?.distribution_enabled" to="/distribution" class="px-3 py-1.5 rounded-field text-ink-soft hover:text-primary hover:bg-primary-light transition">{{ t('nav.distribution') }}</RouterLink>
          <!-- 公告入口:有公告时显示,点击重新弹出公告弹窗(放在推广中心后面) -->
          <button v-if="hasNotice" @click="openNotice" class="px-3 py-1.5 rounded-field text-ink-soft hover:text-primary hover:bg-primary-light transition inline-flex items-center gap-1 cursor-pointer">
            <AppIcon name="ri:megaphone-line" class="w-4 h-4" /> <span class="underline underline-offset-2">{{ t('nav.brandBar.notice') }}</span>
          </button>
          <RouterLink v-if="settings.config?.subsite_enabled" to="/my-subsite" class="px-3 py-1.5 rounded-field text-ink-soft hover:text-primary hover:bg-primary-light transition">{{ t('nav.mySubsite') }}</RouterLink>
          <span class="px-2 text-ink-muted">|</span>
          <RouterLink to="/user" class="px-3 py-1.5 rounded-field text-ink font-medium hover:text-primary hover:bg-primary-light transition inline-flex items-center gap-1">
            <AppIcon name="ri:user-3-line" class="w-4 h-4" /> {{ authStore.user?.username }}
          </RouterLink>
          <button @click="logout" class="ml-1 px-3 py-1.5 rounded-field text-ink-soft hover:text-danger hover:bg-red-50 transition">{{ t('nav.logout') }}</button>
        </template>
        <template v-else>
          <RouterLink to="/login" class="px-3 py-1.5 rounded-field text-primary hover:bg-primary-light transition">{{ t('nav.login') }}</RouterLink>
          <RouterLink to="/register"
            class="ml-1 px-4 py-1.5 rounded-field bg-primary text-white hover:bg-primary-hover transition shadow-sm">{{ t('nav.register') }}</RouterLink>
        </template>
        <!-- 右侧尾部:货币 + 语言切换器(加载完成且多于1种时显示,避免首帧空数组闪烁) -->
        <div
          v-if="prefs.loaded && (prefs.currencies.length > 1 || prefs.languages.length > 1)"
          class="flex items-center gap-1 ml-2 pl-2 border-l border-border"
        >
          <select v-if="prefs.currencies.length > 1" v-model="currencySel" class="px-2 py-1 rounded-field border border-border text-xs text-ink-soft bg-white cursor-pointer">
            <option v-for="c in prefs.currencies" :key="c.code" :value="c.code">{{ c.code }}</option>
          </select>
          <select v-if="prefs.languages.length > 1" v-model="langSel" class="px-2 py-1 rounded-field border border-border text-xs text-ink-soft bg-white cursor-pointer">
            <option v-for="lang in prefs.languages" :key="lang" :value="lang">{{ languageLabel(lang) }}</option>
          </select>
        </div>
      </nav>

      <!-- 移动端汉堡按钮 (md 以下显示) -->
      <button @click="mobileMenuOpen = !mobileMenuOpen"
        class="md:hidden p-2 rounded-field text-ink-soft hover:bg-primary-light transition shrink-0">
        <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path v-if="!mobileMenuOpen" d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round"/>
          <path v-else d="M6 6l12 12M18 6l-12 12" stroke-linecap="round"/>
        </svg>
      </button>
    </div>

    <!-- 移动端下拉菜单 -->
    <div v-if="mobileMenuOpen" class="md:hidden border-t border-border bg-white px-4 py-3 space-y-2">
      <RouterLink to="/" @click="mobileMenuOpen = false" class="block px-3 py-2 rounded-field text-ink-soft hover:bg-primary-light transition text-sm">{{ t('nav.home') }}</RouterLink>
      <RouterLink to="/orders/query" @click="mobileMenuOpen = false" class="block px-3 py-2 rounded-field text-ink-soft hover:bg-primary-light transition text-sm">{{ t('nav.orders') }}</RouterLink>
      <RouterLink to="/checkout?cart=1" @click="mobileMenuOpen = false" class="block px-3 py-2 rounded-field text-ink-soft hover:bg-primary-light transition text-sm inline-flex items-center gap-1.5">
        <AppIcon name="ri:shopping-cart-2-line" class="w-4 h-4" /> {{ t('nav.cart') }}<span v-if="cart.totalQty > 0" class="text-danger font-bold"> ({{ cart.totalQty }})</span>
      </RouterLink>
      <template v-if="authStore.isLoggedIn">
        <RouterLink to="/user" @click="mobileMenuOpen = false" class="block px-3 py-2 rounded-field text-ink font-medium hover:bg-primary-light transition text-sm inline-flex items-center gap-1.5"><AppIcon name="ri:user-3-line" class="w-4 h-4" /> {{ authStore.user?.username }}</RouterLink>
        <RouterLink to="/orders/mine" @click="mobileMenuOpen = false" class="block px-3 py-2 rounded-field text-ink-soft hover:bg-primary-light transition text-sm">{{ t('nav.mine') }}</RouterLink>
        <RouterLink v-if="settings.config?.distribution_enabled" to="/distribution" @click="mobileMenuOpen = false" class="block px-3 py-2 rounded-field text-ink-soft hover:bg-primary-light transition text-sm">{{ t('nav.distribution') }}</RouterLink>
        <button v-if="hasNotice" @click="openNotice(); mobileMenuOpen = false" class="block w-full text-left px-3 py-2 rounded-field text-ink-soft hover:bg-primary-light transition text-sm inline-flex items-center gap-1.5"><AppIcon name="ri:megaphone-line" class="w-4 h-4" /> {{ t('nav.brandBar.notice') }}</button>
        <RouterLink v-if="settings.config?.subsite_enabled" to="/my-subsite" @click="mobileMenuOpen = false" class="block px-3 py-2 rounded-field text-ink-soft hover:bg-primary-light transition text-sm">{{ t('nav.mySubsite') }}</RouterLink>
        <button @click="logout(); mobileMenuOpen = false" class="block w-full text-left px-3 py-2 rounded-field text-ink-soft hover:text-danger hover:bg-red-50 transition text-sm">{{ t('nav.logout') }}</button>
      </template>
      <template v-else>
        <RouterLink to="/login" @click="mobileMenuOpen = false" class="block px-3 py-2 rounded-field text-primary hover:bg-primary-light transition text-sm">{{ t('nav.login') }}</RouterLink>
        <RouterLink to="/register" @click="mobileMenuOpen = false" class="block px-3 py-2 rounded-field bg-primary text-white hover:bg-primary-hover transition text-sm text-center">{{ t('nav.register') }}</RouterLink>
      </template>
      <!-- 货币 + 语言切换(加载完成且多于1种时显示;避免加载前闪烁) -->
      <div v-if="prefs.loaded && (prefs.currencies.length > 1 || prefs.languages.length > 1)" class="flex items-center gap-2 pt-2 border-t border-border">
        <select v-if="prefs.currencies.length > 1" v-model="currencySel" class="flex-1 px-2 py-1.5 rounded-field border border-border text-xs text-ink-soft bg-white">
          <option v-for="c in prefs.currencies" :key="c.code" :value="c.code">{{ c.code }}</option>
        </select>
        <select v-if="prefs.languages.length > 1" v-model="langSel" class="flex-1 px-2 py-1.5 rounded-field border border-border text-xs text-ink-soft bg-white">
          <option v-for="lang in prefs.languages" :key="lang" :value="lang">{{ languageLabel(lang) }}</option>
        </select>
      </div>
    </div>
  </header>
</template>
