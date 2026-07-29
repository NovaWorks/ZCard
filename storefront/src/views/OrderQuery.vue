<script setup lang="ts">
import { ref } from 'vue'
import { queryOrder, type OrderDetail } from '@/api/orders'
import { useSettingsStore } from '@/stores/settings'

const settings = useSettingsStore()
const contact = ref('')
const orderNo = ref('')
const password = ref('')
const result = ref<OrderDetail | null>(null)
const err = ref('')
const searched = ref(false)

const statusText = (s: string) => ({
  pending: '待支付', paid: '已支付', closed: '已关闭', refunded: '已退款',
}[s] || s)
const fmt = (fen: number) => (fen / 100).toFixed(2)

async function search() {
  err.value = ''
  result.value = null
  searched.value = true
  try {
    result.value = await queryOrder({
      contact: contact.value,
      order_no: orderNo.value,
      password: password.value || undefined,
    })
  } catch (e: any) {
    err.value = e?.response?.data?.message || '查询失败'
  }
}
function copy(text: string) {
  navigator.clipboard.writeText(text)
}
</script>

<template>
  <div class="max-w-md mx-auto px-4 py-8">
    <h1 class="text-xl font-bold text-ink mb-6">订单查询</h1>

    <div class="space-y-3 mb-4">
      <input v-model="contact" type="text" placeholder="邮箱/手机"
        class="w-full px-3 py-2 border border-gray-200 rounded-field text-sm focus:border-primary" />
      <input v-model="orderNo" type="text" placeholder="订单号"
        class="w-full px-3 py-2 border border-gray-200 rounded-field text-sm focus:border-primary" />
      <input v-if="settings.config?.order_query_password" v-model="password" type="password" placeholder="查询密码"
        class="w-full px-3 py-2 border border-gray-200 rounded-field text-sm focus:border-primary" />
    </div>
    <button @click="search" class="w-full bg-primary text-white font-bold py-2.5 rounded-card">查询</button>

    <div v-if="err" class="text-danger text-sm mt-4 text-center">{{ err }}</div>

    <div v-if="result" class="mt-6 bg-white rounded-card border border-gray-200 p-4">
      <div class="flex justify-between items-center mb-3">
        <span class="text-xs text-ink-muted">{{ result.order_no }}</span>
        <span class="text-xs font-bold px-2 py-0.5 rounded-full"
          :class="result.status === 'paid' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'">
          {{ statusText(result.status) }}
        </span>
      </div>
      <div class="text-sm text-ink mb-1">{{ result.product_name }} × {{ result.quantity }}</div>
      <div class="text-primary font-bold mb-3">¥{{ fmt(result.amount) }}</div>

      <div v-if="result.cards.length" class="border-t border-gray-100 pt-3">
        <div class="text-xs font-semibold text-ink-soft mb-2">卡密({{ result.cards.length }})</div>
        <div v-for="(card, i) in result.cards" :key="i" class="flex items-center gap-2 mb-2">
          <code class="flex-1 text-xs bg-gray-50 p-2 rounded break-all">{{ card }}</code>
          <button @click="copy(card)" class="text-primary text-xs">复制</button>
        </div>
      </div>
      <div v-else-if="result.status === 'pending'" class="text-xs text-orange-600 border-t border-gray-100 pt-3">
        订单待支付,支付后自动发货
      </div>
    </div>
  </div>
</template>
