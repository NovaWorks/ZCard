<script setup lang="ts">
  /**
   * 素材管理页面(管理端入口)。
   * 完整能力: 分类管理 + 图片上传/搜索/排序/批量操作/预览/删除。
   */
  import MediaManager from '@/components/business/media-manager/index.vue'

  defineOptions({ name: 'MediaIndex' })
</script>

<template>
  <div class="media-page">
    <MediaManager />
  </div>
</template>

<style lang="scss" scoped>
  .media-page {
    height: 100%;
  }
</style>
