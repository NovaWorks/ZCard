<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  getMySubsite,
  getSubsiteFinance,
  getSubsiteLedger,
  getSubsiteProductSettings,
  getSubsiteOrders,
  bindSubsiteDomain,
  upsertSubsiteProductSetting,
  requestSubsiteWithdrawal,
  updateSubsiteBranding,
  type SubsiteInfo,
  type SubsiteFinance,
  type SubsiteLedgerEntry,
  type SubsiteProductSetting,
} from '@/api/subsite'
import { formatMoney } from '@/utils/money'
import { usePreferencesStore } from '@/stores/preferences'
import { useSettingsStore } from '@/stores/settings'

defineOptions({ name: 'MySubsite' })

const { t } = useI18n()
const prefs = usePreferencesStore()
const settings = useSettingsStore()

const loading = ref(true)
const err = ref('')
const info = ref<SubsiteInfo | null>(null)
const finance = ref<SubsiteFinance | null>(null)
const ledger = ref<SubsiteLedgerEntry[]>([])
const orders = ref<any[]>([])
const products = ref<SubsiteProductSetting[]>([])

const hasSubsite = computed(() => !!info.value)

// 白标编辑
const branding = ref({ site_name: '', logo: '', announcement: '' })
const brandingSaving = ref(false)
const brandingMsg = ref('')

// 绑定域名
const domainForm = ref({ domain: '', type: 'subdomain' })
const domainMsg = ref('')

// 提现(内联)
const withdrawForm = ref({ amount: '' as number | string, method: '', account: '', account_name: '' })
const withdrawSaving = ref(false)
const withdrawMsg = ref('')
const withdrawErr = ref('')
const methods = computed(() => {
  const cfg: any = settings.config
  const list: { value: string; label: string }[] = []
  if (cfg?.cash_type_alipay) list.push({ value: 'alipay', label: t('withdraw.alipay') })
  if (cfg?.cash_type_wechat) list.push({ value: 'wechat', label: t('withdraw.wechat') })
  if (cfg?.cash_type_usdt) list.push({ value: 'usdt', label: t('withdraw.usdt') })
  return list
})

const fmtDate = (d?: string | null) => d || ''

const domainStatusText = (d: { status: string; verification_status: string }) => {
  if (d.status === 'active' && d.verification_status === 'verified') return t('subsite.verified')
  if (d.status === 'disabled') return t('subsite.disabled')
  return t('subsite.pending')
}

const ledgerStatusText = (s: string) => {
  const map: Record<string, string> = {
    pending: t('subsite.pending'),
    available: t('subsite.available'),
    withdrawn: t('subsite.withdrawn'),
  }
  return map[s] || s
}

const orderStatusText = (s: string) => {
  const map: Record<string, string> = {
    pending: t('subsite.orderPending'),
    paid: t('subsite.orderPaid'),
    completed: t('subsite.orderCompleted'),
    cancelled: t('subsite.orderCancelled'),
    refunded: t('subsite.orderRefunded'),
  }
  return map[s] || s || '-'
}

const pricingModeLabel = (mode: string) => {
  if (mode === 'percent') return t('subsite.modePercent')
  if (mode === 'fixed_markup') return t('subsite.modeFixedMarkup')
  if (mode === 'fixed_price') return t('subsite.modeFixedPrice')
  return mode || '-'
}

async function loadAll() {
  loading.value = true
  err.value = ''
  try {
    // info 是核心,失败(404)即视为无分站
    let myInfo: SubsiteInfo | null = null
    try {
      myInfo = await getMySubsite()
    } catch {
      myInfo = null
    }
    info.value = myInfo
    if (myInfo) {
      branding.value = {
        site_name: myInfo.settings?.site_name || myInfo.name || '',
        logo: myInfo.settings?.logo || '',
        announcement: myInfo.settings?.announcement || '',
      }
      const [f, l, p, o] = await Promise.all([
        getSubsiteFinance().catch(() => null),
        getSubsiteLedger().catch(() => []),
        getSubsiteProductSettings().catch(() => []),
        getSubsiteOrders().catch(() => []),
      ])
      finance.value = f
      ledger.value = Array.isArray(l) ? l : []
      products.value = Array.isArray(p) ? p : []
      orders.value = Array.isArray(o) ? o : []
    }
  } catch (e: any) {
    err.value = e?.response?.data?.message || t('subsite.loadFailed')
  } finally {
    loading.value = false
  }
}

