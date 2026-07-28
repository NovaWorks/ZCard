<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { getCategories, type Category } from '@/api/categories'

const props = defineProps<{ modelValue: number | null; style: 'pills' | 'sidebar' | 'combo' }>()
const emit = defineEmits<{ (e: 'update:modelValue', v: number | null): void }>()
const cats = ref<Category[]>([])
onMounted(async () => { cats.value = await getCategories() })

function select(id: number | null) { emit('update:modelValue', id) }
</script>

<template>
  <!-- pills: 顶部横排 -->
  <div v-if="style === 'pills'" class="flex flex-wrap gap-2 p-3 border-b bg-white">
    <button @click="select(null)" :class="['pill', modelValue === null ? 'pill-on' : 'pill-off']">全部</button>
    <button v-for="c in cats" :key="c.id" @click="select(c.id)"
      :class="['pill', modelValue === c.id ? 'pill-on' : 'pill-off']">{{ c.name }}</button>
  </div>

  <!-- sidebar: 左侧树 -->
  <div v-else-if="style === 'sidebar'" class="w-44 bg-surface-subtle p-3 border-r">
    <div :class="['cursor-pointer px-2 py-1.5 rounded text-sm', modelValue === null ? 'bg-primary text-white' : 'text-ink-soft']" @click="select(null)">全部商品</div>
    <template v-for="c in cats" :key="c.id">
      <div :class="['cursor-pointer px-2 py-1.5 rounded text-sm mt-0.5', modelValue === c.id ? 'bg-primary text-white' : 'text-ink-soft']" @click="select(c.id)">{{ c.name }}</div>
      <div v-for="ch in c.children" :key="ch.id" :class="['cursor-pointer pl-6 py-1 rounded text-xs', modelValue === ch.id ? 'text-primary font-semibold' : 'text-ink-muted']" @click="select(ch.id)">— {{ ch.name }}</div>
    </template>
  </div>

  <!-- combo: 简化为顶部+pills,同 pills 行为(完整 combo 留优化) -->
  <div v-else class="flex flex-wrap gap-2 p-3 border-b bg-white">
    <button @click="select(null)" :class="['pill', modelValue === null ? 'pill-on' : 'pill-off']">全部</button>
    <button v-for="c in cats" :key="c.id" @click="select(c.id)" :class="['pill', modelValue === c.id ? 'pill-on' : 'pill-off']">{{ c.name }}</button>
  </div>
</template>

<style scoped>
.pill { padding: 4px 14px; border-radius: 16px; font-size: 12px; }
.pill-off { background: #f3f4f6; color: #374151; }
.pill-on { background: var(--color-primary); color: #fff; }
</style>
