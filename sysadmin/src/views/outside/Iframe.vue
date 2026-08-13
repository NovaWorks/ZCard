<template>
  <div class="box-border w-full h-full" v-loading="isLoading">
    <iframe
      v-if="iframeUrl"
      ref="iframeRef"
      :src="iframeUrl"
      frameborder="0"
      class="w-full h-full min-h-[calc(100vh-120px)] border-none"
      sandbox="allow-forms allow-modals allow-popups allow-scripts allow-same-origin allow-downloads"
      referrerpolicy="no-referrer"
      @load="handleIframeLoad"
    ></iframe>
  </div>
</template>

<script setup lang="ts">
  import { IframeRouteManager } from '@/router/core'

  defineOptions({ name: 'IframeView' })

  const route = useRoute()
  const isLoading = ref(true)
  const iframeUrl = ref('')
  const iframeRef = ref<HTMLIFrameElement | null>(null)

  /**
   * 初始化 iframe URL
   * 从路由配置中获取对应的外部链接地址
   * 安全:仅允许 https(阻断 javascript:/data:/http: 等协议注入面)。
   */
  onMounted(() => {
    const iframeRoute = IframeRouteManager.getInstance().findByPath(route.path)

    if (iframeRoute?.meta) {
      const link = iframeRoute.meta.link || ''
      iframeUrl.value = typeof link === 'string' && link.toLowerCase().startsWith('https://') ? link : ''
    }
  })

  /**
   * 处理 iframe 加载完成事件
   * 隐藏加载状态
   */
  const handleIframeLoad = (): void => {
    isLoading.value = false
  }
</script>