async function saveBranding() {
  brandingSaving.value = true
  brandingMsg.value = ''
  try {
    await updateSubsiteBranding({
      site_name: branding.value.site_name,
      logo: branding.value.logo,
      announcement: branding.value.announcement,
    })
    brandingMsg.value = t('subsite.brandingSaved')
  } catch (e: any) {
    brandingMsg.value = e?.response?.data?.message || t('subsite.brandingFailed')
  } finally {
    brandingSaving.value = false
  }
}

async function saveDomain() {
  domainMsg.value = ''
  if (!domainForm.value.domain.trim()) {
    domainMsg.value = t('subsite.domainRequired')
    return
  }
  try {
    await bindSubsiteDomain({ domain: domainForm.value.domain.trim(), type: domainForm.value.type })
    domainForm.value.domain = ''
    domainMsg.value = t('subsite.domainBound')
    await loadAll()
  } catch (e: any) {
    domainMsg.value = e?.response?.data?.message || t('subsite.bindFailed')
  }
}

async function toggleListed(row: SubsiteProductSetting) {
  try {
    await upsertSubsiteProductSetting({
      product_id: row.product_id,
      is_listed: !row.is_listed,
      pricing_mode: row.pricing_mode,
      markup_percent: Number(row.markup_percent),
    })
    row.is_listed = !row.is_listed
  } catch {
    // 失败回滚由 UI 反映
  }
}

async function saveProduct(row: SubsiteProductSetting) {
  try {
    await upsertSubsiteProductSetting({
      product_id: row.product_id,
      is_listed: row.is_listed,
      pricing_mode: row.pricing_mode,
      markup_percent: Number(row.markup_percent),
    })
  } catch {
    // 静默;后端错误已由拦截器提示
  }
}

async function submitWithdraw() {
  withdrawErr.value = ''
  withdrawMsg.value = ''
  const amount = Number(withdrawForm.value.amount)
  if (!amount || amount <= 0) {
    withdrawErr.value = t('subsite.amountRequired')
    return
  }
  if (!withdrawForm.value.method || !withdrawForm.value.account || !withdrawForm.value.account_name) {
    withdrawErr.value = t('subsite.fillAll')
    return
  }
  withdrawSaving.value = true
  try {
    await requestSubsiteWithdrawal({
      amount,
      method: withdrawForm.value.method,
      account: withdrawForm.value.account,
      account_name: withdrawForm.value.account_name,
    })
    withdrawMsg.value = t('subsite.withdrawSuccess')
    withdrawForm.value.amount = ''
    withdrawForm.value.account = ''
    withdrawForm.value.account_name = ''
    await getSubsiteFinance().then((f) => (finance.value = f)).catch(() => {})
  } catch (e: any) {
    withdrawErr.value = e?.response?.data?.message || t('subsite.withdrawFailed')
  } finally {
    withdrawSaving.value = false
  }
}

onMounted(async () => {
  prefs.load()
  settings.load()
  await loadAll()
  if (!withdrawForm.value.method && methods.value.length) {
    withdrawForm.value.method = methods.value[0].value
  }
})
</script>

