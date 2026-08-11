<script setup lang="ts">
import { ref, onMounted, computed, watch, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { queryOrders, getMyOrders, type OrderDetail } from '@/api/orders'
import { formatMoney } from '@/utils/money'
import { usePreferencesStore } from '@/stores/preferences'
import AppIcon from '@/components/AppIcon.vue'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const prefs = usePreferencesStore()

const orderNo = computed(() => (route.query.order_no as string) || (route.query.out_trade_no as string) || '')
const qrcodeContent = computed(() => (route.query.qrcode as string) || '')
const isCancel = computed(() => route.query.status === 'cancel')
const loading = ref(true)
const order = ref<OrderDetail | null>(null)
const paid = ref(false)

// 二维码渲染(canvas):等 v-else-if 分支挂载完成(nextTick)后再绘制
const qrCanvas = ref<HTMLCanvasElement | null>(null)
async function renderQrcode() {
  if (!qrcodeContent.value || !qrCanvas.value) return
  try {
    const QRCode = (await import('qrcode')).default
    await QRCode.toCanvas(qrCanvas.value, qrcodeContent.value, { width: 240, margin: 2 })
  } catch { /* 二维码渲染失败不阻塞页面 */ }
}
watch(qrcodeContent, async (v) => {
  if (v) {
    await nextTick()
    renderQrcode()
  }
}, { immediate: true })

/**
 * 查询订单状态。
 * 登录用户优先走 /orders/mine(按 user_id 查,不受「查询密码」过滤——
 * 开启 order_query_password 时,queryOrders 不带密码查不到有密码的订单,
 * 支付成功回跳页就会永远停在"处理中")。
 * 游客回退订单查询(无密码订单可查)。
 */
async function fetchOrderStatus(no: string): Promise<OrderDetail | null> {
  if (localStorage.getItem('zcard_token')) {
    try {
      const mine = await getMyOrders()
      const found = Array.isArray(mine) ? mine.find(o => o.order_no === no) : null
      if (found) return found
    } catch { /* 回退订单查询 */ }
  }
  try {
    const list = await queryOrders(no)
    return Array.isArray(list) ? list.find(o => o.order_no === no) ?? null : null
  } catch {
    return null
  }
}

onMounted(async () => {
  // 取消支付,无需查询
  if (isCancel.value) {
    loading.value = false
    return
  }
  // 二维码场景:渲染二维码(当面付扫码),同时有订单号则轮询支付状态
  if (qrcodeContent.value) {
    loading.value = false
  }
  // 没有订单号(部分第三方回跳不带),只能提示等待
  if (!orderNo.value) {
    loading.value = false
    return
  }
  // 持续轮询订单状态(第三方异步回调可能延迟,最多 20 次 × 2s ≈ 40 秒)
  for (let i = 0; i < 20; i++) {
    const found = await fetchOrderStatus(orderNo.value)
    if (found) {
      order.value = found
      if (found.status === 'paid') {
        paid.value = true
        break
      }
    }
    if (i < 19) await new Promise(r => setTimeout(r, 2000))
  }
  loading.value = false
})
</script>

<template>
  <div class="max-w-md mx-auto px-4 py-12">
    <div class="bg-white rounded-card border border-border p-8 shadow-card text-center">
      <!-- 加载中 -->
      <template v-if="loading">
        <div class="w-16 h-16 mx-auto bg-primary-light rounded-full flex items-center justify-center mb-4 animate-pulse"><AppIcon name="ri:time-line" class="w-8 h-8" /></div>
        <h2 class="text-lg font-bold text-ink mb-1">{{ t('order.payResult.confirmingTitle') }}</h2>
        <p class="text-xs text-ink-muted">{{ t('order.payResult.confirmingHint') }}</p>
      </template>

      <!-- 取消支付 -->
      <template v-else-if="isCancel">
        <div class="w-16 h-16 mx-auto bg-gray-100 rounded-full flex items-center justify-center mb-4"><AppIcon name="ri:emotion-sad-line" class="w-8 h-8" /></div>
        <h2 class="text-lg font-bold text-ink mb-1">{{ t('order.payResult.cancelTitle') }}</h2>
        <p class="text-xs text-ink-muted mb-5">{{ t('order.payResult.cancelHint') }}</p>
        <div class="flex gap-2 justify-center">
          <button @click="router.push('/orders/query')" class="px-4 py-2 text-xs bg-surface-subtle text-ink-soft rounded-field hover:bg-border transition">{{ t('order.payResult.orderQuery') }}</button>
          <button @click="router.push('/')" class="px-4 py-2 text-xs bg-primary text-white rounded-field hover:bg-primary-hover transition">{{ t('order.payResult.continueShopping') }}</button>
        </div>
      </template>

      <!-- 扫码支付:展示二维码(当面付),轮询等待支付结果 -->
      <template v-else-if="qrcodeContent && !paid">
        <div class="w-16 h-16 mx-auto bg-primary-light rounded-full flex items-center justify-center mb-4"><AppIcon name="ri:qr-scan-line" class="w-8 h-8" /></div>
        <h2 class="text-lg font-bold text-ink mb-1">{{ t('order.payResult.scanTitle') }}</h2>
        <p class="text-xs text-ink-muted mb-4">{{ t('order.payResult.scanHint') }}</p>
        <div class="flex justify-center mb-4">
          <canvas ref="qrCanvas" class="bg-white rounded-card border border-border p-2"></canvas>
        </div>
        <div v-if="orderNo" class="text-xs text-ink-muted mb-5">{{ t('order.payResult.processingWithNo', { no: orderNo }) }}</div>
        <div class="flex justify-center gap-2">
          <button @click="router.push('/orders/query')" class="px-4 py-2 text-xs bg-primary text-white rounded-field hover:bg-primary-hover transition">{{ t('order.payResult.orderQuery') }}</button>
          <button @click="router.push('/')" class="px-4 py-2 text-xs bg-surface-subtle text-ink-soft rounded-field hover:bg-border transition">{{ t('order.payResult.backHome') }}</button>
        </div>
      </template>

      <!-- 支付成功 -->
      <template v-else-if="paid && order">
        <div class="w-16 h-16 mx-auto bg-green-50 rounded-full flex items-center justify-center mb-4"><AppIcon name="ri:checkbox-circle-line" class="w-8 h-8" /></div>
        <h2 class="text-lg font-bold text-ink mb-1">{{ t('order.payResult.successTitle') }}</h2>
        <p class="text-xs text-ink-muted mb-4">{{ t('order.payResult.orderNoLabel', { no: order.order_no }) }}</p>
        <div class="bg-surface-subtle rounded-field p-3 mb-5 text-left">
          <div class="flex justify-between text-xs mb-1">
            <span class="text-ink-muted">{{ t('order.payResult.productLabel') }}</span>
            <span class="text-ink font-medium">{{ order.product_name }}</span>
          </div>
          <div class="flex justify-between text-xs">
            <span class="text-ink-muted">{{ t('order.payResult.amountLabel') }}</span>
            <span class="text-price font-bold">{{ formatMoney(order.amount_display ?? order.amount, prefs.currencyOf(order.display_currency)) }}</span>
          </div>
        </div>
        <div v-if="order.cards.length" class="mb-5">
          <div class="text-xs font-semibold text-ink-soft mb-2">{{ t('order.payResult.cardsTitle', { n: order.cards.length }) }}</div>
          <div v-for="(c, i) in order.cards" :key="i" class="bg-surface-subtle rounded-field p-2 mb-1.5 text-left">
            <code class="text-xs text-ink break-all">{{ c }}</code>
          </div>
        </div>
        <button @click="router.push('/orders/query')" class="w-full bg-primary text-white text-sm font-semibold py-2.5 rounded-card hover:bg-primary-hover transition">{{ t('order.payResult.viewOrderDetail') }}</button>
      </template>

      <!-- 等待回调(支付了但状态未更新) -->
      <template v-else>
        <div class="w-16 h-16 mx-auto bg-amber-50 rounded-full flex items-center justify-center mb-4"><AppIcon name="ri:time-line" class="w-8 h-8" /></div>
        <h2 class="text-lg font-bold text-ink mb-1">{{ t('order.payResult.processingTitle') }}</h2>
        <p class="text-xs text-ink-muted mb-5" v-if="orderNo">{{ t('order.payResult.processingWithNo', { no: orderNo }) }}<br/>{{ t('order.payResult.processingWithNoNext') }}</p>
        <p class="text-xs text-ink-muted mb-5" v-else>{{ t('order.payResult.processingNoNo') }}</p>
        <div class="flex gap-2 justify-center">
          <button @click="router.push('/orders/query')" class="px-4 py-2 text-xs bg-primary text-white rounded-field hover:bg-primary-hover transition">{{ t('order.payResult.orderQuery') }}</button>
          <button @click="router.push('/')" class="px-4 py-2 text-xs bg-surface-subtle text-ink-soft rounded-field hover:bg-border transition">{{ t('order.payResult.backHome') }}</button>
        </div>
      </template>
    </div>
  </div>
</template>
