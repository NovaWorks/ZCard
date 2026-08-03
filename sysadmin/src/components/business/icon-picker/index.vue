<script setup lang="ts">
/**
 * 图标选择器 - emoji 库
 * 点击触发弹出面板,选择 emoji 图标。配合 ImagePicker 使用可实现"图标或图片"双模式。
 */
const modelValue = defineModel<string>({ default: '' })

/** 常用图标库(分类) */
const iconGroups = [
  {
    label: '常用',
    icons: ['📁', '📦', '🎁', '💎', '⭐', '🔥', '✨', '💫', '🎯', '🏆', '💳', '💰', '🛒', '🏷️', '🔔', '✅']
  },
  {
    label: '购物',
    icons: ['🛍️', '🏪', '🏬', '🛒', '💳', '💵', '💶', '💷', '💴', '💰', '🏦', '📈', '📊', '🔖', '🎁', '🎗️']
  },
  {
    label: '数字',
    icons: ['🎮', '🕹️', '💾', '💿', '📀', '📱', '☎️', '📞', '📡', '📺', '🎧', '🎤', '📷', '🖥️', '⌨️', '🖱️']
  },
  {
    label: '社交',
    icons: ['💬', '✉️', '📧', '📨', '📩', '📤', '📥', '✈️', '🚀', '💌', '📮', '📯', '📢', '📣', '💞', '💖']
  },
  {
    label: '其他',
    icons: ['🔐', '🔒', '🔓', '🗝️', '🛡️', '⚙️', '🔧', '🔨', '🧰', ' magician', '🌈', '☀️', '🌙', '⚡', '💧', '🍃']
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

/** 判断值是否是图片URL(而非 emoji) */
const isImageUrl = (v: string) => /^https?:\/\/|^\/storage\//.test(v)
</script>

<template>
  <ElPopover v-model:visible="visible" placement="bottom-start" :width="320" trigger="click">
    <template #reference>
      <div class="icon-trigger" @click="visible = true">
        <img v-if="isImageUrl(modelValue)" :src="modelValue" class="icon-preview-img" />
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
          {{ icon }}
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