<template>
  <div class="max-w-3xl mx-auto px-4 py-6">
    <h1 class="text-xl font-bold text-ink mb-4">{{ t('subsite.title') }}</h1>

    <div v-if="loading" class="text-center text-ink-muted py-20">{{ t('product.detail.loading') }}</div>
    <div v-else-if="err" class="text-center text-danger py-20">{{ err }}</div>

    <!-- 没有分站 -->
    <div v-else-if="!hasSubsite" class="bg-white rounded-card border border-border p-8 text-center">
      <div class="text-base text-ink mb-2">{{ t('subsite.noSubsite') }}</div>
      <div class="text-xs text-ink-muted">{{ t('subsite.contactAdmin') }}</div>
    </div>

    <template v-else>
      <!-- 店铺信息 + 白标编辑 -->
      <div class="bg-white rounded-card border border-border p-4 mb-4">
        <div class="text-sm font-semibold text-ink mb-3">{{ t('subsite.siteInfo') }}</div>
        <div class="space-y-3">
          <div>
            <label class="block text-xs font-medium text-ink-muted mb-1">{{ t('subsite.siteName') }}</label>
            <input
              v-model="branding.site_name"
              type="text"
              class="w-full px-3 py-2 border border-border rounded-field text-sm bg-white outline-none focus:border-primary"
              :placeholder="t('subsite.siteName')"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-muted mb-1">{{ t('subsite.logo') }}</label>
            <input
              v-model="branding.logo"
              type="text"
              class="w-full px-3 py-2 border border-border rounded-field text-sm bg-white outline-none focus:border-primary"
              :placeholder="t('subsite.logoPlaceholder')"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-ink-muted mb-1">{{ t('subsite.announcement') }}</label>
            <textarea
              v-model="branding.announcement"
              rows="2"
              class="w-full px-3 py-2 border border-border rounded-field text-sm bg-white outline-none focus:border-primary"
              :placeholder="t('subsite.announcement')"
            />
          </div>
          <div class="flex items-center gap-3">
            <button
              @click="saveBranding"
              :disabled="brandingSaving"
              class="px-4 py-2 rounded-field bg-primary text-white text-sm font-semibold hover:bg-primary-hover transition disabled:opacity-60"
            >
              {{ brandingSaving ? t('common.loading') : t('common.submit') }}
            </button>
            <span v-if="brandingMsg" class="text-xs text-green-600">{{ brandingMsg }}</span>
          </div>
        </div>

        <!-- 域名列表 -->
        <div class="mt-4 border-t border-border pt-3">
          <div class="text-xs font-medium text-ink-muted mb-2">{{ t('subsite.domains') }}</div>
          <div v-if="!info?.domains?.length" class="text-xs text-ink-muted">{{ t('subsite.noDomain') }}</div>
          <div v-else class="space-y-1">
            <div v-for="d in info.domains" :key="d.id" class="flex items-center justify-between text-xs">
              <span class="text-ink font-mono">{{ d.domain }}</span>
              <span class="text-[10px] px-1.5 py-0.5 rounded-pill bg-surface-subtle text-ink-muted">
                {{ domainStatusText(d) }}
              </span>
            </div>
          </div>

          <!-- 绑定域名 -->
          <div class="mt-3 flex items-center gap-2 flex-wrap">
            <input
              v-model="domainForm.domain"
              type="text"
              class="flex-1 min-w-[160px] px-3 py-2 border border-border rounded-field text-sm bg-white outline-none focus:border-primary"
              :placeholder="t('subsite.domainPlaceholder')"
            />
            <select
              v-model="domainForm.type"
              class="px-2 py-2 border border-border rounded-field text-xs text-ink-soft bg-white"
            >
              <option value="subdomain">{{ t('subsite.subdomain') }}</option>
              <option value="custom">{{ t('subsite.custom') }}</option>
            </select>
            <button
              @click="saveDomain"
              class="shrink-0 px-3 py-2 rounded-field bg-primary text-white text-xs font-semibold hover:bg-primary-hover transition"
            >
              {{ t('subsite.bindDomain') }}
            </button>
          </div>
          <div v-if="domainMsg" class="text-xs text-green-600 mt-1">{{ domainMsg }}</div>
        </div>
      </div>

      <!-- 财务统计 -->
      <div class="grid grid-cols-3 gap-3 mb-4">
        <div class="bg-white rounded-card border border-border p-4">
          <div class="text-xs text-ink-muted">{{ t('subsite.totalProfit') }}</div>
          <div class="text-price font-bold mt-1 text-sm">{{ formatMoney(finance?.total_profit ?? 0, prefs.baseCurrencyInfo) }}</div>
        </div>
        <div class="bg-white rounded-card border border-border p-4">
          <div class="text-xs text-ink-muted">{{ t('subsite.available') }}</div>
          <div class="text-price font-bold mt-1 text-sm">{{ formatMoney(finance?.available ?? 0, prefs.baseCurrencyInfo) }}</div>
        </div>
        <div class="bg-white rounded-card border border-border p-4">
          <div class="text-xs text-ink-muted">{{ t('subsite.pending') }}</div>
          <div class="text-ink font-bold mt-1 text-sm">{{ formatMoney(finance?.pending ?? 0, prefs.baseCurrencyInfo) }}</div>
        </div>
      </div>

      <!-- 提现表单 -->
      <div class="bg-white rounded-card border border-border p-4 mb-4">
        <div class="text-sm font-semibold text-ink mb-3">{{ t('subsite.withdraw') }}</div>
        <div v-if="!methods.length" class="text-center text-ink-muted text-xs py-3">{{ t('subsite.noMethods') }}</div>
        <div v-else class="space-y-2">
          <div class="grid grid-cols-2 gap-2">
            <input
              v-model="withdrawForm.amount"
              type="number"
              step="0.01"
              min="0"
              class="px-3 py-2 border border-border rounded-field text-sm bg-white outline-none focus:border-primary"
              :placeholder="t('subsite.amountPlaceholder')"
            />
            <select
              v-model="withdrawForm.method"
              class="px-3 py-2 border border-border rounded-field text-sm bg-white outline-none"
            >
              <option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
          </div>
          <input
            v-model="withdrawForm.account"
            type="text"
            class="w-full px-3 py-2 border border-border rounded-field text-sm bg-white outline-none focus:border-primary"
            :placeholder="t('subsite.account')"
          />
          <input
            v-model="withdrawForm.account_name"
            type="text"
            class="w-full px-3 py-2 border border-border rounded-field text-sm bg-white outline-none focus:border-primary"
            :placeholder="t('subsite.accountName')"
          />
          <div v-if="withdrawMsg" class="text-xs text-green-600">{{ withdrawMsg }}</div>
          <div v-if="withdrawErr" class="text-xs text-danger">{{ withdrawErr }}</div>
          <button
            @click="submitWithdraw"
            :disabled="withdrawSaving"
            class="w-full px-4 py-2 rounded-field bg-primary text-white text-sm font-semibold hover:bg-primary-hover transition disabled:opacity-60"
          >
            {{ withdrawSaving ? t('common.loading') : t('subsite.submitWithdraw') }}
          </button>
        </div>
      </div>

      <!-- 商品配置 -->
      <div class="bg-white rounded-card border border-border p-4 mb-4">
        <div class="text-sm font-semibold text-ink mb-3">{{ t('subsite.products') }}</div>
        <div v-if="!products.length" class="text-center text-ink-muted text-xs py-6">{{ t('subsite.noProducts') }}</div>
        <div v-else class="space-y-3">
          <div v-for="p in products" :key="p.product_id" class="border-b border-border/60 pb-2 last:border-0">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-2">
                <span class="text-ink text-sm">{{ p.product?.name || '#' + p.product_id }}</span>
                <span class="text-[10px] px-1.5 py-0.5 rounded-pill bg-surface-subtle text-ink-muted">
                  {{ pricingModeLabel(p.pricing_mode) }}
                </span>
              </div>
              <label class="flex items-center gap-1 cursor-pointer">
                <span class="text-xs text-ink-muted">{{ t('subsite.listed') }}</span>
                <input type="checkbox" :checked="p.is_listed" @change="toggleListed(p)" />
              </label>
            </div>
            <div class="flex items-center gap-2 mt-1">
              <span class="text-[11px] text-ink-muted">{{ t('subsite.markup') }}</span>
              <input
                v-model="p.markup_percent"
                type="number"
                step="0.01"
                class="w-24 px-2 py-1 border border-border rounded-field text-xs bg-white outline-none focus:border-primary"
              />
              <span class="text-[11px] text-ink-muted">%</span>
              <button
                @click="saveProduct(p)"
                class="ml-auto px-2 py-1 rounded-field bg-primary text-white text-[11px] font-semibold hover:bg-primary-hover transition"
              >
                {{ t('common.submit') }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- 利润账本 -->
      <div class="bg-white rounded-card border border-border p-4">
        <div class="text-sm font-semibold text-ink mb-3">{{ t('subsite.ledger') }}</div>
        <div v-if="!ledger.length" class="text-center text-ink-muted text-xs py-6">{{ t('subsite.noLedger') }}</div>
        <table v-else class="w-full text-xs">
          <thead>
            <tr class="text-ink-muted border-b border-border">
              <th class="text-left font-medium py-2">{{ t('subsite.ledgerType') }}</th>
              <th class="text-right font-medium py-2">{{ t('subsite.amount') }}</th>
              <th class="text-left font-medium py-2">{{ t('subsite.status') }}</th>
              <th class="text-right font-medium py-2">{{ t('subsite.date') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in ledger" :key="l.id" class="border-b border-border/60">
              <td class="py-2 text-ink">{{ l.type }}</td>
              <td class="py-2 text-right text-price font-semibold">{{ formatMoney(l.amount, prefs.baseCurrencyInfo) }}</td>
              <td class="py-2 text-ink-muted">{{ ledgerStatusText(l.status) }}</td>
              <td class="py-2 text-right text-ink-muted">{{ fmtDate(l.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- 销售订单 -->
      <div class="bg-white rounded-card border border-border p-4 mt-4">
        <div class="text-sm font-semibold text-ink mb-3">{{ t('subsite.orders') }}</div>
        <div v-if="!orders.length" class="text-center text-ink-muted text-xs py-6">{{ t('subsite.noOrders') }}</div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-xs whitespace-nowrap">
            <thead>
              <tr class="text-ink-muted border-b border-border">
                <th class="text-left font-medium py-2 pr-3">{{ t('subsite.orderNo') }}</th>
                <th class="text-left font-medium py-2 pr-3">{{ t('subsite.productName') }}</th>
                <th class="text-left font-medium py-2 pr-3">{{ t('subsite.buyer') }}</th>
                <th class="text-right font-medium py-2 pr-3">{{ t('subsite.quantity') }}</th>
                <th class="text-right font-medium py-2 pr-3">{{ t('subsite.amount') }}</th>
                <th class="text-right font-medium py-2 pr-3">{{ t('subsite.profit') }}</th>
                <th class="text-left font-medium py-2 pr-3">{{ t('subsite.status') }}</th>
                <th class="text-right font-medium py-2">{{ t('subsite.date') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="o in orders" :key="o.id" class="border-b border-border/60">
                <td class="py-2 pr-3 text-ink font-mono">{{ o.order_no || '#' + o.id }}</td>
                <td class="py-2 pr-3 text-ink">{{ o.product_name || '-' }}</td>
                <td class="py-2 pr-3 text-ink">{{ o.buyer_name || '-' }}</td>
                <td class="py-2 pr-3 text-right text-ink">{{ o.quantity }}</td>
                <td class="py-2 pr-3 text-right text-price font-semibold">{{ formatMoney(o.amount, prefs.baseCurrencyInfo) }}</td>
                <td class="py-2 pr-3 text-right text-price font-semibold">{{ formatMoney(o.profit, prefs.baseCurrencyInfo) }}</td>
                <td class="py-2 pr-3 text-ink-muted">{{ orderStatusText(o.status) }}</td>
                <td class="py-2 text-right text-ink-muted">{{ fmtDate(o.paid_at || o.created_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
