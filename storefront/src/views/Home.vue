<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useSettingsStore } from '@/stores/settings'
import { useProductsStore } from '@/stores/products'
import CategoryNav from '@/components/CategoryNav.vue'
import ViewSwitcher from '@/components/ViewSwitcher.vue'
import ProductCard from '@/components/ProductCard.vue'
import HotTags from '@/components/HotTags.vue'

const settings = useSettingsStore()
const products = useProductsStore()
const category = ref<number | null>(null)
const order = ref('')

async function load() {
  await settings.load()
  await products.fetch({
    category: category.value ?? undefined,
    order: order.value || undefined,
  })
}
onMounted(load)
watch(category, load)

const gridClass = (cols: number) => `grid gap-3 p-4 grid-cols-${cols} md:grid-cols-${cols}`
</script>

<template>
  <div>
    <!-- 热门标签 -->
    <div class="max-w-6xl mx-auto px-4">
      <HotTags v-if="settings.config?.show_hot_tags" :ids="settings.config?.hot_tag_categories || []" />
    </div>

    <div class="flex max-w-6xl mx-auto">
      <!-- 分类导航 + 列表 -->
      <CategoryNav v-if="settings.config" v-model="category" :style="settings.config.category_nav_style" />
      <div class="flex-1">
        <div class="flex justify-between items-center p-3">
          <span class="text-sm text-ink-soft">全部商品</span>
          <ViewSwitcher />
        </div>
        <div v-if="settings.config?.list_default_view && settings.effectiveView !== 'list'"
          :class="gridClass(settings.config?.grid_columns || 4)">
          <ProductCard v-for="p in products.list" :key="p.id" :product="p" />
        </div>
        <div v-else class="max-w-3xl mx-auto px-4">
          <ProductCard v-for="p in products.list" :key="p.id" :product="p" />
        </div>
        <div v-if="!products.loading && !products.list.length" class="text-center text-ink-muted py-20">
          暂无商品(请先在后台添加商品)
        </div>
      </div>
    </div>
  </div>
</template>
