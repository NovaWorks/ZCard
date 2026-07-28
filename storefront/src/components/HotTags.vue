<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { getCategories, type Category } from '@/api/categories'

const props = defineProps<{ ids: number[] }>()
const router = useRouter()
const all = ref<Category[]>([])
onMounted(async () => {
  const tree = await getCategories()
  // 收集所有分类(含子)
  const flat: Category[] = []
  const walk = (list: Category[]) => list.forEach(c => { flat.push(c); c.children && walk(c.children) })
  walk(tree)
  all.value = flat.filter(c => props.ids.includes(c.id))
})
function go() { router.push('/') } // 简化:跳首页(按分类筛留优化)
</script>

<template>
  <div v-if="all.length" class="flex flex-wrap gap-2 py-3">
    <span class="text-[10px] text-ink-muted self-center">热门:</span>
    <span v-for="c in all" :key="c.id" @click="go"
      class="px-3 py-1 bg-primary text-white text-xs rounded-full cursor-pointer">{{ c.name }}</span>
  </div>
</template>
