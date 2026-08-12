<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { getChannels, type PaymentChannel } from '@/api/payments'
import { createRechargePayment, getRechargeStatus } from '@/api/recharge'
import { formatMoney } from '@/utils/money'
import { calcChannelFee } from '@/utils/fee'
import { usePreferencesStore } from '@/stores/preferences'
import AppIcon from '@/components/AppIcon.vue'
import PayBrandIcon from '@/components/PayBrandIcon.vue'
import { navigateToPaymentUrl, submitPaymentForm } from '@/utils/paymentNavigation'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const prefs = usePreferencesStore()

const rechargeNo = computed(() => route.params.rechargeNo as string)

const channels = ref<PaymentChannel[]>([])
const loading = ref(true)
const err = ref('')
const payingOptionKey = ref<string | null>(null)
const amountFen = ref(0)

/** 手续费提示文案:原价 + 手续费 = 应付(供用户在付款前确认) */
function feeTip(ch: PaymentChannel): string {
  if (!amountFen.value) return ''
  const { feeFen, payFen } = calcChannelFee(amountFen.value, ch)
  if (feeFen <= 0) return ''
  const orig = formatMoney(amountFen.value, prefs.baseCurrencyInfo)
  const pay = formatMoney(payFen, prefs.baseCurrencyInfo)
  return t('recharge.feeTip', { orig, fee: formatMoney(feeFen, prefs.baseCurrencyInfo), pay })
}

const qrcodeContent = ref('')
const formContainerId = 'recharge-form-mount'

function currencySymbol(code?: string | null): string {
  if (!code) return ''
  return prefs.currencies.find((c) => c.code === code)?.symbol || ''
}

/** 支付方式标识 → 展示信息(图标/名称)。与收银台 Checkout.vue 保持一致:显示具体支付方式而非通道名 */
const PAY_TYPE_META: Record<string, { icon: string; label: string }> = {
  alipay: { icon: 'alipay', label: '支付宝' },
  wechat: { icon: 'wechat', label: '微信支付' },
  wxpay: { icon: 'wechat', label: '微信支付' },
  qqpay: { icon: 'qqpay', label: 'QQ 钱包' },
  bank: { icon: 'bank', label: '云闪付 / 网银' },
  jdpay: { icon: 'jdpay', label: '京东支付' },
  paypal: { icon: 'paypal', label: 'PayPal' },
  stripe: { icon: 'stripe', label: 'Stripe' },
  usdt: { icon: 'usdt', label: 'USDT' },
  tron: { icon: 'usdt', label: 'TRON' },
  trx: { icon: 'usdt', label: 'TRX' },
  h5: { icon: 'ri:alipay-line', label: '手机网站支付' },
  scan: { icon: 'ri:qr-scan-line', label: '当面付扫码' },
  pos: { icon: 'ri:alipay-line', label: '刷卡支付' },
}

/** 通道对应的支付方式列表(带图标与名称);无 pay_types 时回退到通道自身 */
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

const paymentOptions = computed(() => channels.value.flatMap((channel) => {
  const types = channelPayTypes(channel)
  if (channel.code === 'epay') {
    return types.map((payType) => ({
      key: `${channel.id}:${payType.type}`,
      channel,
      payType: payType.type as string | undefined,
      types: [payType],
    }))
  }
  return [{ key: String(channel.id), channel, payType: undefined as string | undefined, types }]
}))

onMounted(async () => {
  void prefs.load()
  // 拉一次充值单状态拿金额用于展示;同时加载通道
  try {
    const st = await getRechargeStatus(rechargeNo.value)
    amountFen.value = st.amount
    if (st.status === 'paid') {
      // 已支付,直接跳结果页
      router.replace({ name: 'recharge-result', query: { recharge_no: rechargeNo.value } })
      return
    }
  } catch (e: any) {
    err.value = e?.response?.data?.message || t('recharge.notFound')
  }
  await loadChannels()
})

onUnmounted(stopPolling)

async function loadChannels() {
  loading.value = true
  err.value = ''
  try {
    // 充值不能用余额支付,过滤 balance 通道
    channels.value = (await getChannels()).filter(c => c.code !== 'balance')
  } catch (e: any) {
    err.value = e?.response?.data?.message || t('order.pay.channelLoadFailed')
  } finally {
    loading.value = false
  }
}

async function selectChannel(channel: PaymentChannel, payType: string | undefined, optionKey: string) {
  if (payingOptionKey.value !== null) return
  err.value = ''
  payingOptionKey.value = optionKey
  try {
    const result = await createRechargePayment(rechargeNo.value, channel.id, payType)
    handleResult(result)
  } catch (e: any) {
    err.value = e?.response?.data?.message || t('order.pay.payFailed')
  } finally {
    payingOptionKey.value = null
  }
}

