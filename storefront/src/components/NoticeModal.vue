<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSettingsStore } from '@/stores/settings'
import AppIcon from '@/components/AppIcon.vue'

const { t } = useI18n()
const settings = useSettingsStore()

// localStorage 键:记录用户已关闭公告(仅第一次访问弹一次,关闭后本次会话不再弹)
const NOTICE_DISMISSED_KEY = 'zcard_notice_dismissed'
const visible = ref(false)

const noticeHtml = computed(() => settings.config?.site_notice || '')

/**
 * 公告内容做基本 XSS 过滤(与商品描述一致):
 * 移除 script/iframe/style/link 与 on* 事件属性,防注入。
 */
const sanitizedNotice = computed(() => {
  const html = noticeHtml.value
  return html
    .replace(/<script[\s\S]*?<\/script>/gi, '')
    .replace(/<iframe[\s\S]*?<\/iframe>/gi, '')
    .replace(/<style[\s\S]*?<\/style>/gi, '')
    .replace(/<link[\s\S]*?>/gi, '')
    .replace(/\s+on\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '')
})

// 监听公告内容:settings 异步加载完成后自动弹出(不能只靠 onMounted,
// 此时 settings.config 可能尚未就绪导致不弹)
watch(noticeHtml, (html) => {
  if (html.trim() && !visible.value && !localStorage.getItem(NOTICE_DISMISSED_KEY)) {
    visible.value = true
  }
}, { immediate: true })

function close() {
  visible.value = false
  // 记录已关闭,本次会话不再弹(刷新后重新弹,避免永久打扰)
  localStorage.setItem(NOTICE_DISMISSED_KEY, '1')
}

/** 手动打开弹窗(顶部公告入口点击时调用) */
function open() {
  if (noticeHtml.value.trim()) {
    visible.value = true
  }
}

// 暴露 open/close 供父组件调用(公告顶部入口)
defineExpose({ open, close })
</script>

<template>
  <!-- 公告弹窗:首次访问弹出,可关闭 -->
  <Teleport to="body">
    <div v-if="visible" class="fixed inset-0 z-[90] flex items-center justify-center p-4 bg-black/50" @click.self="close">
      <div class="bg-white rounded-card shadow-pop max-w-lg w-full overflow-hidden animate-[fadeInUp_.2s_ease]">
        <!-- 标题栏 -->
        <div class="flex items-center justify-between px-5 py-3 border-b border-border bg-surface-subtle">
          <span class="text-sm font-bold text-ink inline-flex items-center gap-1.5"><AppIcon name="ri:megaphone-line" class="w-4 h-4" /> {{ t('notice.title') }}</span>
          <button @click="close" class="w-7 h-7 rounded-full flex items-center justify-center text-ink-muted hover:bg-border hover:text-ink transition text-lg leading-none">
            ×
          </button>
        </div>
        <!-- 内容:渲染后台富文本(已 XSS 过滤) -->
        <div class="px-5 py-4 max-h-[60vh] overflow-y-auto text-sm text-ink leading-relaxed prose notice-content" v-html="sanitizedNotice"></div>
        <!-- 底部按钮 -->
        <div class="flex justify-end px-5 py-3 border-t border-border">
          <button @click="close"
            class="px-4 py-1.5 rounded-field bg-primary text-white text-xs font-medium hover:bg-primary-hover transition shadow-sm">
            {{ t('notice.gotIt') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
/* 公告内容富文本基础样式 */
.notice-content :deep(h1) { font-size: 1.3em; font-weight: 700; margin: 0.6em 0 0.3em; }
.notice-content :deep(h2) { font-size: 1.15em; font-weight: 700; margin: 0.6em 0 0.3em; }
.notice-content :deep(h3) { font-size: 1.05em; font-weight: 600; margin: 0.5em 0 0.3em; }
.notice-content :deep(p) { margin: 0.4em 0; }
.notice-content :deep(ul), .notice-content :deep(ol) { margin: 0.4em 0; padding-left: 1.5em; list-style: revert; }
.notice-content :deep(a) { color: var(--color-primary, #2563eb); text-decoration: underline; }
.notice-content :deep(img) { max-width: 100%; border-radius: 6px; margin: 0.4em 0; }
.notice-content :deep(blockquote) { border-left: 3px solid #e2e8f0; padding-left: 0.8em; color: #64748b; margin: 0.5em 0; }
.notice-content :deep(code) { background: #f1f5f9; padding: 0.15em 0.4em; border-radius: 4px; font-size: 0.9em; }
.notice-content :deep(pre) { background: #0f172a; color: #e2e8f0; padding: 0.8em; border-radius: 6px; overflow-x: auto; }
.notice-content :deep(pre code) { background: transparent; color: inherit; padding: 0; }
.notice-content :deep(table) { border-collapse: collapse; margin: 0.5em 0; }
.notice-content :deep(td), .notice-content :deep(th) { border: 1px solid #e2e8f0; padding: 0.3em 0.6em; }
</style>
