<script setup lang="ts">
  /**
   * 图片选择器 - 上传到服务器 或 输入 URL,带鼠标悬停预览。
   * 已接入素材管理:点击「选择图片」打开素材库弹窗选择,上传仍走 /api/admin/upload/image。
   */
  import { uploadImage } from '@/api/upload'
  import { ElMessage } from 'element-plus'
  import MediaPicker from '@/components/business/media-picker/index.vue'
  import { useI18n } from 'vue-i18n'

  const { t } = useI18n()

  const modelValue = defineModel<string>({ default: '' })
  const uploading = ref(false)

  /** 上传图片 */
  const handleUpload = async (file: File) => {
    uploading.value = true
    try {
      const res = await uploadImage(file)
      modelValue.value = res.url
      ElMessage.success('上传成功')
    } catch {
      ElMessage.error('上传失败')
    } finally {
      uploading.value = false
    }
    return false // 阻止 ElUpload 默认行为
  }

  /** 素材库选中回填 */
  const handleMediaConfirm = (items: { url: string; id: number }[]) => {
    if (items[0]) modelValue.value = items[0].url
  }

  /** 判断值是否是图片URL(而非 emoji) */
  const isImageUrl = (v: string) => /^https?:\/\/|^\/storage\//.test(v)
</script>

<template>
  <div class="image-picker-wrap">
    <!-- URL 输入框 -->
    <ElInput
      v-model="modelValue"
      :placeholder="$t('zcard.common.imageUrl') || '图片URL'"
      clearable
      size="default"
      class="url-input"
    />

    <!-- 悬停预览 + 上传按钮 -->
    <ElTooltip v-if="modelValue && isImageUrl(modelValue)" placement="top">
      <template #content>
        <img :src="modelValue" class="preview-img-tip" />
      </template>
      <div class="preview-thumb">
        <img :src="modelValue" />
      </div>
    </ElTooltip>

    <!-- 上传按钮 -->
    <ElUpload :show-file-list="false" :before-upload="handleUpload" accept="image/*">
      <ElButton :loading="uploading" size="default">
        {{ uploading ? '...' : '📷' }}
      </ElButton>
    </ElUpload>

    <!-- 素材库选择按钮 -->
    <MediaPicker
      v-model="modelValue"
      :button-text="t('zcard.media.selectImage') || '选择图片'"
      size="default"
      @confirm="handleMediaConfirm"
    />
  </div>
</template>

<style lang="scss" scoped>
  .image-picker-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    .url-input {
      flex: 1;
    }
  }
  .preview-thumb {
    width: 36px;
    height: 36px;
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid var(--el-border-color);
    cursor: pointer;
    flex-shrink: 0;
    img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
  }
  .preview-img-tip {
    max-width: 200px;
    max-height: 200px;
    border-radius: 4px;
  }
</style>
