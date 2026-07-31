<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { getMyOrders, type OrderDetail } from '@/api/orders'
import { formatMoney } from '@/utils/money'
import { usePreferencesStore } from '@/stores/preferences'

const router = useRouter()
const prefs = usePreferencesStore()
const list = ref<OrderDetail[]>([])
const loading = ref(true)
const err = ref('')
// 已展开卡密的订单号集合
const expanded = ref<Set<string>>(new Set())

const statusText = (s: string) => ({
  pending: '待支付', paid: '已支付', closed: '已关闭', refunded: '已退款',
}[s] || s)

const statusClass = (s: string) => ({
  pending: 'bg-orange-100 text-orange-700',
  paid: 'bg-green-100 text-green-700',
  closed: 'bg-gray-100 text-gray-600',
  refunded: 'bg-red-100 text-red-700',
}[s] || 'bg-gray-100 text-gray-600')

const fmtDate = (d?: string) => {
  if (!d) return ''
  // 后端返回 'Y-m-d H:i:s',直接展示即可
  return d
}

function toggle(orderNo: string) {
  if (expanded.value.has(orderNo)) expanded.value.delete(orderNo)
  else expanded.value.add(orderNo)
}

function copy(text: string) {
  navigator.clipboard.writeText(text)
}

async function copyAll(cards: string[]) {
  await navigator.clipboard.writeText(cards.join('\n'))
}

onMounted(async () => {
  try {
    list.value = await getMyOrders()
  } catch (e: any) {
    err.value = e?.response?.data?.message || '加载订单失败'
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="max-w-3xl mx-auto px-4 py-6">
    <h1 class="text-xl font-bold text-ink mb-4">我的订单</h1>

    <div v-if="loading" class="text-center text-ink-muted py-20">加载中…</div>
    <div v-else-if="err" class="text-center text-danger py-20">{{ err }}</div>
    <div v-else-if="!list.length" class="text-center text-ink-muted py-20">暂无订单</div>

    <div v-else class="space-y-3">
      <div v-for="o in list" :key="o.order_no"
        class="bg-white rounded-card border border-border p-4">
        <!-- 头部:商品 + 状态 -->
        <div class="flex items-start gap-3">
          <img v-if="o.product_cover" :src="o.product_cover" :alt="o.product_name"
            class="w-14 h-14 rounded-field object-cover bg-surface-subtle shrink-0" />
          <div v-else class="w-14 h-14 rounded-field bg-surface-subtle shrink-0 flex items-center justify-center text-ink-muted text-xs">
            无图
          </div>

          <div class="flex-1 min-w-0">
            <div class="text-sm font-semibold text-ink truncate">{{ o.product_name || '商品已下架' }}</div>
            <div class="text-xs text-ink-muted mt-0.5">× {{ o.quantity }}</div>
            <div class="text-price font-bold mt-1">{{ formatMoney(o.amount_display ?? o.amount, prefs.currentCurrency) }}</div>
          </div>

          <span class="text-xs font-bold px-2 py-0.5 rounded-pill shrink-0"
            :class="statusClass(o.status)">
            {{ statusText(o.status) }}
          </span>
        </div>

        <!-- 订单号 + 时间 -->
        <div class="flex justify-between items-center mt-3 pt-2 border-t border-border">
          <span class="text-[11px] text-ink-muted">{{ o.order_no }}</span>
          <span class="text-[11px] text-ink-muted">{{ fmtDate(o.paid_at || o.created_at) }}</span>
        </div>

        <!-- 卡密(已支付且有卡) -->
        <div v-if="o.status === 'paid' && o.cards.length" class="mt-3">
          <button @click="toggle(o.order_no)"
            class="text-xs text-primary hover:text-primary-hover flex items-center gap-1 transition">
            {{ expanded.has(o.order_no) ? '收起卡密' : `查看卡密(${o.cards.length})` }}
            <span class="text-[10px]">{{ expanded.has(o.order_no) ? '▲' : '▼' }}</span>
          </button>

          <div v-if="expanded.has(o.order_no)" class="mt-2 space-y-2">
            <div v-for="(card, i) in o.cards" :key="i" class="flex items-center gap-2">
              <code class="flex-1 text-xs bg-surface-subtle p-2 rounded-field break-all">{{ card }}</code>
              <button @click="copy(card)" class="text-primary text-xs shrink-0 hover:text-primary-hover transition">复制</button>
            </div>
            <button v-if="o.cards.length > 1" @click="copyAll(o.cards)"
              class="text-xs text-ink-soft underline mt-1 hover:text-primary transition">复制全部</button>
          </div>
        </div>

        <!-- 待支付提示 -->
        <div v-else-if="o.status === 'pending'" class="mt-3 text-xs text-orange-600">
          订单待支付,支付后自动发货
        </div>
      </div>
    </div>
  </div>
</template>
