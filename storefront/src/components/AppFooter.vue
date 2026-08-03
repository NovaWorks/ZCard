<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSettingsStore } from '@/stores/settings'

const { t } = useI18n()
const settings = useSettingsStore()
// 确保配置已加载(页脚为全局组件,兜底加载避免直达子页时 config 为 null)
onMounted(() => {
  settings.load()
  injectAnalytics()
})
const cfg = computed(() => settings.config)

const siteName = computed(() => cfg.value?.site_name || 'ZCard')
const about = computed(() => cfg.value?.footer_about || '')
const links = computed(() => cfg.value?.footer_links || [])
const contacts = computed(() => cfg.value?.footer_contact || [])
const copyright = computed(() => cfg.value?.footer_copyright || `© ${new Date().getFullYear()} ${siteName.value}`)

/** 社交链接:只显示有 url 的 */
const socials = computed(() =>
  (cfg.value?.footer_social || []).filter(s => s.url && s.url.trim())
)

/** 客服联络:只显示有 value 的 */
const visibleContacts = computed(() =>
  (cfg.value?.footer_contact || []).filter(c => c.value && c.value.trim())
)

/** 注入第三方统计代码(百度统计/Google Analytics) */
function injectAnalytics() {
  const code = cfg.value?.footer_analytics
  if (!code || !code.trim()) return
  // 创建 script 标签注入到 body 底部
  const div = document.createElement('div')
  div.innerHTML = code.trim()
  // 提取 script 标签并重新创建(直接 innerHTML 的 script 不会执行)
  div.querySelectorAll('script').forEach(oldScript => {
    const newScript = document.createElement('script')
    Array.from(oldScript.attributes).forEach(attr => newScript.setAttribute(attr.name, attr.value))
    newScript.textContent = oldScript.textContent
    oldScript.replaceWith(newScript)
  })
  document.body.appendChild(div)
}

/** 内部链接(router 跳转) vs 外部链接(新窗口) */
function isExternal(url: string) {
  return /^https?:\/\//i.test(url)
}
</script>

