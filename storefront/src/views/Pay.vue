<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { mockPay } from '@/api/orders'
import { getChannels, createPayment, balancePay, type PaymentChannel } from '@/api/payments'
import { usePreferencesStore } from '@/stores/preferences'
import { formatMoney } from '@/utils/money'
import AppIcon from '@/components/AppIcon.vue'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const prefs = usePreferencesStore()
const orderNo = route.params.orderNo as string

const channels = ref<PaymentChannel[]>([])
const loading = ref(true)
const err = ref('')
const payingChannelId = ref<number | null>(null)
const mockPaying = ref(false)

// 二维码 / 表单弹层
const qrcodeContent = ref('')
const formContainerId = 'pay-form-mount'

/** 通道 target_currency → 货币符号(从 /currencies 拉取的元信息) */
function currencySymbol(code?: string | null): string {
  if (!code) return ''
  return prefs.currencies.find((c) => c.code === code)?.symbol || ''
}

/** 支付方式标识 → 展示信息(图标/名称)。收银台显示具体支付方式而非通道名 */
const PAY_TYPE_META: Record<string, { icon: string; label: string }> = {
  alipay: { icon: 'ri:alipay-line', label: '支付宝' },
  wechat: { icon: 'ri:wechat-line', label: '微信支付' },
  wxpay: { icon: 'ri:wechat-line', label: '微信支付' },
  qqpay: { icon: 'ri:qq-line', label: 'QQ 钱包' },
  bank: { icon: 'ri:bank-line', label: '云闪付 / 网银' },
  jdpay: { icon: 'ri:shopping-bag-line', label: '京东支付' },
  paypal: { icon: 'ri:paypal-line', label: 'PayPal' },
  stripe: { icon: 'ri:bank-card-2-line', label: 'Stripe' },
  usdt: { icon: 'ri:coins-line', label: 'USDT' },
  tron: { icon: 'ri:coins-line', label: 'TRON' },
  trx: { icon: 'ri:coins-line', label: 'TRX' },
  balance: { icon: 'ri:wallet-3-line', label: '余额支付' },
}

/** 通道对应的支付方式列表;无 pay_types 时回退到通道自身 */
const channelPayTypes = (ch: PaymentChannel) => {
  const types = (ch.pay_types || []).filter(Boolean)
  if (types.length) {
    return types.map((t) => ({
      type: t,
      icon: PAY_TYPE_META[t]?.icon || ch.icon || 'ri:bank-card-2-line',
      label: PAY_TYPE_META[t]?.label || t,
    }))
  }
  return [{ type: ch.code, icon: ch.icon || 'ri:bank-card-2-line', label: ch.name }]
}

onMounted(async () => {
  // 偏好(含货币元信息)用于展示通道收款币种;失败不阻塞
  void prefs.load()
  await loadChannels()
})

// 组件卸载时清理轮询
onUnmounted(stopPolling)

async function loadChannels() {
  loading.value = true
  err.value = ''
  try {
    channels.value = await getChannels()
  } catch (e: any) {
    err.value = e?.response?.data?.message || t('order.pay.channelLoadFailed')
  } finally {
    loading.value = false
  }
}

async function selectChannel(channel: PaymentChannel) {
  if (payingChannelId.value !== null) return
  err.value = ''
  payingChannelId.value = channel.id
  try {
    // 余额支付:直接扣款成功,跳结果页
    if (channel.code === 'balance') {
      await balancePay(orderNo)
      router.push({ path: '/pay/result', query: { order_no: orderNo } })
      return
    }
    const result = await createPayment(orderNo, channel.id)
    handleResult(result)
  } catch (e: any) {
    err.value = e?.response?.data?.message || t('order.pay.payFailed')
  } finally {
    payingChannelId.value = null
  }
}

function handleResult(result: { type: string; redirect_url?: string; qrcode_content?: string; form_html?: string }) {
  if (result.type === 'redirect' && result.redirect_url) {
    window.location.href = result.redirect_url
    return
  }

  if (result.type === 'qrcode' && result.qrcode_content) {
    qrcodeContent.value = result.qrcode_content
    // 启动轮询:扫码支付成功后自动跳转结果页
    startPolling()
    return
  }

  if (result.type === 'form' && result.form_html) {
    const container = document.getElementById(formContainerId)
    if (container) {
      container.innerHTML = result.form_html
      const form = container.querySelector('form')
      if (form) {
        // 真实第三方通常返回一段自动提交的表单
        form.submit()
      }
    }
    return
  }

  err.value = t('order.pay.payUnknown')
}