function handleResult(result: { type: string; redirect_url?: string; qrcode_content?: string; form_html?: string }) {
  if (result.type === 'redirect' && result.redirect_url) {
    if (navigateToPaymentUrl(result.redirect_url)) return
  }
  if (result.type === 'qrcode' && result.qrcode_content) {
    qrcodeContent.value = result.qrcode_content
    startPolling()
    return
  }
  if (result.type === 'form' && result.form_html) {
    if (submitPaymentForm(result.form_html)) return
  }
  err.value = t('order.pay.payUnknown')
}

let pollTimer: ReturnType<typeof setInterval> | null = null
function startPolling() {
  if (pollTimer) return
  pollTimer = setInterval(async () => {
    try {
      const st = await getRechargeStatus(rechargeNo.value)
      if (st.status === 'paid') {
        stopPolling()
        router.replace({ name: 'recharge-result', query: { recharge_no: rechargeNo.value } })
      }
    } catch { /* 忽略,继续轮询 */ }
  }, 3000)
}
function stopPolling() {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null }
}
</script>

<template>
  <div class="max-w-md mx-auto px-4 py-12 text-center">
    <div class="bg-white rounded-card border border-border p-6">
      <h2 class="text-lg font-bold text-ink mb-1">{{ t('recharge.payTitle') }}</h2>
      <div v-if="amountFen" class="text-2xl font-extrabold text-price mb-2">
        {{ formatMoney(amountFen, prefs.baseCurrencyInfo) }}
      </div>
      <div class="text-xs text-ink-muted mb-4 font-mono break-all">{{ rechargeNo }}</div>

      <div v-if="loading" class="text-sm text-ink-soft py-6">{{ t('order.pay.loadingChannels') }}</div>

      <div v-else-if="err" class="text-danger text-xs mb-3">{{ err }}</div>

      <div v-if="!loading && paymentOptions.length" class="grid grid-cols-1 gap-2 mb-4">
        <button
          v-for="option in paymentOptions"
          :key="option.key"
          type="button"
          :disabled="payingOptionKey !== null"
          @click="selectChannel(option.channel, option.payType, option.key)"
          class="flex items-center gap-3 border border-border rounded-card px-3 py-3 text-left hover:border-primary hover:bg-primary-light transition disabled:opacity-50"
          :class="payingOptionKey === option.key ? 'border-primary ring-2 ring-primary/20' : ''"
        >
          <!-- 支付方式图标(按 pay_types 展开,与收银台一致) -->
          <span class="flex items-center gap-1 shrink-0">
            <template v-for="pt in option.types" :key="pt.type">
              <span class="w-8 h-8 rounded-lg bg-surface-subtle flex items-center justify-center text-lg">
                <PayBrandIcon :brand="pt.icon" :size="18" />
              </span>
            </template>
          </span>
          <span class="flex-1 min-w-0">
            <span class="block text-sm font-medium text-ink">
              {{ payingOptionKey === option.key ? t('order.pay.processing') : option.types.map((p) => p.label).join(' / ') }}
            </span>
            <span v-if="option.channel.target_currency" class="block text-[10px] font-normal text-ink-muted">
              {{ t('order.pay.receiveLabel', { symbol: currencySymbol(option.channel.target_currency), code: option.channel.target_currency }) }}
            </span>
            <!-- 客户承担手续费提示:点击前告知用户需另付手续费 -->
            <span v-if="option.channel.fee_bearer === 'customer' && Number(option.channel.fee ?? 0) > 0" class="block text-[10px] font-normal text-price">
              {{ feeTip(option.channel) }}
            </span>
          </span>
        </button>
      </div>

      <div v-if="!loading && !channels.length && !err" class="text-sm text-ink-soft py-4 mb-3">
        {{ t('order.pay.noChannels') }}
      </div>

      <div v-if="qrcodeContent" class="bg-orange-50 border border-orange-200 rounded-card p-3 mb-4 text-left">
        <div class="text-xs font-medium text-ink mb-1">{{ t('order.pay.qrcodeHint') }}</div>
        <div class="break-all text-xs text-ink-muted select-all bg-white rounded p-2">{{ qrcodeContent }}</div>
      </div>

      <button
        @click="router.push({ name: 'recharge' })"
        class="mt-2 text-xs text-ink-muted hover:text-primary transition"
      >
        ← {{ t('common.back') }}
      </button>

      <div :id="formContainerId" class="hidden"></div>
    </div>
  </div>
</template>
