<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { mockPay } from '@/api/orders'
import { getChannels, createPayment, type PaymentChannel } from '@/api/payments'

const route = useRoute()
const router = useRouter()
const orderNo = route.params.orderNo as string

const channels = ref<PaymentChannel[]>([])
const loading = ref(true)
const err = ref('')
const payingChannelId = ref<number | null>(null)
const mockPaying = ref(false)

// 二维码 / 表单弹层
const qrcodeContent = ref('')
const formContainerId = 'pay-form-mount'

onMounted(async () => {
  await loadChannels()
})

async function loadChannels() {
  loading.value = true
  err.value = ''
  try {
    channels.value = await getChannels()
  } catch (e: any) {
    err.value = e?.response?.data?.message || '支付通道加载失败'
  } finally {
    loading.value = false
  }
}

async function selectChannel(channel: PaymentChannel) {
  if (payingChannelId.value !== null) return
  err.value = ''
  payingChannelId.value = channel.id
  try {
    const result = await createPayment(orderNo, channel.id)
    handleResult(result)
  } catch (e: any) {
    err.value = e?.response?.data?.message || '发起支付失败'
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

  err.value = '未知的支付返回'
}

async function pay() {
  mockPaying.value = true
  err.value = ''
  try {
    const res = await mockPay(orderNo)
    if (res.delivered) {
      alert('支付成功!卡密已发货(演示模式)。请通过订单查询页查看卡密。')
      router.push('/orders/query')
    }
  } catch (e: any) {
    err.value = e?.response?.data?.message || '支付失败'
  } finally {
    mockPaying.value = false
  }
}
</script>

<template>
  <div class="max-w-md mx-auto px-4 py-12 text-center">
    <div class="bg-white rounded-card border border-gray-200 p-6">
      <h2 class="text-lg font-bold text-ink mb-2">订单待支付</h2>
      <div class="text-xs text-ink-muted mb-4">订单号:{{ orderNo }}</div>

      <!-- 加载中 -->
      <div v-if="loading" class="text-sm text-ink-soft py-6">正在加载支付通道...</div>

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
          class="flex items-center gap-2 border border-gray-200 rounded-card px-3 py-3 text-left hover:border-primary transition disabled:opacity-50"
          :class="payingChannelId === ch.id ? 'border-primary' : ''"
        >
          <span class="text-2xl leading-none">{{ ch.icon || '💳' }}</span>
          <span class="text-sm font-medium text-ink truncate">
            {{ payingChannelId === ch.id ? '处理中...' : ch.name }}
          </span>
        </button>
      </div>

      <div v-if="!loading && !channels.length && !err" class="text-sm text-ink-soft py-4 mb-3">
        暂无可用支付通道
      </div>

      <!-- 二维码展示 -->
      <div v-if="qrcodeContent" class="bg-orange-50 border border-orange-200 rounded-card p-3 mb-4 text-left">
        <div class="text-xs font-medium text-ink mb-1">请使用手机扫码支付</div>
        <div class="break-all text-xs text-ink-muted select-all bg-white rounded p-2">{{ qrcodeContent }}</div>
      </div>

      <!-- 模拟支付 -->
      <div class="mt-4 border-t border-gray-100 pt-4">
        <div class="text-xs text-ink-muted mb-2">(演示模式:无需真实通道也可完成下单流程)</div>
        <button
          @click="pay"
          :disabled="mockPaying"
          class="w-full bg-gradient-to-br from-primary to-blue-500 text-white font-bold py-3 rounded-card shadow-md disabled:opacity-50"
        >
          {{ mockPaying ? '支付中...' : '模拟支付' }}
        </button>
      </div>

      <!-- 第三方自动提交表单挂载点(不可见) -->
      <div :id="formContainerId" class="hidden"></div>
    </div>
  </div>
</template>
