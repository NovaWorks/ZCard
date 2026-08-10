<script setup lang="ts">
  import { computed } from 'vue'

  /**
   * 支付品牌图标:官方品牌色 SVG(支付宝蓝/微信绿/PayPal/Stripe/USDT 等)。
   * 传入品牌 key(与后端驱动 getInfo()['icon'] 一致)渲染对应徽标;
   * 兼容 http(s) 图片 URL(直接 img)。
   */
  const props = withDefaults(defineProps<{ brand?: string; size?: number }>(), {
    size: 24
  })

  const BRANDS: Record<string, { bg: string; text: string; label: string }> = {
    alipay: { bg: '#1677FF', text: '#ffffff', label: '支' },
    wechat: { bg: '#07C160', text: '#ffffff', label: '微' },
    wxpay: { bg: '#07C160', text: '#ffffff', label: '微' },
    qqpay: { bg: '#12B7F5', text: '#ffffff', label: 'Q' },
    bank: { bg: '#3B7CD3', text: '#ffffff', label: '银' },
    jdpay: { bg: '#E1251B', text: '#ffffff', label: '东' },
    paypal: { bg: '#003087', text: '#ffffff', label: 'P' },
    stripe: { bg: '#635BFF', text: '#ffffff', label: 'S' },
    usdt: { bg: '#26A17B', text: '#ffffff', label: '₮' },
    tron: { bg: '#26A17B', text: '#ffffff', label: '₮' },
    trx: { bg: '#26A17B', text: '#ffffff', label: '₮' },
    epay: { bg: '#FF6A00', text: '#ffffff', label: '易' },
    codepay: { bg: '#FF6A00', text: '#ffffff', label: '码' },
    epusdt: { bg: '#26A17B', text: '#ffffff', label: 'E' },
    bepusdt: { bg: '#26A17B', text: '#ffffff', label: 'B' },
    okpay: { bg: '#CC9900', text: '#ffffff', label: 'O' },
    tokenpay: { bg: '#0E90D2', text: '#ffffff', label: 'T' },
    balance: { bg: '#2563eb', text: '#ffffff', label: '¥' }
  }

  const isUrl = computed(() => /^https?:\/\//i.test(props.brand || ''))
  const meta = computed(() => BRANDS[props.brand || ''] || null)
</script>

<template>
  <img
    v-if="isUrl"
    :src="brand"
    :alt="brand"
    :style="{ width: size + 'px', height: size + 'px' }"
    class="object-cover rounded-full"
  />
  <svg v-else-if="meta" :width="size" :height="size" viewBox="0 0 48 48" :aria-label="brand">
    <rect width="48" height="48" rx="10" :fill="meta.bg" />
    <text
      x="24"
      y="31"
      text-anchor="middle"
      :fill="meta.text"
      font-size="22"
      font-weight="700"
      font-family="-apple-system, 'PingFang SC', 'Microsoft YaHei', sans-serif"
    >{{ meta.label }}</text>
  </svg>
  <span
    v-else
    class="inline-flex items-center justify-center rounded-full"
    :style="{ width: size + 'px', height: size + 'px', background: 'var(--el-fill-color)' }"
  >💳</span>
</template>
