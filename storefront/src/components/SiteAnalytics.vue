<script setup lang="ts">
/**
 * 站点统计代码注入(issue #39)
 *
 * v1.12.55 起统计代码静默失效:后台仍可保存,但公开配置不下发、前台注入逻辑被删除,
 * v1.12.90 的严格 CSP 又形成第二层阻断。此处与客服组件同一套机制修复:
 * - 后台脚本经受信域名白名单编译后,由同源端点 /api/settings/analytics-script 下发,
 *   既兼容 GA4/百度统计的官方完整安装代码,也不需要放宽 CSP 的内联限制;
 * - SPA 路由切换补发 page_view / _trackPageview —— 单页应用只有首屏会触发一次
 *   官方脚本的自动上报,不补发就只能统计到首页。
 * 本组件不渲染任何 UI。
 */
import { watchEffect, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useSettingsStore } from '@/stores/settings'

const settings = useSettingsStore()
const router = useRouter()

/** 后端只下发「是否有可执行的统计脚本」,原始脚本一律不进公开配置 */
const scriptReady = () => {
  const analytics = settings.config?.analytics
  return !!analytics?.enabled && !!analytics?.script_configured
}

let injected = false
let stopRouterHook: (() => void) | null = null

const stop = watchEffect(() => {
  if (!scriptReady() || injected) return
  if (document.querySelector('script[data-zcard-analytics]')) {
    injected = true
    return
  }
  injected = true

  const el = document.createElement('script')
  // 无扩展名端点:部分 nginx 会把 .js 后缀的 API 路径当静态文件处理而 404。
  el.src = '/api/settings/analytics-script'
  el.async = true
  el.dataset.zcardAnalytics = 'true'
  document.head.appendChild(el)

  // 首屏由统计脚本自身上报,这里只负责后续路由切换。
  stopRouterHook = router.afterEach((to) => {
    const path = to.fullPath
    const w = window as any
    if (typeof w.gtag === 'function') {
      w.gtag('event', 'page_view', { page_path: path, page_location: window.location.href })
    }
    if (Array.isArray(w._hmt)) {
      w._hmt.push(['_trackPageview', path])
    }
  })
})

onUnmounted(() => {
  stop()
  stopRouterHook?.()
})
</script>

<template><span style="display:none" /></template>
