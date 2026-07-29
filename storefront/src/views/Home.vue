<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useSettingsStore } from '@/stores/settings'
import { useProductsStore } from '@/stores/products'
import { getFeatured, type Product } from '@/api/products'
import CategoryNav from '@/components/CategoryNav.vue'
import ViewSwitcher from '@/components/ViewSwitcher.vue'
import ProductCard from '@/components/ProductCard.vue'
import HotTags from '@/components/HotTags.vue'

const router = useRouter()
const settings = useSettingsStore()
const products = useProductsStore()
const category = ref<number | null>(null)
const order = ref('')
const featured = ref<Product[]>([])

const fmt = (fen: number) => (fen / 100).toFixed(2)

async function load() {
  await settings.load()
  await products.fetch({
    category: category.value ?? undefined,
    order: order.value || undefined,
  })
  if (settings.config?.show_featured && !featured.value.length) {
    try {
      const list = await getFeatured(6)
      featured.value = (list || []).slice(0, 6)
    } catch {
      featured.value = []
    }
  }
}
onMounted(load)
watch(category, load)

function goProduct(p: Product) {
  router.push({ name: 'product', params: { id: p.slug ?? p.id } })
}

const gridClass = (cols: number) => `grid gap-3 p-4 grid-cols-${cols} md:grid-cols-${cols}`
</script>

<template>
  <div>
    <!-- 热门推荐 (Featured Carousel) -->
    <section v-if="settings.config?.show_featured && featured.length"
      class="max-w-6xl mx-auto px-4 mt-3">
      <div class="rounded-card p-4 bg-gradient-to-r from-primary/10 via-primary/5 to-transparent border border-primary/10">
        <h2 class="text-base font-bold text-ink mb-3 flex items-center gap-1">
          <span>🔥</span><span>热门推荐</span>
        </h2>
        <div class="flex gap-3 overflow-x-auto snap-x snap-mandatory pb-1 -mx-1 px-1">
          <div v-for="p in featured" :key="p.id"
            class="snap-start shrink-0 w-36 bg-white rounded-card border border-gray-200 overflow-hidden cursor-pointer hover:shadow-card transition-shadow"
            @click="goProduct(p)">
            <img v-if="p.cover" :src="p.cover" :alt="p.name"
              class="w-full h-24 object-cover bg-gray-100" />
            <div v-else class="w-full h-24 bg-gray-100 flex items-center justify-center text-ink-muted text-xs">无图</div>
            <div class="p-2">
              <div class="text-xs font-medium text-ink line-clamp-1">{{ p.name }}</div>
              <div class="text-primary font-bold text-sm mt-1">¥{{ fmt(p.price) }}</div>
            </div>
          </div>
        </div>
      </div>
    </section>

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
