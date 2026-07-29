<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { mockPay } from '@/api/orders'

const route = useRoute()
const router = useRouter()
const orderNo = route.params.orderNo as string
const paying = ref(false)
const err = ref('')

async function pay() {
  paying.value = true
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
    paying.value = false
  }
}
</script>

<template>
  <div class="max-w-md mx-auto px-4 py-12 text-center">
    <div class="bg-white rounded-card border border-gray-200 p-6">
      <h2 class="text-lg font-bold text-ink mb-2">订单待支付</h2>
      <div class="text-xs text-ink-muted mb-4">订单号:{{ orderNo }}</div>
      <div class="bg-orange-50 border border-orange-200 rounded-card p-3 mb-4 text-xs text-orange-700">
        (P1-C 演示模式:点击下方按钮模拟支付成功,P1-D 将接入真实支付通道)
      </div>
      <button @click="pay" :disabled="paying"
        class="w-full bg-gradient-to-br from-primary to-blue-500 text-white font-bold py-3 rounded-card shadow-md disabled:opacity-50">
        {{ paying ? '支付中...' : '模拟支付' }}
      </button>
      <div v-if="err" class="text-danger text-xs mt-3">{{ err }}</div>
    </div>
  </div>
</template>
