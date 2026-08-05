<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { getProduct, type Product } from '@/api/products'
import { getProductReviews, type ReviewItem } from '@/api/reviews'
import { useSettingsStore } from '@/stores/settings'
import { formatMoney } from '@/utils/money'
import { usePreferencesStore } from '@/stores/preferences'

const route = useRoute()
const { t } = useI18n()
const settings = useSettingsStore()
const prefs = usePreferencesStore()
const product = ref<Product | null>(null)
const err = ref('')
const selectedSku = ref<number | null>(null)
const qty = ref(1)
const currentImg = ref(0)
/** 图片放大预览(lightbox) */
const lightboxOpen = ref(false)
function openLightbox() { lightboxOpen.value = true; document.body.style.overflow = 'hidden' }
function closeLightbox() { lightboxOpen.value = false; document.body.style.overflow = '' }
function prevImg() { currentImg.value = (currentImg.value - 1 + galleryImages.value.length) % galleryImages.value.length }
function nextImg() { currentImg.value = (currentImg.value + 1) % galleryImages.value.length }
onMounted(() => { window.addEventListener('keydown', onKeydown) })
onBeforeUnmount(() => { window.removeEventListener('keydown', onKeydown); document.body.style.overflow = '' })
function onKeydown(e: KeyboardEvent) {
  if (!lightboxOpen.value) return
  if (e.key === 'Escape') closeLightbox()
  else if (e.key === 'ArrowLeft') prevImg()
  else if (e.key === 'ArrowRight') nextImg()
}

/** 详情图:优先 images,为空时用封面兜底(上游导入的商品可能只有 cover) */
const galleryImages = computed(() => {
  const imgs = (product.value?.images || []).filter((i) => i)
  if (imgs.length) return imgs
  return product.value?.cover ? [product.value.cover] : []
})

/**
 * 商品描述支持 HTML 渲染(v-html)。做基本 XSS 过滤:
 * 移除 script/iframe/style 等危险标签与 on* 事件属性。
 */
const sanitizedDescription = computed(() => {
  const html = product.value?.description || ''
  return html
    .replace(/<script[\s\S]*?<\/script>/gi, '')
    .replace(/<iframe[\s\S]*?<\/iframe>/gi, '')
    .replace(/<style[\s\S]*?<\/style>/gi, '')
    .replace(/<link[\s\S]*?>/gi, '')
    .replace(/\s+on\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '')
})

// 真实 + 虚拟合并评价(由后端 getProductRating 合并)
const reviewRating = ref(0)
const reviewCount = ref(0)
const reviewList = ref<ReviewItem[]>([])

onMounted(async () => {
  try {
    product.value = await getProduct(route.params.id as string)
    selectedSku.value = product.value.skus?.[0]?.id ?? null
  } catch (e) { err.value = t('product.detail.notFound') }

  // 拉取合并评价(真实 + 虚拟)
  try {
    const data = await getProductReviews(route.params.id as string)
    reviewRating.value = data.rating || 0
    reviewCount.value = data.count || 0
    reviewList.value = data.list || []
  } catch (e) { /* 评价加载失败不阻塞页面 */ }
})

const price = computed(() => {
  if (!product.value) return 0
  const sku = product.value.skus?.find(s => s.id === selectedSku.value)
  return sku ? sku.price : product.value.price
})
/** 展示币种最小单位(优先用 _display 字段,缺失则回退基础金额) */
const priceDisplay = computed(() => {
  if (!product.value) return 0
  const sku = product.value.skus?.find(s => s.id === selectedSku.value)
  if (sku) return sku.price_display ?? sku.price
  return product.value.price_display ?? product.value.price
})
const fmtDate = (d: string | null) => d ? String(d).slice(0, 10) : ''
function buy() {
  alert(t('product.detail.buyAlert', { sku: selectedSku.value, qty: qty.value }))
}
</script>

