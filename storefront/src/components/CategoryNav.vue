<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { getCategories, type Category } from '@/api/categories'

const props = defineProps<{ modelValue: number | null; style: 'pills' | 'sidebar' | 'combo' }>()
const emit = defineEmits<{ (e: 'update:modelValue', v: number | null): void }>()
const { t } = useI18n()
const cats = ref<Category[]>([])
const expanded = ref<Set<number>>(new Set())

onMounted(async () => {
  cats.value = await getCategories()
  // 默认展开第一级
  cats.value.forEach(c => expanded.value.add(c.id))
})

function select(id: number | null) { emit('update:modelValue', id) }
function toggle(id: number) {
  if (expanded.value.has(id)) expanded.value.delete(id)
  else expanded.value.add(id)
}
function hasChildren(c: Category) { return c.children && c.children.length > 0 }
</script>

<template>
  <!-- pills: 顶部横排(大厂风格:横向滚动 + 胶囊) -->
  <div v-if="style === 'pills'" class="bg-white border-b border-border">
    <div class="max-w-6xl mx-auto px-4">
      <div class="flex items-center gap-2 py-3 overflow-x-auto scrollbar-hide">
        <button @click="select(null)" :class="[
          'shrink-0 px-4 py-1.5 rounded-pill text-sm font-medium transition-all whitespace-nowrap',
          modelValue === null
            ? 'bg-primary text-white shadow-sm'
            : 'bg-surface-subtle text-ink-soft hover:bg-primary-light hover:text-primary'
        ]">{{ t('category.all') }}</button>
        <button v-for="c in cats" :key="c.id" @click="select(c.id)" :class="[
          'shrink-0 px-4 py-1.5 rounded-pill text-sm font-medium transition-all whitespace-nowrap',
          modelValue === c.id
            ? 'bg-primary text-white shadow-sm'
            : 'bg-surface-subtle text-ink-soft hover:bg-primary-light hover:text-primary'
        ]">
          <span v-if="c.icon" class="mr-1">{{ c.icon }}</span>{{ c.name }}
        </button>
      </div>
    </div>
  </div>

  <!-- sidebar: 左侧树(大厂风格:卡片化 + 图标 + 可折叠子分类) -->
  <aside v-else-if="style === 'sidebar'" class="w-52 shrink-0">
    <div class="bg-white rounded-card border border-border overflow-hidden sticky top-4">
      <!-- 标题 -->
      <div class="flex items-center gap-2 px-4 py-3 border-b border-border bg-surface-subtle">
        <span class="text-sm">📂</span>
        <span class="text-sm font-bold text-ink">{{ t('category.title') }}</span>
      </div>
      <!-- 全部 -->
      <div class="p-2">
        <button @click="select(null)" :class="[
          'w-full flex items-center gap-2 px-3 py-2 rounded-field text-sm font-medium transition',
          modelValue === null
            ? 'bg-primary text-white shadow-sm'
            : 'text-ink-soft hover:bg-primary-light hover:text-primary'
        ]">
          <span class="text-xs">🏠</span>{{ t('category.allProducts') }}
        </button>
      </div>
      <!-- 分类列表 -->
      <div class="px-2 pb-2 space-y-0.5 max-h-[calc(100vh-200px)] overflow-y-auto">
        <template v-for="c in cats" :key="c.id">
          <!-- 一级分类 -->
          <button @click="select(c.id)" :class="[
            'w-full flex items-center gap-2 px-3 py-2 rounded-field text-sm transition group',
            modelValue === c.id
              ? 'bg-primary text-white font-medium shadow-sm'
              : 'text-ink-soft hover:bg-primary-light hover:text-primary'
          ]">
            <span v-if="c.icon" class="text-xs">{{ c.icon }}</span>
            <span v-else class="text-xs opacity-50">📄</span>
            <span class="flex-1 text-left truncate">{{ c.name }}</span>
            <!-- 子分类展开按钮 -->
            <span v-if="hasChildren(c)" @click.stop="toggle(c.id)" class="text-[10px] opacity-60 group-hover:opacity-100 transition">
              {{ expanded.has(c.id) ? '▼' : '▶' }}
            </span>
          </button>
          <!-- 子分类 -->
          <div v-if="hasChildren(c) && expanded.has(c.id)" class="ml-3 pl-2 border-l border-border space-y-0.5 mt-0.5 mb-1">
            <button v-for="ch in c.children" :key="ch.id" @click="select(ch.id)" :class="[
              'w-full flex items-center gap-1.5 px-2.5 py-1.5 rounded-field text-xs transition',
              modelValue === ch.id
                ? 'text-primary font-semibold bg-primary-light'
                : 'text-ink-muted hover:text-primary hover:bg-primary-light/50'
            ]">
              <span class="w-1 h-1 rounded-full" :class="modelValue === ch.id ? 'bg-primary' : 'bg-ink-muted/40'"></span>
              <span class="truncate">{{ ch.name }}</span>
            </button>
          </div>
        </template>
      </div>
    </div>
  </aside>

  <!-- combo: 混合模式(顶部横排 + 可展开下拉子分类) -->
  <div v-else class="bg-white border-b border-border">
    <div class="max-w-6xl mx-auto px-4">
      <div class="flex items-center gap-2 py-3 overflow-x-auto scrollbar-hide">
        <button @click="select(null)" :class="[
          'shrink-0 px-4 py-1.5 rounded-pill text-sm font-medium transition-all whitespace-nowrap',
          modelValue === null
            ? 'bg-primary text-white shadow-sm'
            : 'bg-surface-subtle text-ink-soft hover:bg-primary-light hover:text-primary'
        ]">{{ t('category.all') }}</button>
        <div v-for="c in cats" :key="c.id" class="shrink-0 relative group">
          <button @click="select(c.id)" :class="[
            'px-4 py-1.5 rounded-pill text-sm font-medium transition-all whitespace-nowrap flex items-center gap-1',
            modelValue === c.id
              ? 'bg-primary text-white shadow-sm'
              : 'bg-surface-subtle text-ink-soft hover:bg-primary-light hover:text-primary'
          ]">
            <span v-if="c.icon" class="mr-0.5">{{ c.icon }}</span>{{ c.name }}
            <span v-if="hasChildren(c)" class="text-[8px] opacity-60">▼</span>
          </button>
          <!-- 下拉子分类 -->
          <div v-if="hasChildren(c)" class="absolute top-full left-0 mt-1 bg-white border border-border rounded-card shadow-pop py-1 min-w-[140px] opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-20">
            <button v-for="ch in c.children" :key="ch.id" @click="select(ch.id)" :class="[
              'w-full text-left px-3 py-1.5 text-xs transition',
              modelValue === ch.id ? 'text-primary font-semibold bg-primary-light' : 'text-ink-soft hover:bg-primary-light hover:text-primary'
            ]">{{ ch.name }}</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.scrollbar-hide {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
.scrollbar-hide::-webkit-scrollbar {
  display: none;
}
</style>
