<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { getRechargeStatus } from '@/api/recharge'
import { formatMoney } from '@/utils/money'
import { usePreferencesStore } from '@/stores/preferences'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const prefs = usePreferencesStore()
const auth = useAuthStore()

const rechargeNo = computed(() => (route.query.recharge_no as string) || '')
const isCancel = computed(() => route.query.status === 'cancel')
const loading = ref(true)
const paid = ref(false)
const amountFen = ref(0)

onMounted(async () => {
  prefs.load()

  if (isCancel.value) {
    loading.value = false
    return
  }
  if (!rechargeNo.value) {
    loading.value = false
    return
  }

  // 轮询充值状态(异步回调可能有延迟,最多查 6 次)
  for (let i = 0; i < 6; i++) {
    try {
      const st = await getRechargeStatus(rechargeNo.value)
      amountFen.value = st.amount
      if (st.status === 'paid') {
        paid.value = true
        // 支付成功:刷新用户余额(后端已入账)
        try { await auth.fetchUser?.() } catch { /* 忽略 */ }
        break
      }
    } catch { /* 忽略,继续轮询 */ }
    if (i < 5) await new Promise((r) => setTimeout(r, 1500))
  }
  loading.value = false
})
</script>

<template>
  <div class="max-w-md mx-auto px-4 py-12">
    <div class="bg-white rounded-card border border-border p-8 shadow-card text-center">
      <!-- 加载中 -->
      <template v-if="loading">
        <div class="w-16 h-16 mx-auto bg-primary-light rounded-full flex items-center justify-center text-3xl mb-4 animate-pulse">⏳</div>
        <h2 class="text-lg font-bold text-ink mb-1">{{ t('recharge.resultConfirming') }}</h2>
        <p class="text-xs text-ink-muted">{{ t('recharge.resultConfirmingHint') }}</p>
      </template>

      <!-- 取消 -->
      <template v-else-if="isCancel">
        <div class="w-16 h-16 mx-auto bg-gray-100 rounded-full flex items-center justify-center text-3xl mb-4">😕</div>
        <h2 class="text-lg font-bold text-ink mb-1">{{ t('recharge.resultCancelTitle') }}</h2>
        <p class="text-xs text-ink-muted mb-5">{{ t('recharge.resultCancelHint') }}</p>
        <div class="flex gap-2 justify-center">
          <button @click="router.push({ name: 'recharge' })" class="px-4 py-2 text-xs bg-primary text-white rounded-field hover:bg-primary-hover transition">{{ t('recharge.retry') }}</button>
          <button @click="router.push({ name: 'user-center' })" class="px-4 py-2 text-xs bg-surface-subtle text-ink-soft rounded-field hover:bg-border transition">{{ t('userCenter.title') }}</button>
        </div>
      </template>

      <!-- 成功 -->
      <template v-else-if="paid">
        <div class="w-16 h-16 mx-auto bg-green-50 rounded-full flex items-center justify-center text-3xl mb-4">✅</div>
        <h2 class="text-lg font-bold text-ink mb-1">{{ t('recharge.resultSuccessTitle') }}</h2>
        <p class="text-xs text-ink-muted mb-2 font-mono break-all">{{ rechargeNo }}</p>
        <div class="bg-surface-subtle rounded-field p-3 mb-5">
          <div class="flex justify-between text-xs">
            <span class="text-ink-muted">{{ t('recharge.colAmount') }}</span>
            <span class="text-price font-bold">{{ formatMoney(amountFen, prefs.baseCurrencyInfo) }}</span>
          </div>
        </div>
        <div class="flex gap-2">
          <button @click="router.push({ name: 'user-center' })" class="flex-1 bg-primary text-white text-sm font-semibold py-2.5 rounded-card hover:bg-primary-hover transition">{{ t('recharge.backToCenter') }}</button>
        </div>
      </template>

      <!-- 处理中 -->
      <template v-else>
        <div class="w-16 h-16 mx-auto bg-amber-50 rounded-full flex items-center justify-center text-3xl mb-4">⏰</div>
        <h2 class="text-lg font-bold text-ink mb-1">{{ t('recharge.resultProcessingTitle') }}</h2>
        <p class="text-xs text-ink-muted mb-5">{{ t('recharge.resultProcessingHint') }}</p>
        <div class="flex gap-2 justify-center">
          <button @click="router.push({ name: 'recharge' })" class="px-4 py-2 text-xs bg-primary text-white rounded-field hover:bg-primary-hover transition">{{ t('recharge.title') }}</button>
          <button @click="router.push({ name: 'user-center' })" class="px-4 py-2 text-xs bg-surface-subtle text-ink-soft rounded-field hover:bg-border transition">{{ t('userCenter.title') }}</button>
        </div>
      </template>
    </div>
  </div>
</template>