<template>
  <div v-if="err" class="max-w-3xl mx-auto py-20 text-center text-danger">{{ err }}</div>
  <div v-else-if="product" class="max-w-5xl mx-auto px-4 py-6">
    <div class="text-xs text-ink-muted mb-4">{{ t('product.detail.breadcrumbHome') }} / {{ product.category?.name }} / {{ product.name }}</div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <!-- 左:配图 -->
      <div>
        <div @click="openLightbox" class="aspect-square rounded-card border border-border bg-gradient-to-br from-primary-soft to-primary-light flex items-center justify-center overflow-hidden cursor-zoom-in">
          <img v-if="galleryImages[currentImg]" :src="galleryImages[currentImg]" class="w-full h-full object-cover" />
          <span v-else class="text-primary/40">{{ t('common.noImage') }}</span>
        </div>
        <div class="flex gap-2 mt-2">
          <div v-for="(img, i) in galleryImages" :key="i" @click="currentImg = i"
            :class="['w-14 h-14 rounded border-2 cursor-pointer', currentImg === i ? 'border-primary' : 'border-transparent']">
            <img :src="img" class="w-full h-full object-cover rounded" />
          </div>
        </div>
      </div>

      <!-- 图片放大预览(全屏遮罩,点击/ESC/左右键关闭切换) -->
      <Teleport to="body">
        <div v-if="lightboxOpen && galleryImages[currentImg]" class="fixed inset-0 z-50 bg-black/90 flex items-center justify-center" @click.self="closeLightbox">
          <button @click="closeLightbox" class="absolute top-4 right-4 w-10 h-10 rounded-full bg-white/10 text-white text-xl hover:bg-white/25 transition flex items-center justify-center z-10" aria-label="关闭">✕</button>
          <button
            v-if="galleryImages.length > 1"
            @click="prevImg"
            class="absolute left-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-white/10 text-white text-xl hover:bg-white/25 transition flex items-center justify-center z-10"
            aria-label="上一张">‹</button>
          <img :src="galleryImages[currentImg]" class="max-w-[90vw] max-h-[90vh] object-contain rounded-lg shadow-2xl" @click="closeLightbox" />
          <button
            v-if="galleryImages.length > 1"
            @click="nextImg"
            class="absolute right-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-white/10 text-white text-xl hover:bg-white/25 transition flex items-center justify-center z-10"
            aria-label="下一张">›</button>
        </div>
      </Teleport>

      <!-- 右:购买区 -->
      <div>
        <h1 class="text-lg font-bold text-ink leading-snug">{{ product.name }}</h1>
        <div class="text-xs text-ink-muted mt-1">{{ t('product.detail.virtualTag') }}</div>

        <!-- 促销价格区 -->
        <div class="mt-3 bg-gradient-to-br from-price-light to-white border border-orange-200 rounded-card p-4 relative">
          <span class="absolute top-0 right-0 bg-gradient-to-br from-price to-orange-400 text-white text-[9px] font-bold px-3 py-1 rounded-bl-lg">{{ t('product.detail.limitedTag') }}</span>
          <div class="flex items-baseline gap-2">
            <span class="text-price font-extrabold text-3xl">{{ formatMoney(priceDisplay, prefs.currentCurrency) }}</span>
          </div>
        </div>

        <!-- 评分汇总(销量/库存受后台开关控制) -->
        <div class="flex border-t border-b border-border py-3 my-3 text-center text-xs text-ink-muted">
          <div class="flex-1 border-r border-border"><span class="block text-sm font-bold text-ink">{{ reviewRating || '—' }}</span>{{ t('product.detail.ratingLabel') }}</div>
          <div v-if="settings.config?.show_reviews" class="flex-1 border-r border-border"><span class="block text-sm font-bold text-ink">{{ reviewCount || 0 }}</span>{{ t('product.detail.reviewLabel') }}</div>
          <div v-if="settings.config?.show_sales" class="flex-1 border-r border-border"><span class="block text-sm font-bold text-price">{{ product.sales }}</span>{{ t('common.sold') }}</div>
          <div v-if="settings.config?.show_stock" class="flex-1"><span class="block text-sm font-bold text-ink">{{ product.stock }}</span>{{ t('common.stock') }}</div>
        </div>

        <!-- 服务保障 -->
        <div class="flex gap-3 flex-wrap text-[10px] text-ink-soft py-2">
          <span>{{ t('product.detail.guarantee.autoDelivery') }}</span><span>{{ t('product.detail.guarantee.instantAccount') }}</span><span>{{ t('product.detail.guarantee.genuineGuarantee') }}</span><span>{{ t('product.detail.guarantee.afterSales') }}</span>
        </div>

        <!-- SKU -->
        <div v-if="product.skus?.length" class="mt-4">
          <div class="text-xs font-semibold text-ink-soft mb-2">{{ t('product.detail.skuTitle') }} <span class="text-price">*</span></div>
          <div class="flex flex-wrap gap-2">
            <div v-for="s in product.skus" :key="s.id" @click="selectedSku = s.id"
              :class="['relative border-2 rounded-card px-3 py-2 cursor-pointer text-center min-w-[80px] transition', selectedSku === s.id ? 'border-primary bg-primary-light' : 'border-border hover:border-primary/40']">
              <div :class="['text-xs font-semibold', selectedSku === s.id ? 'text-primary' : 'text-ink-soft']">{{ s.name }}</div>
              <div class="text-xs font-bold text-price">{{ formatMoney(s.price_display ?? s.price, prefs.currentCurrency) }}</div>
            </div>
          </div>
        </div>

        <!-- 数量 -->
        <div class="mt-4">
          <div class="text-xs font-semibold text-ink-soft mb-2">{{ t('product.detail.qtyTitle') }}</div>
          <div class="inline-flex border border-border rounded-field overflow-hidden">
            <button @click="qty > 1 && qty--" class="w-9 h-9 text-ink-soft hover:bg-surface-subtle transition">−</button>
            <input v-model.number="qty" type="number" class="w-14 h-9 text-center font-semibold border-x border-border" />
            <button @click="qty++" class="w-9 h-9 text-ink-soft hover:bg-surface-subtle transition">+</button>
          </div>
          <span v-if="product.max_order && product.max_order > 0" class="text-[10px] text-ink-muted ml-2">{{ t('product.detail.maxOrderHint', { n: product.max_order }) }}</span>
        </div>

        <!-- 库存条 -->
        <div class="mt-3" v-if="settings.config?.show_stock">
          <div class="flex justify-between text-[10px] text-ink-muted mb-1"><span>{{ t('product.detail.stockPlenty') }}</span><span>{{ t('product.detail.stockUnit', { n: product.stock }) }}</span></div>
          <div class="h-1.5 bg-surface-subtle rounded-full overflow-hidden">
            <div class="h-full bg-success" :style="{ width: Math.min(product.stock / 600 * 100, 100) + '%' }"></div>
          </div>
        </div>

        <!-- 立即购买 -->
        <button @click="buy" class="w-full mt-4 bg-gradient-to-r from-primary to-primary-hover text-white font-bold py-3 rounded-card shadow-md hover:shadow-pop transition">{{ t('product.detail.buyNow') }}</button>
      </div>
    </div>

    <!-- 商品描述 -->
    <div v-if="settings.config?.show_description !== false" class="mt-6 border-t-4 border-surface-subtle pt-4">
      <h2 class="text-sm font-bold mb-3 border-l-2 border-primary pl-2">{{ t('product.detail.detailTitle') }}</h2>
      <div
        v-if="sanitizedDescription"
        class="rich-content border border-border rounded-card p-4 bg-white"
        v-html="sanitizedDescription"
      ></div>
      <div v-else class="text-xs text-ink-soft leading-relaxed border border-border rounded-card p-4 bg-white whitespace-pre-wrap">{{ t('product.detail.noDescription') }}</div>
    </div>

    <!-- 用户评价(真实 + 虚拟,若 show_reviews) -->
    <div v-if="settings.config?.show_reviews && reviewList.length" class="mt-4 border-t-4 border-surface-subtle pt-4">
      <h2 class="text-sm font-bold mb-3 border-l-2 border-primary pl-2">{{ t('product.detail.reviewTitle') }} <span class="text-ink-muted font-normal">({{ reviewCount }})</span></h2>
      <div v-for="r in reviewList" :key="r.id" class="flex gap-2 py-3 border-b border-border text-xs">
        <div class="w-7 h-7 rounded-full bg-primary-soft text-primary flex items-center justify-center font-bold flex-shrink-0">{{ (r.name || '匿')[0] }}</div>
        <div class="min-w-0">
          <div class="flex items-center justify-between gap-2">
            <span class="font-semibold text-ink">{{ r.name || t('product.detail.reviewAnonymous') }} <span class="text-orange-400">{{ '★'.repeat(r.rating || 5) }}</span></span>
            <span v-if="r.created_at" class="text-ink-muted text-[10px] flex-shrink-0">{{ fmtDate(r.created_at) }}</span>
          </div>
          <div class="text-ink-muted mt-1">{{ r.content }}</div>
        </div>
      </div>
    </div>
  </div>
  <div v-else class="text-center text-ink-muted py-20">{{ t('product.detail.loading') }}</div>