<template>
  <footer class="bg-surface-subtle border-t border-border/60 mt-0">
    <!-- 信任徽章 -->
    <div class="border-b border-border">
      <div class="max-w-6xl mx-auto px-4 py-6 grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="flex items-center gap-2 sm:gap-3">
          <span class="w-9 h-9 sm:w-11 sm:h-11 bg-primary-light text-primary rounded-card flex items-center justify-center text-lg sm:text-xl shrink-0">🚀</span>
          <div class="min-w-0">
            <div class="text-xs font-semibold text-ink">{{ t('footer.badge.fastTitle') }}</div>
            <div class="text-[10px] text-ink-muted mt-0.5 truncate">{{ t('footer.badge.fastHint') }}</div>
          </div>
        </div>
        <div class="flex items-center gap-2 sm:gap-3">
          <span class="w-9 h-9 sm:w-11 sm:h-11 bg-green-50 text-success rounded-card flex items-center justify-center text-lg sm:text-xl shrink-0">🔒</span>
          <div class="min-w-0">
            <div class="text-xs font-semibold text-ink">{{ t('footer.badge.secureTitle') }}</div>
            <div class="text-[10px] text-ink-muted mt-0.5 truncate">{{ t('footer.badge.secureHint') }}</div>
          </div>
        </div>
        <div class="flex items-center gap-2 sm:gap-3">
          <span class="w-9 h-9 sm:w-11 sm:h-11 bg-orange-50 text-warning rounded-card flex items-center justify-center text-lg sm:text-xl shrink-0">⏰</span>
          <div class="min-w-0">
            <div class="text-xs font-semibold text-ink">{{ t('footer.badge.onlineTitle') }}</div>
            <div class="text-[10px] text-ink-muted mt-0.5 truncate">{{ t('footer.badge.onlineHint') }}</div>
          </div>
        </div>
        <div class="flex items-center gap-2 sm:gap-3">
          <span class="w-9 h-9 sm:w-11 sm:h-11 bg-blue-50 text-primary rounded-card flex items-center justify-center text-lg sm:text-xl shrink-0">💬</span>
          <div class="min-w-0">
            <div class="text-xs font-semibold text-ink">{{ t('footer.badge.supportTitle') }}</div>
            <div class="text-[10px] text-ink-muted mt-0.5">{{ t('footer.badge.supportHint') }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- 主体:四栏布局(手机2列,桌面4列) -->
    <div class="max-w-6xl mx-auto px-4 py-8 sm:py-10">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-6 sm:gap-8">
        <!-- 栏 1:品牌介绍(手机占整行) -->
        <div class="col-span-2 lg:col-span-1">
          <div class="flex items-center gap-2 mb-3">
            <span class="w-8 h-8 bg-gradient-to-br from-primary to-primary-hover rounded-[8px] text-white font-extrabold flex items-center justify-center">Z</span>
            <span class="text-lg font-extrabold text-ink">{{ siteName }}</span>
          </div>
          <p class="text-xs text-ink-soft leading-relaxed">{{ about }}</p>
          <!-- 社交链接(只显示有 url 的) -->
          <div v-if="socials.length" class="flex flex-wrap gap-2 mt-4">
            <a
              v-for="s in socials" :key="s.name"
              :href="s.url" :target="isExternal(s.url) ? '_blank' : undefined" rel="noopener"
              class="inline-flex items-center gap-1.5 bg-white border border-border rounded-field px-2.5 py-1 text-[11px] text-ink-soft hover:border-primary hover:text-primary hover:bg-primary-light transition"
              :title="s.name"
            >
              <span>{{ s.icon }}</span>
              <span>{{ s.name }}</span>
            </a>
          </div>
        </div>

        <!-- 栏 2:快速导航(官网频道) -->
        <div v-if="links.length">
          <h4 class="text-sm font-bold text-ink mb-3">{{ t('footer.quickNavTitle') }}</h4>
          <ul class="space-y-2">
            <li v-for="l in links" :key="l.title">
              <router-link v-if="!isExternal(l.url)" :to="l.url"
                class="text-xs text-ink-soft hover:text-primary transition">{{ l.title }}</router-link>
              <a v-else :href="l.url" target="_blank" rel="noopener"
                class="text-xs text-ink-soft hover:text-primary transition">{{ l.title }} ↗</a>
            </li>
          </ul>
        </div>

        <!-- 栏 3:客服联络 -->
        <div v-if="visibleContacts.length">
          <h4 class="text-sm font-bold text-ink mb-3">{{ t('footer.contactTitle') }}</h4>
          <ul class="space-y-2.5">
            <li v-for="(c, i) in visibleContacts" :key="i" class="flex items-start gap-2">
              <span class="text-ink-muted text-xs shrink-0 w-16">{{ c.label }}</span>
              <span class="text-xs text-ink-soft break-all">{{ c.value }}</span>
            </li>
          </ul>
        </div>

        <!-- 栏 4:帮助中心 -->
        <div>
          <h4 class="text-sm font-bold text-ink mb-3">{{ t('footer.helpTitle') }}</h4>
          <ul class="space-y-2">
            <li><router-link to="/orders/query" class="text-xs text-ink-soft hover:text-primary transition">{{ t('nav.orders') }}</router-link></li>
            <li><router-link to="/orders/mine" class="text-xs text-ink-soft hover:text-primary transition">{{ t('nav.mine') }}</router-link></li>
            <li><span class="text-xs text-ink-soft">{{ t('footer.helpFaq') }}</span></li>
            <li><span class="text-xs text-ink-soft">{{ t('footer.helpNotice') }}</span></li>
          </ul>
        </div>
      </div>
    </div>

    <!-- 版权栏 -->
    <div class="border-t border-border">
      <div class="max-w-6xl mx-auto px-4 py-4 flex flex-col md:flex-row items-center justify-between gap-2 text-center md:text-left">
        <span class="text-xs text-ink-muted break-all">{{ copyright }}</span>
        <div class="flex items-center gap-4 text-[10px] text-ink-muted shrink-0">
          <span>{{ t('footer.poweredBy') }}</span>
          <span class="hidden md:inline">·</span>
          <span class="hidden md:inline">{{ t('footer.autoDelivery') }}</span>
        </div>
      </div>
    </div>
  </footer>
</template>
