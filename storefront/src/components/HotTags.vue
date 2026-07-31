<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { getCategories, type Category } from '@/api/categories'

const props = defineProps<{ ids: number[] }>()
const router = useRouter()
const { t } = useI18n()
const all = ref<Category[]>([])
onMounted(async () => {
  const tree = await getCategories()
  // 收集所有分类(含子)
  const flat: Category[] = []
  const walk = (list: Category[]) => list.forEach(c => { flat.push(c); c.children && walk(c.children) })
  walk(tree)
  all.value = flat.filter(c => props.ids.includes(c.id))
})
function go() { router.push('/') }
</script>

<template>
  <div v-if="all.length" class="flex flex-wrap items-center gap-2 py-3">
    <span class="text-[10px] text-ink-muted font-medium">{{ t('tag.hot') }}</span>
    <span v-for="c in all" :key="c.id" @click="go"
      class="px-3 py-1 bg-white border border-border text-ink-soft text-xs rounded-pill cursor-pointer hover:border-primary hover:text-primary hover:bg-primary-light transition">{{ c.name }}</span>
  </div>
</template>