</template>

<style scoped>
/* 商品描述富文本排版(description 支持 HTML) */
.rich-content {
  font-size: 13px;
  line-height: 1.8;
  color: var(--color-ink, #333);
  word-break: break-word;
}
.rich-content :deep(p) {
  margin: 0 0 10px;
}
.rich-content :deep(p:last-child) {
  margin-bottom: 0;
}
.rich-content :deep(h1),
.rich-content :deep(h2),
.rich-content :deep(h3),
.rich-content :deep(h4) {
  font-weight: 700;
  margin: 14px 0 8px;
  line-height: 1.4;
}
.rich-content :deep(h1) { font-size: 1.4em; }
.rich-content :deep(h2) { font-size: 1.25em; }
.rich-content :deep(h3) { font-size: 1.1em; }
.rich-content :deep(h4) { font-size: 1em; }
.rich-content :deep(ul),
.rich-content :deep(ol) {
  padding-left: 20px;
  margin: 0 0 10px;
}
.rich-content :deep(ul) { list-style: disc; }
.rich-content :deep(ol) { list-style: decimal; }
.rich-content :deep(li) {
  margin: 2px 0;
}
.rich-content :deep(img) {
  max-width: 100%;
  height: auto;
  border-radius: 6px;
}
.rich-content :deep(a) {
  color: #377dff;
  text-decoration: underline;
}
.rich-content :deep(strong),
.rich-content :deep(b) {
  font-weight: 700;
}
.rich-content :deep(em),
.rich-content :deep(i) {
  font-style: italic;
}
.rich-content :deep(code) {
  background: rgba(0, 0, 0, 0.06);
  padding: 1px 5px;
  border-radius: 4px;
  font-size: 0.9em;
}
.rich-content :deep(pre) {
  background: rgba(0, 0, 0, 0.05);
  padding: 10px 12px;
  border-radius: 6px;
  overflow-x: auto;
  margin: 0 0 10px;
}
.rich-content :deep(blockquote) {
  border-left: 3px solid #ddd;
  padding-left: 12px;
  color: #888;
  margin: 0 0 10px;
}
.rich-content :deep(table) {
  width: 100%;
  border-collapse: collapse;
  margin: 0 0 10px;
}
.rich-content :deep(th),
.rich-content :deep(td) {
  border: 1px solid #eee;
  padding: 6px 10px;
  text-align: left;
}
</style>
