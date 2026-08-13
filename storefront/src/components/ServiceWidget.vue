<script setup lang="ts">
/**
 * 右下角在线客服(issue #7)
 * - 配置了第三方脚本(Chatwoot/Crisp 等):通过同源端点编译执行,由其原生渲染气泡,
 *   不再叠加本站按钮,避免双气泡/嵌套冲突;
 * - 未配置脚本:渲染本站链接浮窗(fallback,仅当配置了可跳转链接时)。
 * 注入为响应式:配置异步到达后自动执行(修复配置了不显示的问题)。
 */
import { ref, computed, watchEffect, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSettingsStore } from '@/stores/settings'
import AppIcon from '@/components/AppIcon.vue'

const { t, locale } = useI18n()
const settings = useSettingsStore()
const open = ref(false)

const widget = computed(() => settings.config?.service_widget || { enabled: false, links: [], script_configured: false })
/** 已配置第三方脚本 → 纯原生模式(不渲染本站 UI) */
const nativeMode = computed(() => !!widget.value.enabled && !!widget.value.script_configured)
/** 未配置脚本 → 本站链接浮窗 */
const links = computed(() => {
  const w = widget.value
  if (!w.enabled || nativeMode.value) return []
  return (w.links || []).filter((l: any) => l?.url)
})
const title = computed(() => {
  const w = widget.value
  const isEn = locale.value === 'en'
  if (isEn && w.title_en) return w.title_en
  return w.title || t('service.title')
})

/** 从同源端点加载已编译脚本，既兼容完整 <script> 代码，也不放宽 CSP 的内联限制。 */
let injected = false
const stop = watchEffect(() => {
  if (!nativeMode.value || injected) return
  const existing = document.querySelector('script[data-zcard-service-widget]')
  if (existing) {
    injected = true
    return
  }
  injected = true
  const el = document.createElement('script')
  el.src = '/api/settings/service-widget.js'
  el.async = true
  el.dataset.zcardServiceWidget = 'true'
  document.head.appendChild(el)
})

onUnmounted(() => stop())
</script>

<template>
  <!-- 原生模式:第三方脚本自带气泡,本站不渲染任何 UI -->
  <template v-if="nativeMode" />

  <!-- 链接浮窗(未配置第三方脚本时) -->
  <div v-else-if="links.length" class="fixed right-4 bottom-4 z-50 flex flex-col items-end">
    <Transition name="sw-pop">
      <div v-if="open" class="sw-panel mb-2 w-64 rounded-card border border-border bg-surface shadow-card overflow-hidden">
        <div class="px-4 py-3 bg-primary text-white text-sm font-bold flex items-center gap-2">
          <AppIcon name="ri:customer-service-2-line" class="w-4 h-4" />
          {{ title }}
        </div>
        <div class="py-1 max-h-72 overflow-y-auto">
          <a
            v-for="l in links"
            :key="l.label"
            :href="l.url"
            target="_blank"
            rel="noopener"
            class="flex items-center justify-between px-4 py-2.5 text-sm text-ink hover:bg-surface-subtle transition"
          >
            <span>{{ l.label }}</span>
            <span class="text-ink-muted">↗</span>
          </a>
        </div>
      </div>
    </Transition>

    <button
      class="w-12 h-12 rounded-full bg-primary text-white shadow-card flex items-center justify-center hover:bg-primary-hover transition active:scale-95"
      :aria-label="title"
      @click="open = !open"
    >
      <AppIcon v-if="!open" name="ri:customer-service-2-line" class="w-6 h-6" />
      <AppIcon v-else name="ri:close-line" class="w-6 h-6" />
    </button>
  </div>
</template>

<style scoped>
.sw-pop-enter-active,
.sw-pop-leave-active {
  transition: opacity 0.18s ease, transform 0.18s ease;
}
.sw-pop-enter-from,
.sw-pop-leave-to {
  opacity: 0;
  transform: translateY(8px);
}
</style>
