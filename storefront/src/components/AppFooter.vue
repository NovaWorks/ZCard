<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSettingsStore } from '@/stores/settings'
import AppIcon from '@/components/AppIcon.vue'

const { t, locale } = useI18n()
const settings = useSettingsStore()
// 确保配置已加载(页脚为全局组件,兜底加载避免直达子页时 config 为 null)
onMounted(() => {
  settings.load()
})
const cfg = computed(() => settings.config)

const siteName = computed(() => cfg.value?.site_name || 'ZCard')
const siteLogo = computed(() => cfg.value?.site_logo || '')
const about = computed(() => cfg.value?.footer_about || '')
const links = computed(() => cfg.value?.footer_links || [])
const helpLinks = computed(() => cfg.value?.footer_help_links || [])
const contacts = computed(() => cfg.value?.footer_contact || [])
const copyright = computed(() => cfg.value?.footer_copyright || `© ${new Date().getFullYear()} ${siteName.value}`)

/** 帮助中心链接标题多语言:英文界面优先 title_en,缺省回退 title(兼容旧数据) */
const helpTitle = (h: { title?: string; title_en?: string }) => {
  if (!h) return ''
  return locale.value === 'en' ? (h.title_en || h.title || '') : (h.title || '')
}

/** 底部固定入口:GitHub 仓库 + 群组(新窗口打开) */
const GITHUB_URL = 'https://github.com/NovaWorks/ZCard'
const GROUP_URL = 'http://t.me/ZhonCard'

/** 社交链接:只显示有 url 的 */
const socials = computed(() =>
  (cfg.value?.footer_social || []).filter(s => s.url && s.url.trim())
)

/** 客服联络:只显示有 value 的 */
const visibleContacts = computed(() =>
  (cfg.value?.footer_contact || []).filter(c => c.value && c.value.trim())
)

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
          <span class="w-9 h-9 sm:w-11 sm:h-11 bg-primary-light text-primary rounded-card flex items-center justify-center text-lg sm:text-xl shrink-0"><AppIcon name="ri:rocket-line" class="w-5 h-5 sm:w-6 sm:h-6" /></span>
          <div class="min-w-0">
            <div class="text-xs font-semibold text-ink">{{ t('footer.badge.fastTitle') }}</div>
            <div class="text-[10px] text-ink-muted mt-0.5 truncate">{{ t('footer.badge.fastHint') }}</div>
          </div>
        </div>
        <div class="flex items-center gap-2 sm:gap-3">
          <span class="w-9 h-9 sm:w-11 sm:h-11 bg-green-50 text-success rounded-card flex items-center justify-center text-lg sm:text-xl shrink-0"><AppIcon name="ri:shield-check-line" class="w-5 h-5 sm:w-6 sm:h-6" /></span>
          <div class="min-w-0">
            <div class="text-xs font-semibold text-ink">{{ t('footer.badge.secureTitle') }}</div>
            <div class="text-[10px] text-ink-muted mt-0.5 truncate">{{ t('footer.badge.secureHint') }}</div>
          </div>
        </div>
        <div class="flex items-center gap-2 sm:gap-3">
          <span class="w-9 h-9 sm:w-11 sm:h-11 bg-orange-50 text-warning rounded-card flex items-center justify-center text-lg sm:text-xl shrink-0"><AppIcon name="ri:time-line" class="w-5 h-5 sm:w-6 sm:h-6" /></span>
          <div class="min-w-0">
            <div class="text-xs font-semibold text-ink">{{ t('footer.badge.onlineTitle') }}</div>
            <div class="text-[10px] text-ink-muted mt-0.5 truncate">{{ t('footer.badge.onlineHint') }}</div>
          </div>
        </div>
        <div class="flex items-center gap-2 sm:gap-3">
          <span class="w-9 h-9 sm:w-11 sm:h-11 bg-blue-50 text-primary rounded-card flex items-center justify-center text-lg sm:text-xl shrink-0"><AppIcon name="ri:customer-service-2-line" class="w-5 h-5 sm:w-6 sm:h-6" /></span>
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
            <!-- Logo:优先显示自定义 logo 图片,否则用首字母方块 -->
            <img v-if="siteLogo" :src="siteLogo" :alt="siteName" class="w-8 h-8 rounded-[8px] object-cover" />
            <span v-else class="w-8 h-8 bg-gradient-to-br from-primary to-primary-hover rounded-[8px] text-white font-extrabold flex items-center justify-center">Z</span>
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

        <!-- 栏 4:帮助中心(后台页脚配置可编辑) -->
        <div>
          <h4 class="text-sm font-bold text-ink mb-3">{{ t('footer.helpTitle') }}</h4>
          <ul class="space-y-2">
            <li v-if="!helpLinks.length">
              <router-link to="/orders/query" class="text-xs text-ink-soft hover:text-primary transition">{{ t('nav.orders') }}</router-link>
            </li>
            <li v-for="h in helpLinks" :key="h.title">
              <router-link v-if="h.url" :to="h.url" class="text-xs text-ink-soft hover:text-primary transition">{{ helpTitle(h) }}</router-link>
              <span v-else class="text-xs text-ink-soft">{{ helpTitle(h) }}</span>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <!-- 版权栏(固定底部,滚动不消失) -->
    <div class="sticky bottom-0 z-10 border-t border-border bg-surface-subtle">
      <div class="max-w-6xl mx-auto px-4 py-4 flex flex-col md:flex-row items-center justify-between gap-3 text-center md:text-left">
        <span class="text-xs text-ink-muted break-all">{{ copyright }}</span>
        <div class="flex items-center gap-4 text-[10px] text-ink-muted shrink-0 flex-wrap justify-center">
          <a
            :href="GITHUB_URL"
            target="_blank"
            rel="noopener"
            class="inline-flex items-center gap-1 hover:text-primary transition"
          >GitHub ↗</a>
          <a
            :href="GROUP_URL"
            target="_blank"
            rel="noopener"
            class="inline-flex items-center gap-1 hover:text-primary transition"
          >{{ t('footer.telegramGroup') }} ↗</a>
          <span>{{ t('footer.poweredBy') }}</span>
        </div>
      </div>
    </div>
  </footer>
</template>
