<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { getStats, getReferrals, getCommissions, type DistributionStats, type Referral, type CommissionRecord } from '@/api/distribution'
import { formatMoney } from '@/utils/money'
import { usePreferencesStore } from '@/stores/preferences'

const router = useRouter()
const { t } = useI18n()
const prefs = usePreferencesStore()

const stats = ref<DistributionStats | null>(null)
const referrals = ref<Referral[]>([])
const commissions = ref<CommissionRecord[]>([])
const loading = ref(true)
const err = ref('')
const copied = ref(false)

const fmtDate = (d?: string) => d || ''

function copyLink() {
  const link = stats.value?.referral_link || ''
  if (!link) return
  navigator.clipboard.writeText(link).then(() => {
    copied.value = true
    setTimeout(() => (copied.value = false), 1500)
  })
}

function goWithdraw() {
  router.push('/withdraw')
}

onMounted(async () => {
  // 直达子页时 prefs 可能尚未加载 → 先触发加载以保证 formatMoney 正确展示
  prefs.load()
  try {
    const [s, r, c] = await Promise.all([
      getStats(),
      getReferrals(),
      getCommissions(),
    ])
    stats.value = s
    referrals.value = r
    commissions.value = c
  } catch (e: any) {
    err.value = e?.response?.data?.message || t('distribution.empty')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="max-w-3xl mx-auto px-4 py-6">
    <h1 class="text-xl font-bold text-ink mb-4">{{ t('distribution.title') }}</h1>

    <div v-if="loading" class="text-center text-ink-muted py-20">{{ t('product.detail.loading') }}</div>
    <div v-else-if="err" class="text-center text-danger py-20">{{ err }}</div>

    <template v-else>
      <!-- 推广链接 -->
      <div class="bg-white rounded-card border border-border p-4 mb-4">
        <div class="text-sm font-semibold text-ink mb-2">{{ t('distribution.referralLink') }}</div>
        <div class="flex items-center gap-2">
          <input :value="stats?.referral_link || ''" readonly
            class="flex-1 px-3 py-2 border border-border rounded-field text-xs bg-surface-subtle outline-none" />
          <button @click="copyLink"
            class="shrink-0 px-3 py-2 rounded-field bg-primary text-white text-xs font-semibold hover:bg-primary-hover transition">
            {{ copied ? t('distribution.copied') : t('distribution.copy') }}
          </button>
        </div>
      </div>

      <!-- 统计卡片 -->
      <div class="grid grid-cols-2 gap-3 mb-4">
        <div class="bg-white rounded-card border border-border p-4">
          <div class="text-xs text-ink-muted">{{ t('distribution.totalCommission') }}</div>
          <div class="text-price font-bold mt-1">{{ formatMoney(stats?.total_commission ?? 0, prefs.baseCurrencyInfo) }}</div>
        </div>
        <div class="bg-white rounded-card border border-border p-4">
          <div class="text-xs text-ink-muted">{{ t('distribution.availableCommission') }}</div>
          <div class="text-price font-bold mt-1">{{ formatMoney(stats?.available_commission ?? 0, prefs.baseCurrencyInfo) }}</div>
          <button @click="goWithdraw"
            class="mt-2 px-3 py-1 rounded-field bg-primary text-white text-xs font-semibold hover:bg-primary-hover transition">
            {{ t('nav.withdraw') }}
          </button>
        </div>
        <div class="bg-white rounded-card border border-border p-4">
          <div class="text-xs text-ink-muted">{{ t('distribution.balance') }}</div>
          <div class="text-price font-bold mt-1">{{ formatMoney(stats?.balance ?? 0, prefs.baseCurrencyInfo) }}</div>
        </div>
        <div class="bg-white rounded-card border border-border p-4">
          <div class="text-xs text-ink-muted">{{ t('distribution.referralCount') }}</div>
          <div class="text-ink font-bold mt-1">{{ stats?.referral_count ?? 0 }}</div>
        </div>
      </div>

      <!-- 我的下级 -->
      <div class="bg-white rounded-card border border-border p-4 mb-4">
        <div class="text-sm font-semibold text-ink mb-3">{{ t('distribution.myReferrals') }}</div>
        <div v-if="!referrals.length" class="text-center text-ink-muted text-xs py-6">{{ t('distribution.empty') }}</div>
        <table v-else class="w-full text-xs">
          <thead>
            <tr class="text-ink-muted border-b border-border">
              <th class="text-left font-medium py-2">{{ t('distribution.buyer') }}</th>
              <th class="text-right font-medium py-2">{{ t('distribution.date') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in referrals" :key="r.id" class="border-b border-border/60">
              <td class="py-2 text-ink">{{ r.username }}</td>
              <td class="py-2 text-right text-ink-muted">{{ fmtDate(r.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- 佣金明细 -->
      <div class="bg-white rounded-card border border-border p-4">
        <div class="text-sm font-semibold text-ink mb-3">{{ t('distribution.commissions') }}</div>
        <div v-if="!commissions.length" class="text-center text-ink-muted text-xs py-6">{{ t('distribution.empty') }}</div>
        <div v-else class="space-y-3">
          <div v-for="c in commissions" :key="c.id" class="border-b border-border/60 pb-2 last:border-0">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-pill bg-primary-light text-primary">T{{ c.tier }}</span>
                <span class="text-ink-muted">{{ c.order?.order_no || '-' }}</span>
              </div>
              <span class="text-price font-bold">{{ formatMoney(c.amount, prefs.baseCurrencyInfo) }}</span>
            </div>
            <div class="flex items-center justify-between mt-1 text-[11px] text-ink-muted">
              <span>{{ c.buyer?.username || '-' }}</span>
              <span>{{ t('distribution.rate') }} {{ c.rate }}% · {{ fmtDate(c.created_at) }}</span>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
