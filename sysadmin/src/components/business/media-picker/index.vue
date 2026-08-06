<script setup lang="ts">
  /**
   * 素材选择器弹窗 - 统一素材管理入口。
   * 所有涉及图片上传/选择的业务模块都应通过本组件打开素材库:
   *   1. 选择已有图片(单选/多选)
   *   2. 上传新图片(上传后自动加入当前分类)
   *
   * 用法(单选):
   *   <MediaPicker v-model="logoUrl" />            → 选中后回填 url 字符串
   * 用法(多选):
   *   <MediaPicker v-model="bannerUrls" multiple /> → 选中后回填 url 字符串数组
   * 用法(纯按钮,自定义回填):
   *   <MediaPicker @confirm="(items) => ..." />     → 通过 confirm 事件拿 {url,id}[]
   */
  import { ref, computed } from 'vue'
  import MediaManager from '@/components/business/media-manager/index.vue'
  import { useI18n } from 'vue-i18n'

  const props = withDefaults(
    defineProps<{
      /** 是否多选(默认单选,回填 string) */
      multiple?: boolean
      /** 触发按钮文案 */
      buttonText?: string
      /** 弹窗标题 */
      title?: string
      /** 按钮类型 */
      type?: 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'default'
      /** 按钮尺寸 */
      size?: 'large' | 'default' | 'small'
      /** 是否禁用 */
      disabled?: boolean
      /** 是否显示当前已选缩略图 */
      showPreview?: boolean
    }>(),
    {
      multiple: false,
      buttonText: '',
      title: '',
      type: 'primary',
      size: 'default',
      disabled: false,
      showPreview: true
    }
  )

  const emit = defineEmits<{
    (e: 'update:modelValue', value: string | string[]): void
    (e: 'confirm', value: { url: string; id: number }[]): void
  }>()

  const modelValue = defineModel<string | string[]>({ default: () => '' })

  const { t } = useI18n()

  const dialogVisible = ref(false)

  /** 当前选中预览图 */
  const previewUrls = computed(() => {
    if (Array.isArray(modelValue.value)) return modelValue.value
    return modelValue.value ? [modelValue.value] : []
  })

  const open = () => {
    dialogVisible.value = true
  }

  defineExpose({ open })

  const handleConfirm = (items: { url: string; id: number }[]) => {
    dialogVisible.value = false
    if (props.multiple) {
      modelValue.value = items.map((i) => i.url)
    } else {
      modelValue.value = items[0]?.url ?? ''
    }
    emit('confirm', items)
  }
</script>

<template>
  <span class="media-picker">
    <div class="picker-row">
      <!-- 已选预览(单选/缩略) -->
      <div v-if="showPreview && previewUrls.length" class="preview-stack">
        <img
          v-for="(url, idx) in previewUrls.slice(0, multiple ? 4 : 1)"
          :key="idx"
          :src="url"
          class="preview-thumb"
          :class="{ 'is-single': !multiple }"
          @click="open"
        />
        <span v-if="multiple && previewUrls.length > 4" class="preview-more"
          >+{{ previewUrls.length - 4 }}</span
        >
      </div>

      <ElButton :type="type" :size="size" :disabled="disabled" @click="open">
        {{ buttonText || t('zcard.media.selectImage') }}
      </ElButton>
    </div>

    <ElDialog
      v-model="dialogVisible"
      :title="title || t('zcard.media.managerTitle')"
      width="960px"
      top="4vh"
      destroy-on-close
      class="media-picker-dialog"
    >
      <div class="dialog-body">
        <MediaManager
          :selection-mode="multiple ? 'multiple' : 'single'"
          @confirm="handleConfirm"
          @cancel="dialogVisible = false"
        />
      </div>
    </ElDialog>
  </span>
</template>

<script lang="ts">
  export default {
    name: 'MediaPicker'
  }
</script>

<style lang="scss" scoped>
  .media-picker {
    display: inline-flex;
  }
  .picker-row {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .preview-stack {
    display: flex;
    align-items: center;
    gap: 4px;
  }
  .preview-thumb {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    object-fit: cover;
    border: 1px solid var(--el-border-color);
    cursor: pointer;
    &.is-single {
      width: 48px;
      height: 48px;
    }
  }
  .preview-more {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }
  .dialog-body {
    height: 62vh;
    min-height: 420px;
    overflow: hidden;
  }
</style>
