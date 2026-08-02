<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSettingsStore } from '@/stores/settings'
import { useProductsStore } from '@/stores/products'
import { getFeatured, type Product } from '@/api/products'
import { formatMoney } from '@/utils/money'
import { usePreferencesStore } from '@/stores/preferences'
import CategoryNav from '@/components/CategoryNav.vue'
import ViewSwitcher from '@/components/ViewSwitcher.vue'
import ProductCard from '@/components/ProductCard.vue'
import HotTags from '@/components/HotTags.vue'

const router = useRouter()
const { t } = useI18n()

/** 网格列数(配置驱动,默认 4 列;手机强制 2 列,safelist 在 main.css) */
const gridClass = (cols: number) => `grid gap-3 grid-cols-2 md:grid-cols-${cols}`
const dualClass = 'grid gap-3 grid-cols-1 md:grid-cols-2'
const settings = useSettingsStore()
const products = useProductsStore()
const prefs = usePreferencesStore()
const category = ref<number | null>(null)
const order = ref('')
const featured = ref<Product[]>([])
const keyword = ref('')
const keywordInput = ref('')

async function load() {
  await settings.load()
  await products.fetch({
    category: category.value ?? undefined,
    order: order.value || undefined,
    keyword: keyword.value || undefined,
  })
  if (settings.config?.show_featured && !featured.value.length) {
    try {
      const list = await getFeatured(settings.config?.featured_count || 8)
      featured.value = (list || []).slice(0, settings.config?.featured_count || 8)
    } catch {
      featured.value = []
    }
  }
}
onMounted(load)
watch(category, load)

function doSearch() {
  keyword.value = keywordInput.value.trim()
  load()
}

function clearSearch() {
  keywordInput.value = ''
  keyword.value = ''
  load()
}

function goProduct(p: Product) {
  router.push({ name: 'product', params: { id: p.slug ?? p.id } })
}
</script>

<template>
  <div>
    <!-- Hero Banner (品牌横幅) -->
    <section class="bg-gradient-to-br from-primary-hover via-primary to-blue-500 text-white">
      <div class="max-w-6xl mx-auto px-4 py-10 flex items-center justify-between gap-6">
        <div class="flex-1">
          <h1 class="text-3xl font-extrabold tracking-tight">{{ t('product.home.heroTitle') }}</h1>
          <p class="mt-2 text-white/80 text-sm">{{ t('product.home.heroSubtitle') }}</p>
          <div class="mt-4 flex gap-4 text-xs text-white/90">
            <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 bg-green-300 rounded-full"></span> {{ t('product.home.heroInstant') }}</span>
            <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 bg-green-300 rounded-full"></span> {{ t('product.home.heroGenuine') }}</span>
            <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 bg-green-300 rounded-full"></span> {{ t('product.home.heroAfterSales') }}</span>
          </div>
        </div>
        <div class="hidden md:block text-7xl opacity-30">🛒</div>
      </div>
    </section>

    <!-- 热门推荐 (Featured Carousel) -->
    <section v-if="settings.config?.show_featured && featured.length" class="max-w-6xl mx-auto px-4 -mt-6 relative z-10">
      <div class="bg-white rounded-card p-4 shadow-card border border-border">
        <h2 class="text-base font-bold text-ink mb-3 flex items-center gap-2">
          <span class="w-1 h-4 bg-price rounded-full"></span>
          <span>{{ t('product.home.featuredTitle') }}</span>
        </h2>
        <div class="flex gap-3 overflow-x-auto snap-x snap-mandatory pb-1 -mx-1 px-1">
          <div v-for="p in featured" :key="p.id"
            class="snap-start shrink-0 w-40 bg-white rounded-[10px] border border-border overflow-hidden cursor-pointer hover:border-primary hover:shadow-card-hover transition"
            @click="goProduct(p)">
            <img v-if="p.cover" :src="p.cover" :alt="p.name"
              class="w-full h-28 object-cover bg-surface-subtle" />
            <div v-else class="w-full h-28 bg-gradient-to-br from-primary-soft to-primary-light flex items-center justify-center text-primary text-xs font-medium">{{ t('common.noImage') }}</div>
            <div class="p-2.5">
              <div class="text-xs font-medium text-ink line-clamp-1">{{ p.name }}</div>
              <div class="text-price font-bold text-base mt-1">{{ formatMoney(p.price_display ?? p.price, prefs.currentCurrency) }}</div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- 热门标签 -->
    <div class="max-w-6xl mx-auto px-4 mt-3">
      <HotTags v-if="settings.config?.show_hot_tags" :ids="settings.config?.hot_tag_categories || []" />
    </div>

    <div class="flex max-w-6xl mx-auto mt-2">
      <!-- 分类导航 + 列表 -->
      <CategoryNav v-if="settings.config" v-model="category" :style="settings.config.category_nav_style" />
      <div class="flex-1 min-w-0">
        <!-- 搜索框 -->
        <div class="px-4 pt-3">
          <div class="flex gap-2">
            <input
              v-model="keywordInput"
              type="text"
              :placeholder="t('common.searchPlaceholder')"
              class="flex-1 px-3 py-2 rounded-field border border-border bg-white text-sm text-ink placeholder:text-ink-muted focus:outline-none focus:border-primary focus:ring-1 focus:ring-primary transition"
              @keydown.enter="doSearch"
            />
            <button
              type="button"
              class="px-4 py-2 rounded-field bg-primary text-white text-sm font-medium hover:bg-primary-hover transition shadow-sm"
              @click="doSearch"
            >{{ t('common.search') }}</button>
            <button
              v-if="keyword"
              type="button"
              class="px-3 py-2 rounded-field border border-border text-ink-soft text-sm hover:text-danger hover:border-danger transition"
              @click="clearSearch"
            >×</button>
          </div>
        </div>
        <div class="flex justify-between items-center px-4 py-3">
          <span class="text-sm font-semibold text-ink">
            {{ keyword ? `${t('common.search')}: "${keyword}"` : t('product.home.allProducts') }}
          </span>
          <ViewSwitcher />
        </div>
        <div class="px-4 pb-6">
          <!-- 网格视图 -->
          <div v-if="settings.effectiveView === 'grid'" :class="gridClass(settings.config?.grid_columns || 4)">
            <ProductCard v-for="p in products.list" :key="p.id" :product="p" />
          </div>
          <!-- 双栏视图 -->
          <div v-else-if="settings.effectiveView === 'dual'" :class="dualClass">
            <ProductCard v-for="p in products.list" :key="p.id" :product="p" />
          </div>
          <!-- 列表视图 -->
          <div v-else class="max-w-3xl mx-auto">
            <ProductCard v-for="p in products.list" :key="p.id" :product="p" />
          </div>
        </div>
        <div v-if="!products.loading && !products.list.length" class="text-center text-ink-muted py-20">
          <div class="text-5xl mb-3 opacity-40">📦</div>
          <div>{{ t('product.home.noProducts') }}</div>
        </div>
      </div>
    </div>
  </div>
</template>
