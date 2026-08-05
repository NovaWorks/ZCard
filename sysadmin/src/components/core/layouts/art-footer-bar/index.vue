<!-- 管理后台底部栏:GitHub 仓库 + 群组入口(新窗口打开) -->
<template>
  <footer class="app-footer">
    <div class="footer-inner">
      <span class="footer-brand">ZCard Admin</span>
      <div class="footer-links">
        <a :href="GITHUB_URL" target="_blank" rel="noopener">GitHub</a>
        <a :href="GROUP_URL" target="_blank" rel="noopener">{{ t('zcard.common.telegramGroup') }}</a>
      </div>
    </div>
  </footer>
</template>

<script setup lang="ts">
  import { useI18n } from 'vue-i18n'

  defineOptions({ name: 'ArtFooterBar' })

  const { t } = useI18n()

  const GITHUB_URL = 'https://github.com/NovaWorks/ZCard'
  const GROUP_URL = 'http://t.me/ZhonCard'
</script>

<style lang="scss" scoped>
  .app-footer {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 100;
    width: 100%;
    border-top: 1px solid var(--el-border-color-lighter);
    background: var(--default-bg-color);
  }
  .footer-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 20px;
  }
  .footer-brand {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }
  .footer-links {
    display: flex;
    align-items: center;
    gap: 16px;
  }
  .footer-links a {
    font-size: 12px;
    color: var(--el-text-color-secondary);
    transition: color 0.2s;
  }
  .footer-links a:hover {
    color: var(--el-color-primary);
  }
</style>
