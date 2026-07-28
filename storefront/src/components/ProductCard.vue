<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useSettingsStore } from '@/stores/settings'
import type { Product } from '@/api/products'

const props = defineProps<{ product: Product }>()
const router = useRouter()
const settings = useSettingsStore()
const view = computed(() => settings.effectiveView)
function go() { router.push(`/product/${props.product.slug}`) }
function fmt(fen: number) { return (fen / 100).toFixed(2) }
</script>

<template>
  <!-- 网格 / 双栏 -->
  <div v-if="view !== 'list'" @click="go"
    class="cursor-pointer border border-gray-200 rounded-card bg-white overflow-hidden hover:shadow-md transition">
    <div class="aspect-square bg-gradient-to-br from-blue-100 to-indigo-100 flex items-center justify-center text-primary text-xs">
      <img v-if="product.cover" :src="product.cover" class="w-full h-full object-cover" />
      <span v-else>无图</span>
    </div>
    <div class="p-2">
      <div class="text-xs font-semibold text-ink line-clamp-2 h-8">{{ product.name }}</div>
      <div class="text-primary font-bold mt-1">¥{{ fmt(product.price) }}</div>
      <div v-if="settings.config?.show_stock" class="text-[10px] text-ink-muted">库存 {{ product.stock }}</div>
      <div v-if="settings.config?.show_sales" class="text-[10px] text-ink-muted">已售 {{ product.sales }}</div>
    </div>
  </div>

  <!-- 列表行 -->
  <div v-else @click="go" class="flex gap-3 p-3 border-b border-gray-100 cursor-pointer hover:bg-gray-50 items-center">
    <div class="w-16 h-12 bg-gradient-to-br from-blue-100 to-indigo-100 rounded flex items-center justify-center text-primary text-[9px] flex-shrink-0">
      <img v-if="product.cover" :src="product.cover" class="w-full h-full object-cover rounded" />
      <span v-else>缩略</span>
    </div>
    <div class="flex-1 min-w-0">
      <div class="text-xs font-semibold text-ink truncate">{{ product.name }}</div>
      <div class="text-primary font-bold text-sm">¥{{ fmt(product.price) }}
        <span v-if="settings.config?.show_stock || settings.config?.show_sales" class="text-[10px] text-ink-muted font-normal">
          · <span v-if="settings.config?.show_stock">库存 {{ product.stock }}</span>
          <span v-if="settings.config?.show_sales"> · 已售 {{ product.sales }}</span>
        </span>
      </div>
    </div>
    <button class="bg-primary text-white text-xs px-3 py-1 rounded-field">购买</button>
  </div>
</template>
