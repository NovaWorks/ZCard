<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { usePreferencesStore } from '@/stores/preferences'
import { formatMoney } from '@/utils/money'
import { createRecharge, getRechargeHistory, type RechargeRecord } from '@/api/recharge'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const prefs = usePreferencesStore()

// 快捷金额(元)
const QUICK_AMOUNTS = [10, 20, 50, 100, 200, 500]

const selectedAmount = ref<number>(100)
const customAmount = ref('')
const isCustom = ref(false)
const submitting = ref(false)
const err = ref('')
const target = ref<'balance' | 'supply'>(route.query.target === 'supply' ? 'supply' : 'balance')

const history = ref<RechargeRecord[]>([])
const loadingHistory = ref(true)

const balance = computed(() => auth.user?.balance ?? 0)

// 当前生效金额(元):自定义模式取输入,否则取快捷选项
const effectiveAmount = computed(() => {
  if (isCustom.value) {
    const n = Number(customAmount.value)
    return isNaN(n) ? 0 : n
  }
  return selectedAmount.value
})

function selectQuick(n: number) {
  isCustom.value = false
  selectedAmount.value = n
}

function selectCustom() {
  isCustom.value = true
}

async function submit() {
  err.value = ''
  const amount = effectiveAmount.value
  if (!amount || amount <= 0) {
    err.value = t('recharge.amountInvalid')
    return
  }
  if (amount < 0.01) {
    err.value = t('recharge.amountInvalid')
    return
  }
  submitting.value = true
  try {
    const res = await createRecharge(amount, target.value)
    // 跳转到充值支付页(选通道 + 付款)
    router.push({ name: 'recharge-pay', params: { rechargeNo: res.recharge_no } })
  } catch (e: any) {
    err.value = e?.response?.data?.message || e?.message || t('recharge.createFailed')
  } finally {
    submitting.value = false
  }
}

const statusText = (s: string) =>
  ({
    pending: t('recharge.statusPending'),
    paid: t('recharge.statusPaid'),
    closed: t('recharge.statusClosed'),
  }[s] || s)

const statusClass = (s: string) =>
  ({
    pending: 'bg-amber-100 text-amber-700',
    paid: 'bg-green-100 text-green-700',
    closed: 'bg-gray-100 text-gray-500',
  }[s] || 'bg-gray-100 text-gray-500')

async function loadHistory() {
  loadingHistory.value = true
  try {
    history.value = await getRechargeHistory()
  } catch {
    history.value = []
  } finally {
    loadingHistory.value = false
  }
}

onMounted(() => {
  prefs.load()
  void loadHistory()
})
</script>

