<script setup lang="ts">
import { ref, watch, onMounted, computed } from 'vue'
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
import { getCategories, type Category } from '@/api/categories'
import AppIcon from '@/components/AppIcon.vue'

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
/** 移动端分类横向条(sidebar 模式在移动端隐藏,这里兜底) */
const topCats = ref<Category[]>([])
/** 移动端已展开子分类的父分类 id(点击展开/收起) */
const mobileExpandedCat = ref<number | null>(null)

/** 是否还有下一页可加载 */
const hasMore = computed(() => products.page < products.lastPage)

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
onMounted(async () => {
  load()
  try {
    topCats.value = await getCategories()
  } catch {
    topCats.value = []
  }
})
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
        <div class="hidden md:block text-7xl opacity-30"><AppIcon name="ri:shopping-cart-2-line" class="w-20 h-20" /></div>
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
              <div class="text-price font-bold text-base mt-1">{{ formatMoney(p.price_display ?? p.price, prefs.currencyOf(p.display_currency)) }}</div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- 热门标签 -->
    <div class="max-w-6xl mx-auto px-4 mt-3">
      <HotTags v-if="settings.config?.show_hot_tags" :ids="settings.config?.hot_tag_categories || []" />
    </div>

    <!-- 移动端分类条(sidebar 模式移动端隐藏左侧树,这里兜底显示一级分类 + 可展开子分类) -->
    <div
      v-if="settings.config?.category_nav_style === 'sidebar'"
      class="md:hidden bg-white border-b border-border overflow-x-auto scrollbar-hide"
    >
      <div class="flex items-center gap-2 px-4 py-2.5">
        <button
          @click="category = null; mobileExpandedCat = null"
          :class="[
            'shrink-0 px-3.5 py-1.5 rounded-pill text-xs font-medium whitespace-nowrap transition',
            category === null ? 'bg-primary text-white shadow-sm' : 'bg-surface-subtle text-ink-soft'
          ]"
        >{{ t('category.all') }}</button>
        <button
          v-for="c in topCats"
          :key="c.id"
          @click="mobileExpandedCat = mobileExpandedCat === c.id ? null : c.id"
          :class="[
            'shrink-0 px-3.5 py-1.5 rounded-pill text-xs font-medium whitespace-nowrap transition inline-flex items-center gap-1',
            category === c.id ? 'bg-primary text-white shadow-sm' : 'bg-surface-subtle text-ink-soft'
          ]"
        >
          <img v-if="c.icon && /^https?:\/\/|^\/storage\//.test(c.icon)" :src="c.icon" alt="" class="w-3.5 h-3.5 object-contain inline-block align-middle" />
          <span v-else-if="c.icon" class="mr-0.5">{{ c.icon }}</span>{{ c.name }}
          <span v-if="c.children?.length" class="text-[8px] opacity-60">{{ mobileExpandedCat === c.id ? '▼' : '▶' }}</span>
        </button>
      </div>
      <!-- 移动端子分类(选中父分类时展示,可横向滚动) -->
      <div
        v-if="mobileExpandedCat !== null"
        class="flex items-center gap-2 px-4 pb-2.5 overflow-x-auto scrollbar-hide border-t border-border pt-2"
      >
        <button
          @click="category = mobileExpandedCat"
          :class="[
            'shrink-0 px-3 py-1.5 rounded-pill text-xs font-medium whitespace-nowrap transition',
            category === mobileExpandedCat ? 'bg-primary text-white shadow-sm' : 'bg-surface-subtle text-ink-soft'
          ]"
        >{{ t('category.viewAll') }}</button>
        <template v-for="c in topCats" :key="c.id">
          <button
            v-for="ch in (mobileExpandedCat === c.id ? (c.children || []) : [])"
            :key="ch.id"
            @click="category = ch.id"
            :class="[
              'shrink-0 px-3 py-1.5 rounded-pill text-xs font-medium whitespace-nowrap transition',
              category === ch.id ? 'bg-primary text-white shadow-sm' : 'bg-surface-subtle text-ink-soft'
            ]"
          >{{ ch.name }}</button>
        </template>
      </div>
    </div>

    <!-- pills/combo:全宽顶行(flex 之外),分类在搜索框上方 -->
    <CategoryNav
      v-if="settings.config && settings.config.category_nav_style !== 'sidebar'"
      v-model="category"
      :style="settings.config.category_nav_style"
    />
    <div class="flex max-w-6xl mx-auto mt-2">
      <!-- sidebar:左侧树(与右侧内容并排) -->
      <CategoryNav
        v-if="settings.config && settings.config.category_nav_style === 'sidebar'"
        v-model="category"
        :style="settings.config.category_nav_style"
      />
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
          <!-- 加载更多(分页) -->
          <div v-if="hasMore" class="flex justify-center mt-6">
            <button
              type="button"
              class="px-8 py-2.5 rounded-pill border border-primary text-primary text-sm font-medium hover:bg-primary-light hover:border-primary-hover transition disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="products.loading"
              @click="products.fetchMore()"
            >{{ products.loading ? t('product.home.loadingMore') : t('product.home.loadMore') }}</button>
          </div>
          <div v-if="!products.loading && products.list.length" class="text-center text-ink-muted text-xs mt-4">
            {{ t('product.home.pageInfo', { page: products.page, total: products.lastPage }) }}
          </div>
        </div>
        <div v-if="!products.loading && !products.list.length" class="text-center text-ink-muted py-20">
          <div class="text-5xl mb-3 opacity-40"><AppIcon name="ri:archive-line" class="w-12 h-12" /></div>
          <div>{{ t('product.home.noProducts') }}</div>
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
