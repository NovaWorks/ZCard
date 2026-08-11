<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSettingsStore } from '@/stores/settings'
import { formatMoney, stockText } from '@/utils/money'
import { usePreferencesStore } from '@/stores/preferences'
import type { Product } from '@/api/products'

const props = defineProps<{ product: Product }>()
const router = useRouter()
const { t } = useI18n()
const settings = useSettingsStore()
const prefs = usePreferencesStore()
const view = computed(() => settings.effectiveView)
/** 靓号自选:显示最低价起 */
const isPremium = computed(() => (props.product.pick_type ?? 'general') === 'premium')
const priceText = computed(() => {
  const cur = prefs.currencyOf(props.product.display_currency)
  const min = props.product.premium_min_price_display ?? props.product.premium_min_price
  return isPremium.value && min != null
    ? t('product.premium.fromPrice', { price: formatMoney(min, cur) })
    : formatMoney(props.product.price_display ?? props.product.price, cur)
})
function go() { router.push(`/product/${props.product.slug}`) }
</script>

<template>
  <!-- 网格 / 双栏 -->
  <div v-if="view !== 'list'" @click="go"
    class="group cursor-pointer bg-white rounded-card border border-border overflow-hidden hover:border-primary/40 hover:shadow-card-hover transition-all duration-200">
    <div class="aspect-square bg-gradient-to-br from-primary-soft to-primary-light flex items-center justify-center text-primary/40 text-xs overflow-hidden">
      <img v-if="product.cover" :src="product.cover" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" />
      <span v-else class="font-medium">{{ t('common.noImage') }}</span>
    </div>
    <div class="p-2.5">
      <div class="text-xs font-medium text-ink line-clamp-2 min-h-[2rem] leading-snug">{{ product.name }}</div>
      <div v-if="settings.config?.show_price !== false" class="flex items-baseline gap-1 mt-1.5">
        <span class="text-price font-extrabold text-base">{{ priceText }}</span>
      </div>
      <div class="flex justify-between items-center mt-1 text-[10px] text-ink-muted">
        <span v-if="settings.config?.show_sales">{{ t('common.sold') }} {{ product.sales }}</span>
        <span v-if="settings.config?.show_stock">{{ t('common.stock') }} {{ stockText(product.stock) }}</span>
      </div>
    </div>
  </div>

  <!-- 列表行 -->
  <div v-else @click="go" class="flex gap-3 p-3 border-b border-border cursor-pointer hover:bg-surface-subtle items-center transition-colors">
    <div class="w-16 h-12 bg-gradient-to-br from-primary-soft to-primary-light rounded-field flex items-center justify-center text-primary/40 text-[9px] flex-shrink-0 overflow-hidden">
      <img v-if="product.cover" :src="product.cover" class="w-full h-full object-cover rounded-field" />
      <span v-else>{{ t('common.noThumb') }}</span>
    </div>
    <div class="flex-1 min-w-0">
      <div class="text-xs font-medium text-ink truncate">{{ product.name }}</div>
      <div class="flex items-center gap-2 mt-0.5">
        <span v-if="settings.config?.show_price !== false" class="text-price font-bold text-sm">{{ priceText }}</span>
        <span v-if="settings.config?.show_sales" class="text-[10px] text-ink-muted">{{ t('common.sold') }} {{ product.sales }}</span>
        <span v-if="settings.config?.show_stock" class="text-[10px] text-ink-muted">{{ t('common.stock') }} {{ stockText(product.stock) }}</span>
      </div>
    </div>
    <button class="bg-primary text-white text-xs px-3 py-1.5 rounded-field hover:bg-primary-hover transition">{{ t('common.buy') }}</button>
  </div>
</template>