<template>
  <div class="max-w-3xl mx-auto px-4 py-6">
    <h1 class="text-xl font-bold text-ink mb-1">{{ t('recharge.title') }}</h1>
    <p class="text-xs text-ink-muted mb-4">
      {{ t('recharge.currentBalance') }}:
      <span class="text-price font-bold">{{ formatMoney(balance, prefs.baseCurrencyInfo) }}</span>
    </p>

    <!-- 充值卡 -->
    <div class="bg-white rounded-card border border-border p-4 mb-4">
      <!-- 充值目标(大厂风格:双卡片切换) -->
      <div class="text-sm font-semibold text-ink mb-3">{{ t('recharge.selectTarget') }}</div>
      <div class="grid grid-cols-2 gap-3 mb-4">
        <button
          type="button"
          @click="target = 'balance'"
          class="rounded-card border p-3 text-left transition"
          :class="target === 'balance'
            ? 'border-primary ring-2 ring-primary/20 bg-primary-light'
            : 'border-border hover:border-primary'"
        >
          <div class="text-2xl">💳</div>
          <div class="text-sm font-semibold text-ink mt-1">{{ t('recharge.targetBalance') }}</div>
          <div class="text-[11px] text-ink-muted mt-0.5">{{ t('recharge.targetBalanceTip') }}</div>
        </button>
        <button
          type="button"
          @click="target = 'supply'"
          class="rounded-card border p-3 text-left transition"
          :class="target === 'supply'
            ? 'border-primary ring-2 ring-primary/20 bg-primary-light'
            : 'border-border hover:border-primary'"
        >
          <div class="text-2xl">🔗</div>
          <div class="text-sm font-semibold text-ink mt-1">{{ t('recharge.targetSupply') }}</div>
          <div class="text-[11px] text-ink-muted mt-0.5">{{ t('recharge.targetSupplyTip') }}</div>
        </button>
      </div>

      <div class="text-sm font-semibold text-ink mb-3">{{ t('recharge.selectAmount') }}</div>

      <!-- 快捷金额 -->
      <div class="grid grid-cols-3 gap-2 mb-3">
        <button
          v-for="n in QUICK_AMOUNTS"
          :key="n"
          type="button"
          @click="selectQuick(n)"
          class="border rounded-card py-3 text-center font-bold transition"
          :class="!isCustom && selectedAmount === n
            ? 'border-primary ring-2 ring-primary/20 bg-primary-light text-primary'
            : 'border-border text-ink hover:border-primary'"
        >
          {{ n }}
        </button>
      </div>

      <!-- 自定义金额(文字描述在前,编辑框在后;整行点击聚焦输入框) -->
      <label
        class="w-full border rounded-card px-3 py-2 text-left text-sm transition flex items-center gap-2 cursor-text"
        :class="isCustom ? 'border-primary ring-2 ring-primary/20 bg-primary-light' : 'border-border hover:border-primary'"
      >
        <span class="text-ink-muted shrink-0">{{ t('recharge.customAmount') }}</span>
        <input
          v-model="customAmount"
          type="number"
          step="0.01"
          min="0.01"
          :placeholder="t('recharge.customPlaceholder')"
          @focus="selectCustom"
          class="flex-1 min-w-0 bg-transparent outline-none text-ink font-semibold text-right"
        />
      </label>

      <!-- 错误 -->
      <div v-if="err" class="mt-3 text-xs text-danger">{{ err }}</div>

      <!-- 提示金额 -->
      <div class="mt-4 flex items-center justify-between border-t border-border pt-3">
        <span class="text-xs text-ink-muted">{{ t('recharge.willRecharge') }}</span>
        <span class="text-lg font-extrabold text-price">
          {{ formatMoney(Math.round(effectiveAmount * 100), prefs.baseCurrencyInfo) }}
        </span>
      </div>

      <button
        @click="submit"
        :disabled="submitting || effectiveAmount <= 0"
        class="mt-3 w-full bg-gradient-to-r from-primary to-primary-hover text-white font-bold py-3 rounded-card shadow-md hover:shadow-pop disabled:opacity-50 transition"
      >
        {{ submitting ? t('common.loading') : t('recharge.submit') }}
      </button>
    </div>

    <!-- 充值记录 -->
    <div class="bg-white rounded-card border border-border p-4">
      <div class="text-sm font-semibold text-ink mb-3">{{ t('recharge.history') }}</div>

      <div v-if="loadingHistory" class="text-center text-ink-muted py-6">{{ t('common.loading') }}</div>
      <div v-else-if="!history.length" class="text-center text-ink-muted text-xs py-6">
        {{ t('recharge.historyEmpty') }}
      </div>

      <table v-else class="w-full text-xs">
        <thead>
          <tr class="text-ink-muted border-b border-border">
            <th class="text-left font-medium py-2">{{ t('recharge.colAmount') }}</th>
            <th class="text-left font-medium py-2">{{ t('recharge.colNo') }}</th>
            <th class="text-left font-medium py-2">{{ t('recharge.colStatus') }}</th>
            <th class="text-left font-medium py-2">{{ t('recharge.colDate') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="h in history" :key="h.id" class="border-b border-border/60">
            <td class="py-2 text-price font-semibold">
              {{ formatMoney(h.amount, prefs.baseCurrencyInfo) }}
            </td>
            <td class="py-2 text-ink-muted font-mono">{{ h.recharge_no }}</td>
            <td class="py-2">
              <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-pill" :class="statusClass(h.status)">
                {{ statusText(h.status) }}
              </span>
            </td>
            <td class="py-2 text-ink-muted">{{ (h.created_at || '').slice(0, 16).replace('T', ' ') }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
