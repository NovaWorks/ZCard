<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { getChannels, type PaymentChannel } from '@/api/payments'
import { createRechargePayment, getRechargeStatus } from '@/api/recharge'
import { formatMoney } from '@/utils/money'
import { usePreferencesStore } from '@/stores/preferences'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const prefs = usePreferencesStore()

const rechargeNo = computed(() => route.params.rechargeNo as string)

const channels = ref<PaymentChannel[]>([])
const loading = ref(true)
const err = ref('')
const payingChannelId = ref<number | null>(null)
const amountFen = ref(0)

const qrcodeContent = ref('')
const formContainerId = 'recharge-form-mount'

function currencySymbol(code?: string | null): string {
  if (!code) return ''
  return prefs.currencies.find((c) => c.code === code)?.symbol || ''
}

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
    const result = await createRechargePayment(rechargeNo.value, channel.id)
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
    startPolling()
    return
  }
  if (result.type === 'form' && result.form_html) {
    const container = document.getElementById(formContainerId)
    if (container) {
      container.innerHTML = result.form_html
      const form = container.querySelector('form')
      if (form) form.submit()
    }
    return
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
          <span class="text-2xl leading-none shrink-0">{{ ch.icon || '💳' }}</span>
          <span class="text-sm font-medium text-ink leading-tight">
            <span class="block">{{ payingChannelId === ch.id ? t('order.pay.processing') : ch.name }}</span>
            <span v-if="ch.target_currency" class="block text-[10px] font-normal text-ink-muted">
              {{ t('order.pay.receiveLabel', { symbol: currencySymbol(ch.target_currency), code: ch.target_currency }) }}
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
