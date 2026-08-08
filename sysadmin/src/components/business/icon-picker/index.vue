<script setup lang="ts">
/**
 * 图标选择器 - Remix Icon(ri: 前缀,与 storefront 的 AppIcon 命名一致)
 * 点击触发弹出面板,选择图标。配合 ImagePicker 使用可实现"图标或图片"双模式。
 * 兼容旧数据:已保存的 emoji 图标仍以字符形式展示。
 */
const modelValue = defineModel<string>({ default: '' })

/** 常用图标库(分类) */
const iconGroups = [
  {
    label: '常用',
    icons: ['ri:folder-2-line', 'ri:archive-line', 'ri:gift-2-line', 'ri:diamond-line', 'ri:star-fill', 'ri:fire-fill', 'ri:sparkling-line', 'ri:magic-line', 'ri:target-line', 'ri:trophy-line', 'ri:bank-card-line', 'ri:money-cny-circle-line', 'ri:shopping-cart-2-line', 'ri:price-tag-3-line', 'ri:notification-3-line', 'ri:checkbox-circle-line']
  },
  {
    label: '购物',
    icons: ['ri:shopping-bag-3-line', 'ri:store-3-line', 'ri:store-2-line', 'ri:shopping-cart-2-line', 'ri:bank-card-line', 'ri:money-dollar-circle-line', 'ri:money-euro-circle-line', 'ri:money-pound-circle-line', 'ri:money-cny-circle-line', 'ri:coins-line', 'ri:bank-line', 'ri:line-chart-line', 'ri:bar-chart-2-line', 'ri:bookmark-3-line', 'ri:gift-2-line', 'ri:award-line']
  },
  {
    label: '数字',
    icons: ['ri:gamepad-line', 'ri:save-3-line', 'ri:disc-line', 'ri:smartphone-line', 'ri:phone-line', 'ri:radar-line', 'ri:tv-line', 'ri:headphone-line', 'ri:mic-line', 'ri:camera-line', 'ri:computer-line', 'ri:keyboard-line', 'ri:mouse-line', 'ri:hard-drive-line', 'ri:database-2-line', 'ri:server-line']
  },
  {
    label: '社交',
    icons: ['ri:chat-smile-2-line', 'ri:mail-line', 'ri:mail-send-line', 'ri:send-plane-line', 'ri:rocket-line', 'ri:heart-3-line', 'ri:megaphone-line', 'ri:megaphone-2-line', 'ri:message-3-line', 'ri:wechat-line', 'ri:qq-line', 'ri:telegram-line', 'ri:discord-line', 'ri:twitter-x-line', 'ri:youtube-line', 'ri:github-line']
  },
  {
    label: '其他',
    icons: ['ri:lock-2-line', 'ri:lock-unlock-line', 'ri:key-2-line', 'ri:shield-check-line', 'ri:settings-3-line', 'ri:tools-line', 'ri:hammer-line', 'ri:settings-4-line', 'ri:rainbow-line', 'ri:sun-line', 'ri:moon-line', 'ri:flashlight-line', 'ri:drop-line', 'ri:leaf-line', 'ri:home-4-line', 'ri:user-3-line']
  },
]

const visible = ref(false)
const activeGroup = ref(0)

const selectIcon = (icon: string) => {
  modelValue.value = icon
  visible.value = false
}

const clearIcon = () => {
  modelValue.value = ''
  visible.value = false
}

/** 判断值是否是图片URL(而非图标名/emoji) */
const isImageUrl = (v: string) => /^https?:\/\/|^\/storage\//.test(v)
/** 判断值是否是 Iconify 图标名(ri: 前缀) */
const isIconify = (v: string) => !!v && v.startsWith('ri:')
</script>

<template>
  <ElPopover v-model:visible="visible" placement="bottom-start" :width="360" trigger="click">
    <template #reference>
      <!-- 注意:ElPopover trigger="click" 自身管理显隐,这里不能再 @click 手动置 true,
           否则与 trigger 的切换逻辑冲突(点一下立即关闭,无法选择图标)。 -->
      <div class="icon-trigger">
        <img v-if="isImageUrl(modelValue)" :src="modelValue" class="icon-preview-img" />
        <ArtSvgIcon v-else-if="isIconify(modelValue)" :icon="modelValue" class="icon-preview" />
        <span v-else-if="modelValue" class="icon-preview">{{ modelValue }}</span>
        <span v-else class="icon-placeholder">😀</span>
      </div>
    </template>

    <div class="icon-picker-panel">
      <!-- 分类标签 -->
      <div class="icon-tabs">
        <span
          v-for="(group, idx) in iconGroups"
          :key="idx"
          class="icon-tab"
          :class="{ active: activeGroup === idx }"
          @click="activeGroup = idx"
        >
          {{ group.label }}
        </span>
      </div>

      <!-- 图标网格 -->
      <div class="icon-grid">
        <span
          v-for="icon in iconGroups[activeGroup].icons"
          :key="icon"
          class="icon-item"
          :class="{ selected: modelValue === icon }"
          @click="selectIcon(icon)"
        >
          <ArtSvgIcon :icon="icon" />
        </span>
      </div>

      <!-- 清除按钮 -->
      <div class="icon-footer">
        <ElButton text size="small" @click="clearIcon">清除</ElButton>
      </div>
    </div>
  </ElPopover>
</template>

<style lang="scss" scoped>
.icon-trigger {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border: 1px solid var(--el-border-color);
  border-radius: 6px;
  cursor: pointer;
  flex-shrink: 0;
  overflow: hidden;
  transition: all 0.2s;
  &:hover {
    border-color: var(--el-color-primary);
  }
}
.icon-preview-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.icon-preview {
  font-size: 20px;
}
.icon-placeholder {
  font-size: 18px;
  opacity: 0.4;
}
.icon-picker-panel {
  .icon-tabs {
    display: flex;
    gap: 4px;
    margin-bottom: 10px;
    border-bottom: 1px solid var(--el-border-color-lighter);
    padding-bottom: 6px;
  }
  .icon-tab {
    padding: 2px 8px;
    font-size: 12px;
    border-radius: 4px;
    cursor: pointer;
    color: var(--el-text-color-secondary);
    &.active {
      background: var(--el-color-primary-light-9);
      color: var(--el-color-primary);
      font-weight: 500;
    }
  }
  .icon-grid {
    display: grid;
    grid-template-columns: repeat(8, 1fr);
    gap: 2px;
    max-height: 180px;
    overflow-y: auto;
  }
  .icon-item {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 32px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 18px;
    transition: all 0.15s;
    &:hover {
      background: var(--el-fill-color);
    }
    &.selected {
      background: var(--el-color-primary-light-8);
      outline: 2px solid var(--el-color-primary);
    }
  }
  .icon-footer {
    margin-top: 8px;
    text-align: right;
  }
}
</style>
