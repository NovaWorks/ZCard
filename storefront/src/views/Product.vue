<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import { getProduct, type Product } from '@/api/products'
import { useSettingsStore } from '@/stores/settings'

const route = useRoute()
const settings = useSettingsStore()
const product = ref<Product | null>(null)
const err = ref('')
const selectedSku = ref<number | null>(null)
const qty = ref(1)
const currentImg = ref(0)

onMounted(async () => {
  try {
    product.value = await getProduct(route.params.id as string)
    selectedSku.value = product.value.skus?.[0]?.id ?? null
  } catch (e) { err.value = '商品不存在' }
})

const price = computed(() => {
  if (!product.value) return 0
  const sku = product.value.skus?.find(s => s.id === selectedSku.value)
  return sku ? sku.price : product.value.price
})
const fmt = (fen: number) => (fen / 100).toFixed(2)
const reviews = computed(() => product.value?.virtual_reviews || {})
function buy() {
  alert(`P1-C 收银台即将开放\n已选: SKU#${selectedSku.value} × ${qty.value}`)
}
</script>

<template>
  <div v-if="err" class="max-w-3xl mx-auto py-20 text-center text-danger">{{ err }}</div>
  <div v-else-if="product" class="max-w-5xl mx-auto px-4 py-6">
    <div class="text-xs text-ink-muted mb-4">首页 / {{ product.category?.name }} / {{ product.name }}</div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <!-- 左:配图 -->
      <div>
        <div class="aspect-square rounded-card border bg-gradient-to-br from-blue-100 to-indigo-100 flex items-center justify-center overflow-hidden">
          <img v-if="product.images?.[currentImg]" :src="product.images[currentImg]" class="w-full h-full object-cover" />
          <span v-else class="text-primary">无图</span>
        </div>
        <div class="flex gap-2 mt-2">
          <div v-for="(img, i) in (product.images || [])" :key="i" @click="currentImg = i"
            :class="['w-14 h-14 rounded border-2 cursor-pointer', currentImg === i ? 'border-primary' : 'border-transparent']">
            <img :src="img" class="w-full h-full object-cover rounded" />
          </div>
        </div>
      </div>

      <!-- 右:购买区 -->
      <div>
        <h1 class="text-lg font-bold text-ink leading-snug">{{ product.name }}</h1>
        <div class="text-xs text-ink-muted mt-1">虚拟商品 · 自动发货 · 7×24 小时</div>

        <!-- 促销价格区 -->
        <div class="mt-3 bg-gradient-to-br from-orange-50 to-white border border-orange-200 rounded-card p-4 relative">
          <span class="absolute top-0 right-0 bg-gradient-to-br from-red-500 to-orange-400 text-white text-[9px] font-bold px-3 py-1 rounded-bl-lg">限时</span>
          <div class="flex items-baseline gap-2">
            <span class="text-red-500 font-bold text-sm">¥</span>
            <span class="text-red-500 font-extrabold text-3xl">{{ fmt(price) }}</span>
          </div>
        </div>

        <!-- 评分汇总 -->
        <div class="flex border-t border-b border-gray-100 py-3 my-3 text-center text-xs text-ink-muted">
          <div class="flex-1 border-r border-gray-100"><span class="block text-sm font-bold text-ink">{{ reviews.rating || '—' }}</span>评分</div>
          <div class="flex-1 border-r border-gray-100"><span class="block text-sm font-bold text-ink">{{ reviews.count || 0 }}</span>评价</div>
          <div class="flex-1 border-r border-gray-100"><span class="block text-sm font-bold text-red-500">{{ product.sales }}</span>已售</div>
          <div class="flex-1"><span class="block text-sm font-bold text-ink">{{ product.stock }}</span>库存</div>
        </div>

        <!-- 服务保障 -->
        <div class="flex gap-3 flex-wrap text-[10px] text-ink-soft py-2">
          <span>✓ 自动发货</span><span>✓ 即时到账</span><span>✓ 正品保障</span><span>✓ 售后无忧</span>
        </div>

        <!-- SKU -->
        <div v-if="product.skus?.length" class="mt-4">
          <div class="text-xs font-semibold text-ink-soft mb-2">选择套餐 <span class="text-red-500">*</span></div>
          <div class="flex flex-wrap gap-2">
            <div v-for="s in product.skus" :key="s.id" @click="selectedSku = s.id"
              :class="['relative border-2 rounded-card px-3 py-2 cursor-pointer text-center min-w-[80px]', selectedSku === s.id ? 'border-primary bg-blue-50' : 'border-gray-200']">
              <div :class="['text-xs font-semibold', selectedSku === s.id ? 'text-primary' : 'text-ink-soft']">{{ s.name }}</div>
              <div class="text-xs font-bold text-red-500">¥{{ fmt(s.price) }}</div>
            </div>
          </div>
        </div>

        <!-- 数量 -->
        <div class="mt-4">
          <div class="text-xs font-semibold text-ink-soft mb-2">购买数量</div>
          <div class="inline-flex border border-gray-200 rounded-field overflow-hidden">
            <button @click="qty > 1 && qty--" class="w-9 h-9 text-ink-soft">−</button>
            <input v-model.number="qty" type="number" class="w-14 h-9 text-center font-semibold border-x border-gray-200" />
            <button @click="qty++" class="w-9 h-9 text-ink-soft">+</button>
          </div>
          <span v-if="product.max_order && product.max_order > 0" class="text-[10px] text-ink-muted ml-2">(单次限购 {{ product.max_order }} 件)</span>
        </div>

        <!-- 库存条 -->
        <div class="mt-3" v-if="settings.config?.show_stock">
          <div class="flex justify-between text-[10px] text-ink-muted mb-1"><span>库存充足</span><span>{{ product.stock }} 件</span></div>
          <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
            <div class="h-full bg-green-500" :style="{ width: Math.min(product.stock / 600 * 100, 100) + '%' }"></div>
          </div>
        </div>

        <!-- 立即购买 -->
        <button @click="buy" class="w-full mt-4 bg-gradient-to-br from-primary to-blue-500 text-white font-bold py-3 rounded-card shadow-md">立即购买</button>
      </div>
    </div>

    <!-- 商品描述 -->
    <div class="mt-6 border-t-4 border-gray-50 pt-4">
      <h2 class="text-sm font-bold mb-2 border-l-2 border-primary pl-2">商品详情</h2>
      <div class="text-xs text-ink-soft leading-relaxed border rounded-card p-4 bg-white whitespace-pre-wrap">{{ product.description || '暂无描述' }}</div>
    </div>

    <!-- 虚拟评论(若 show_reviews) -->
    <div v-if="settings.config?.show_reviews && reviews.list?.length" class="mt-4 border-t-4 border-gray-50 pt-4">
      <h2 class="text-sm font-bold mb-2 border-l-2 border-primary pl-2">用户评价</h2>
      <div v-for="(r, i) in reviews.list" :key="i" class="flex gap-2 py-3 border-b border-gray-50 text-xs">
        <div class="w-7 h-7 rounded-full bg-blue-100 text-primary flex items-center justify-center font-bold flex-shrink-0">{{ (r.name || '匿')[0] }}</div>
        <div><div class="font-semibold text-ink">{{ r.name || '匿名用户' }} <span class="text-orange-400">{{ '★'.repeat(r.rating || 5) }}</span></div><div class="text-ink-muted mt-1">{{ r.content }}</div></div>
      </div>
    </div>
  </div>
  <div v-else class="text-center text-ink-muted py-20">加载中…</div>
</template>