/** 轮询订单状态(扫码场景:用户支付后自动跳转) */
let pollTimer: ReturnType<typeof setInterval> | null = null
function startPolling() {
  if (pollTimer) return
  pollTimer = setInterval(async () => {
    try {
      const { queryOrders } = await import('@/api/orders')
      const list = await queryOrders(orderNo)
      const found = Array.isArray(list) ? list.find(o => o.order_no === orderNo) : null
      if (found?.status === 'paid') {
        stopPolling()
        router.push('/pay/result?order_no=' + orderNo)
      }
    } catch { /* 忽略,继续轮询 */ }
  }, 3000)
}
function stopPolling() {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null }
}

async function pay() {
  mockPaying.value = true
  err.value = ''
  try {
    const res = await mockPay(orderNo)
    if (res.delivered) {
      alert(t('order.pay.mockSuccess'))
      router.push('/orders/query')
    }
  } catch (e: any) {
    err.value = e?.response?.data?.message || t('order.pay.payFailed')
  } finally {
    mockPaying.value = false
  }
}
</script>

<template>
  <div class="max-w-md mx-auto px-4 py-12 text-center">
    <div class="bg-white rounded-card border border-border p-6">
      <h2 class="text-lg font-bold text-ink mb-2">{{ t('order.pay.title') }}</h2>
      <div class="text-xs text-ink-muted mb-4">{{ t('order.pay.orderNo', { no: orderNo }) }}</div>

      <!-- 加载中 -->
      <div v-if="loading" class="text-sm text-ink-soft py-6">{{ t('order.pay.loadingChannels') }}</div>

      <!-- 错误 -->
      <div v-else-if="err" class="text-danger text-xs mb-3">{{ err }}</div>

      <!-- 通道列表 -->
      <div v-if="!loading && channels.length" class="grid grid-cols-2 gap-2 mb-4">
        <button
          v-for="ch in channels"
          :key="ch.id"
          type="button"
          :disabled="payingChannelId !== null"
          @click="selectChannel(ch)"
          class="flex items-center gap-2 border border-border rounded-card px-3 py-3 text-left hover:border-primary hover:bg-primary-light transition disabled:opacity-50"
          :class="payingChannelId === ch.id ? 'border-primary ring-2 ring-primary/20' : ''"
        >
          <span class="flex items-center gap-1.5 shrink-0">
            <template v-for="pt in channelPayTypes(ch)" :key="pt.type">
              <span class="w-7 h-7 rounded-md bg-surface-subtle flex items-center justify-center text-base">
                <AppIcon :name="pt.icon" class="w-4 h-4" />
              </span>
            </template>
          </span>
          <span class="text-sm font-medium text-ink leading-tight">
            <span class="block">{{
              payingChannelId === ch.id
                ? t('order.pay.processing')
                : channelPayTypes(ch)
                    .map((p) => p.label)
                    .join(' / ')
            }}</span>
            <span v-if="ch.code === 'balance' && ch.balance !== undefined" class="block text-[10px] font-normal text-ink-muted">
              {{ t('order.checkout.balanceLabel', { amount: formatMoney(ch.balance, null) }) }}
            </span>
            <span v-else-if="ch.target_currency" class="block text-[10px] font-normal text-ink-muted">
              {{ t('order.pay.receiveLabel', { symbol: currencySymbol(ch.target_currency), code: ch.target_currency }) }}
            </span>
          </span>
        </button>
      </div>

      <div v-if="!loading && !channels.length && !err" class="text-sm text-ink-soft py-4 mb-3">
        {{ t('order.pay.noChannels') }}
      </div>

      <!-- 二维码展示 -->
      <div v-if="qrcodeContent" class="bg-orange-50 border border-orange-200 rounded-card p-3 mb-4 text-left">
        <div class="text-xs font-medium text-ink mb-1">{{ t('order.pay.qrcodeHint') }}</div>
        <div class="break-all text-xs text-ink-muted select-all bg-white rounded p-2">{{ qrcodeContent }}</div>
      </div>

      <!-- 模拟支付 -->
      <div class="mt-4 border-t border-border pt-4">
        <div class="text-xs text-ink-muted mb-2">{{ t('order.pay.demoHint') }}</div>
        <button
          @click="pay"
          :disabled="mockPaying"
          class="w-full bg-gradient-to-r from-primary to-primary-hover text-white font-bold py-3 rounded-card shadow-md hover:shadow-pop disabled:opacity-50 transition"
        >
          {{ mockPaying ? t('order.pay.paying') : t('order.pay.demoPay') }}
        </button>
      </div>

      <!-- 第三方自动提交表单挂载点(不可见) -->
      <div :id="formContainerId" class="hidden"></div>
    </div>
  </div>
</template>
