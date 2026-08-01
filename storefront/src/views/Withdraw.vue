<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  requestWithdrawal,
  getWithdrawalHistory,
  type WithdrawalRecord,
} from '@/api/withdrawal'
import { formatMoney } from '@/utils/money'
import { usePreferencesStore } from '@/stores/preferences'
import { useSettingsStore } from '@/stores/settings'

const { t } = useI18n()
const prefs = usePreferencesStore()
const settings = useSettingsStore()

const history = ref<WithdrawalRecord[]>([])
const loading = ref(true)
const submitting = ref(false)
const err = ref('')
const msg = ref('')

// 表单(amount 单位为元)
const form = ref({
  amount: '' as number | string,
  method: '',
  account: '',
  account_name: '',
})

// 可用提现方式(根据后台开关 cash_type_*)
const methods = computed(() => {
  const cfg: any = settings.config
  const list: { value: string; label: string }[] = []
  if (cfg?.cash_type_alipay) list.push({ value: 'alipay', label: t('withdraw.alipay') })
  if (cfg?.cash_type_wechat) list.push({ value: 'wechat', label: t('withdraw.wechat') })
  if (cfg?.cash_type_usdt) list.push({ value: 'usdt', label: t('withdraw.usdt') })
  return list
})

// 最低提现金额(元,后台 cash_min 默认 100)
const minAmount = computed(() => {
  const m = Number((settings.config as any)?.cash_min)
  return m > 0 ? m : 0
})

const statusText = (s: string) => {
  const map: Record<string, string> = {
    pending: t('common.orderStatus.pending'),
    approved: t('common.success'),
    rejected: t('common.error'),
  }
  return map[s] || s
}

const statusClass = (s: string) =>
  ({
    pending: 'bg-orange-100 text-orange-700',
    approved: 'bg-green-100 text-green-700',
    rejected: 'bg-red-100 text-red-700',
  }[s] || 'bg-gray-100 text-gray-600')

const fmtDate = (d?: string) => d || ''

async function loadHistory() {
  try {
    history.value = await getWithdrawalHistory()
  } catch (e: any) {
    err.value = e?.response?.data?.message || t('distribution.empty')
  }
}

async function submit() {
  msg.value = ''
  err.value = ''
  const amount = Number(form.value.amount)
  if (!amount || amount <= 0) {
    err.value = t('withdraw.amount')
    return
  }
  if (!form.value.method) {
    err.value = t('withdraw.noMethods')
    return
  }
  if (!form.value.account || !form.value.account_name) {
    err.value = t('withdraw.account')
    return
  }
  submitting.value = true
  try {
    await requestWithdrawal({
      amount,
      method: form.value.method,
      account: form.value.account,
      account_name: form.value.account_name,
    })
    msg.value = t('withdraw.success')
    // 清空表单(保留方式选择便于再次提交)
    form.value.amount = ''
    form.value.account = ''
    form.value.account_name = ''
    await loadHistory()
  } catch (e: any) {
    err.value = e?.response?.data?.message || t('withdraw.submit')
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  // 直达子页时 prefs 可能未加载 → 触发加载以保证 formatMoney 正确
  prefs.load()
  settings.load()
  await loadHistory()
  loading.value = false
  // 默认选中第一个可用方式
  if (!form.value.method && methods.value.length) {
    form.value.method = methods.value[0].value
  }
})
</script>

<template>
  <div class="max-w-3xl mx-auto px-4 py-6">
    <h1 class="text-xl font-bold text-ink mb-4">{{ t('withdraw.title') }}</h1>

    <!-- 提现表单 -->
    <div class="bg-white rounded-card border border-border p-4 mb-4">
      <!-- 无可用方式 -->
      <div v-if="!methods.length" class="text-center text-ink-muted text-xs py-6">
        {{ t('withdraw.noMethods') }}
      </div>

      <div v-else class="space-y-3">
        <!-- 金额 -->
        <div>
          <label class="block text-xs font-medium text-ink-muted mb-1">{{ t('withdraw.amount') }}</label>
          <input
            v-model="form.amount"
            type="number"
            step="0.01"
            min="0"
            class="w-full px-3 py-2 border border-border rounded-field text-sm bg-white outline-none focus:border-primary"
            :placeholder="t('withdraw.amount')"
          />
          <div v-if="minAmount" class="text-[11px] text-ink-muted mt-1">
            {{ t('withdraw.minAmountTip') }}: {{ minAmount }}
          </div>
        </div>

        <!-- 方式 -->
        <div>
          <label class="block text-xs font-medium text-ink-muted mb-1">{{ t('withdraw.method') }}</label>
          <select
            v-model="form.method"
            class="w-full px-3 py-2 border border-border rounded-field text-sm bg-white outline-none focus:border-primary"
          >
            <option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </div>

        <!-- 账号 -->
        <div>
          <label class="block text-xs font-medium text-ink-muted mb-1">{{ t('withdraw.account') }}</label>
          <input
            v-model="form.account"
            type="text"
            class="w-full px-3 py-2 border border-border rounded-field text-sm bg-white outline-none focus:border-primary"
            :placeholder="t('withdraw.account')"
          />
        </div>

        <!-- 户名 -->
        <div>
          <label class="block text-xs font-medium text-ink-muted mb-1">{{ t('withdraw.accountName') }}</label>
          <input
            v-model="form.account_name"
            type="text"
            class="w-full px-3 py-2 border border-border rounded-field text-sm bg-white outline-none focus:border-primary"
            :placeholder="t('withdraw.accountName')"
          />
        </div>

        <!-- 提示 -->
        <div v-if="msg" class="text-xs text-green-600">{{ msg }}</div>
        <div v-if="err" class="text-xs text-danger">{{ err }}</div>

        <button
          @click="submit"
          :disabled="submitting"
          class="w-full px-4 py-2 rounded-field bg-primary text-white text-sm font-semibold hover:bg-primary-hover transition disabled:opacity-60"
        >
          {{ submitting ? t('common.loading') : t('withdraw.submit') }}
        </button>
      </div>
    </div>

    <!-- 提现记录 -->
    <div class="bg-white rounded-card border border-border p-4">
      <div class="text-sm font-semibold text-ink mb-3">{{ t('withdraw.history') }}</div>

      <div v-if="loading" class="text-center text-ink-muted py-6">{{ t('product.detail.loading') }}</div>
      <div v-else-if="!history.length" class="text-center text-ink-muted text-xs py-6">
        {{ t('distribution.empty') }}
      </div>

      <table v-else class="w-full text-xs">
        <thead>
          <tr class="text-ink-muted border-b border-border">
            <th class="text-left font-medium py-2">{{ t('withdraw.amount') }}</th>
            <th class="text-left font-medium py-2">{{ t('withdraw.method') }}</th>
            <th class="text-left font-medium py-2">{{ t('withdraw.status') }}</th>
            <th class="text-left font-medium py-2">{{ t('withdraw.date') }}</th>
            <th v-if="history.some((h) => h.reject_reason)" class="text-left font-medium py-2">
              {{ t('withdraw.rejectReason') }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="h in history" :key="h.id" class="border-b border-border/60">
            <td class="py-2 text-price font-semibold">
              {{ formatMoney(h.amount, prefs.currentCurrency) }}
            </td>
            <td class="py-2 text-ink">
              {{ methods.find((m) => m.value === h.method)?.label || h.method }}
            </td>
            <td class="py-2">
              <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-pill" :class="statusClass(h.status)">
                {{ statusText(h.status) }}
              </span>
            </td>
            <td class="py-2 text-ink-muted">{{ fmtDate(h.created_at) }}</td>
            <td v-if="history.some((hh) => hh.reject_reason)" class="py-2 text-danger">
              {{ h.reject_reason || '-' }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
