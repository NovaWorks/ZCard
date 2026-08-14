<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { usePreferencesStore } from '@/stores/preferences'
import AppIcon from '@/components/AppIcon.vue'
import { formatMoney } from '@/utils/money'
import { getMyOrders, type OrderDetail } from '@/api/orders'
import { updatePassword, updateProfile } from '@/api/auth'
import {
  getMySupplyAccount,
  getMySupplySecret,
  regenerateMySupplySecret,
  type MySupplyAccount,
} from '@/api/supplier'

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const prefs = usePreferencesStore()

// ===== 自助 API 对接(供货) =====
const supplyAccount = ref<MySupplyAccount | null>(null)
const supplySecretVisible = ref(false)
const supplySecretPlain = ref('')
const supplyDocOpen = ref(false)
const supplyCreating = ref(false)
const supplyRegenerating = ref(false)
const supplyMsg = ref('')

function notify(msg: string) {
  supplyMsg.value = msg
  setTimeout(() => (supplyMsg.value = ''), 2500)
}

const apiBaseUrl = computed(() => `${window.location.origin}/api/supply`)

async function loadSupply() {
  try {
    supplyAccount.value = await getMySupplyAccount()
  } catch {
    supplyAccount.value = null
  }
}

async function createSupply() {
  supplyCreating.value = true
  try {
    const acc = await getMySupplyAccount()
    supplyAccount.value = acc
    if (acc.is_new && acc.approved) {
      supplySecretPlain.value = acc.api_secret
      supplySecretVisible.value = true
      notify(t('supply.created'))
    } else if (acc.is_new || !acc.approved) {
      notify(t('supply.pendingApproval'))
    }
  } catch {
    // 拦截器提示
  } finally {
    supplyCreating.value = false
  }
}

async function toggleSupplySecret() {
  if (supplySecretVisible.value) {
    supplySecretVisible.value = false
    return
  }
  try {
    const res = await getMySupplySecret()
    supplySecretPlain.value = res.api_secret
    supplySecretVisible.value = true
  } catch {
    // 拦截器提示
  }
}

async function regenerateSupplySecret() {
  if (!confirm(t('supply.regenerateConfirm'))) return
  supplyRegenerating.value = true
  try {
    const res = await regenerateMySupplySecret()
    supplySecretPlain.value = res.api_secret
    supplySecretVisible.value = true
    if (supplyAccount.value) supplyAccount.value.api_secret_masked = `••••••••${res.api_secret.slice(-4)}`
    notify(t('supply.regenerated'))
  } catch {
    // 拦截器提示
  } finally {
    supplyRegenerating.value = false
  }
}

async function copyText(text: string) {
  try {
    await navigator.clipboard.writeText(text)
    notify(t('supply.copied'))
  } catch {
    // 忽略
  }
}

function goRechargeSupply() {
  router.push({ name: 'recharge', query: { target: 'supply' } })
}

const orders = ref<OrderDetail[]>([])
const loadingOrders = ref(true)

// 账号设置表单
const profileForm = ref({ username: '', email: '' })
const passwordForm = ref({ current_password: '', password: '', password_confirmation: '' })
const savingProfile = ref(false)
const savingPassword = ref(false)
const errMsg = ref('')
const okMsg = ref('')

const balance = computed(() => auth.user?.balance ?? 0)

const statusCls: Record<string, string> = {
  pending: 'bg-amber-100 text-amber-700',
  paid: 'bg-green-100 text-green-700',
  closed: 'bg-gray-100 text-gray-500',
  refunded: 'bg-red-100 text-red-700',
}
const statusText = (s: string) => t(`common.orderStatus.${s}`)
const statusClass = (s: string) => statusCls[s] || 'bg-gray-100 text-gray-500'
const fmtDate = (d?: string) => (d ? String(d).slice(0, 16).replace('T', ' ') : '')

async function loadData() {
  if (!auth.isLoggedIn) return
  // 同步资料表单
  profileForm.value.username = auth.user?.username ?? ''
  profileForm.value.email = auth.user?.email ?? ''
  // 拉订单
  loadingOrders.value = true
  try {
    orders.value = await getMyOrders()
  } catch {
    orders.value = []
  } finally {
    loadingOrders.value = false
  }
  // 自助 API 对接
  loadSupply()
}

async function handleSaveProfile() {
  errMsg.value = ''
  okMsg.value = ''
  savingProfile.value = true
  try {
    const updated = await updateProfile({
      username: profileForm.value.username,
      email: profileForm.value.email,
    })
    auth.user = updated
    okMsg.value = t('userCenter.profileSaved')
  } catch (e: any) {
    errMsg.value = e?.response?.data?.message || e?.message || 'Error'
  } finally {
    savingProfile.value = false
  }
}

async function handleSavePassword() {
  errMsg.value = ''
  okMsg.value = ''
  if (passwordForm.value.password !== passwordForm.value.password_confirmation) {
    errMsg.value = t('userCenter.confirmPassword') + ' ✗'
    return
  }
  if (passwordForm.value.password.length < 8) {
    errMsg.value = '≥ 8'
    return
  }
  savingPassword.value = true
  try {
    await updatePassword(passwordForm.value)
    passwordForm.value = { current_password: '', password: '', password_confirmation: '' }
    auth.clearAuth()
    await router.push('/login')
  } catch (e: any) {
    errMsg.value = e?.response?.data?.message || e?.message || 'Error'
  } finally {
    savingPassword.value = false
  }
}

function go(name: string) {
  router.push({ name })
}

onMounted(loadData)
</script>

<template>
  <div class="mx-auto w-full max-w-4xl px-4 sm:px-6 py-6 space-y-6">
    <!-- 未登录提示 -->
    <div v-if="!auth.isLoggedIn" class="text-center py-20">
      <div class="text-5xl mb-4 opacity-30"><AppIcon name="ri:lock-line" class="w-12 h-12" /></div>
      <p class="text-ink-soft mb-4">{{ t('userCenter.loginRequired') }}</p>
      <button @click="go('login')" class="bg-primary text-white px-6 py-2 rounded-field text-sm font-medium">
        {{ t('nav.login') }}
      </button>
    </div>

    <template v-else>
      <!-- 用户信息卡(渐变) -->
      <section class="bg-gradient-to-br from-primary-hover via-primary to-blue-500 text-white rounded-card shadow-card overflow-hidden">
        <div class="px-6 py-8">
          <div class="flex items-center gap-4">
            <div class="w-16 h-16 rounded-full bg-white/20 flex items-center justify-center text-2xl font-bold shrink-0">
              {{ auth.user?.username?.charAt(0).toUpperCase() || '?' }}
            </div>
            <div class="flex-1 min-w-0">
              <div class="text-white/80 text-sm">{{ t('userCenter.welcome') }}</div>
              <div class="text-xl font-bold truncate">{{ auth.user?.username }}</div>
              <div class="text-white/70 text-xs mt-0.5 truncate">{{ auth.user?.email }}</div>
            </div>
          </div>

          <div class="mt-6 bg-white/15 rounded-field p-4 backdrop-blur-sm">
            <div class="text-white/80 text-xs">{{ t('userCenter.balance') }}</div>
            <div class="text-3xl font-extrabold mt-1">{{ formatMoney(balance, prefs.baseCurrencyInfo) }}</div>
            <div class="mt-3 flex gap-2">
              <button @click="go('recharge')"
                class="bg-white/90 text-primary text-sm font-semibold px-4 py-1.5 rounded-pill hover:bg-white transition">
                {{ t('userCenter.recharge') }}
              </button>
              <button @click="go('withdraw')"
                class="bg-white/20 text-white text-sm font-semibold px-4 py-1.5 rounded-pill hover:bg-white/30 transition border border-white/30">
                {{ t('userCenter.withdraw') }}
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- 快捷入口 -->
      <section>
        <h2 class="text-sm font-semibold text-ink-soft mb-3">{{ t('userCenter.quickEntry') }}</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <button @click="go('my-orders')" class="bg-white rounded-field border border-border p-4 text-center hover:shadow-card-hover transition">
            <div class="text-2xl mb-1"><AppIcon name="ri:archive-line" class="w-6 h-6" /></div>
            <div class="text-xs font-medium text-ink">{{ t('userCenter.myOrders') }}</div>
            <div class="text-[10px] text-ink-muted mt-0.5">{{ orders.length }} {{ t('userCenter.orderCount') }}</div>
          </button>
          <button @click="go('recharge')" class="bg-white rounded-field border border-border p-4 text-center hover:shadow-card-hover transition">
            <div class="text-2xl mb-1"><AppIcon name="ri:money-cny-circle-line" class="w-6 h-6" /></div>
            <div class="text-xs font-medium text-ink">{{ t('userCenter.recharge') }}</div>
            <div class="text-[10px] text-ink-muted mt-0.5">{{ formatMoney(balance, prefs.baseCurrencyInfo) }}</div>
          </button>
          <button @click="go('distribution')" class="bg-white rounded-field border border-border p-4 text-center hover:shadow-card-hover transition">
            <div class="text-2xl mb-1"><AppIcon name="ri:gift-2-line" class="w-6 h-6" /></div>
            <div class="text-xs font-medium text-ink">{{ t('userCenter.distribution') }}</div>
          </button>
          <button @click="go('my-subsite')" class="bg-white rounded-field border border-border p-4 text-center hover:shadow-card-hover transition">
            <div class="text-2xl mb-1"><AppIcon name="ri:store-3-line" class="w-6 h-6" /></div>
            <div class="text-xs font-medium text-ink">{{ t('userCenter.mySubsite') }}</div>
          </button>
        </div>
      </section>

      <!-- API 对接(自助供货) -->
      <section class="bg-white rounded-card border border-border overflow-hidden">
        <div class="px-5 py-3 border-b border-border flex items-center justify-between">
          <h2 class="text-sm font-semibold text-ink flex items-center gap-1.5"><AppIcon name="ri:link" class="w-4 h-4" /> {{ t('supply.title') }}</h2>
          <span v-if="supplyAccount" class="text-[11px] text-ink-muted">{{ t('supply.subtitle') }}</span>
        </div>
        <div class="p-5">
          <!-- 全局提示 -->
          <div v-if="supplyMsg" class="mb-3 text-xs text-primary font-medium">{{ supplyMsg }}</div>

          <!-- 未开通凭证 -->
          <div v-if="!supplyAccount" class="text-center py-6">
            <div class="text-5xl mb-3 opacity-30"><AppIcon name="ri:link" class="w-12 h-12" /></div>
            <p class="text-ink-soft text-sm mb-4">{{ t('supply.notOpened') }}</p>
            <button
              @click="createSupply"
              :disabled="supplyCreating"
              class="bg-primary text-white px-6 py-2 rounded-field text-sm font-medium hover:opacity-90 transition disabled:opacity-60"
            >
              {{ supplyCreating ? '...' : t('supply.open') }}
            </button>
          </div>

          <!-- 已开通 -->
          <template v-else>
            <!-- 待审核提示:审核通过前供货 API 不可用 -->
            <div
              v-if="!supplyAccount.approved"
              class="mb-3 flex items-start gap-2 rounded-field border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-700"
            >
              <AppIcon name="ri:error-warning-line" class="mt-0.5 h-4 w-4 shrink-0" />
              <div>
                <span class="font-semibold">{{ t('supply.pendingApproval') }}</span>
                <p class="mt-1 leading-relaxed">{{ t('supply.pendingApprovalTip') }}</p>
              </div>
            </div>

            <!-- 余额 + 充值 -->
            <div class="flex items-center justify-between bg-surface-subtle rounded-field p-4">
              <div>
                <div class="text-xs text-ink-muted">{{ t('supply.balance') }}</div>
                <div class="text-2xl font-extrabold text-ink mt-0.5">{{ formatMoney(supplyAccount.balance, prefs.baseCurrencyInfo) }}</div>
              </div>
              <button
                @click="goRechargeSupply"
                class="bg-primary text-white text-sm font-semibold px-5 py-2 rounded-pill hover:opacity-90 transition"
              >
                {{ t('supply.recharge') }}
              </button>
            </div>

            <!-- 凭证 -->
            <div class="mt-3 space-y-2 text-sm">
              <div class="flex items-center justify-between bg-surface-subtle rounded-field px-4 py-2.5">
                <span class="text-xs text-ink-muted shrink-0 mr-2">{{ t('supply.apiBaseUrl') }}</span>
                <code class="flex-1 min-w-0 text-xs text-ink font-mono truncate text-right">{{ apiBaseUrl }}</code>
                <button class="ml-2 text-primary text-xs hover:opacity-70" @click="copyText(apiBaseUrl)">{{ t('supply.copy') }}</button>
              </div>
              <div class="flex items-center justify-between bg-surface-subtle rounded-field px-4 py-2.5">
                <span class="text-xs text-ink-muted shrink-0 mr-2">{{ t('supply.appId') }}</span>
                <code class="flex-1 min-w-0 text-xs text-ink font-mono truncate text-right">{{ supplyAccount.api_key }}</code>
                <button class="ml-2 text-primary text-xs hover:opacity-70" @click="copyText(supplyAccount!.api_key)">{{ t('supply.copy') }}</button>
              </div>
              <div class="flex items-center justify-between bg-surface-subtle rounded-field px-4 py-2.5">
                <span class="text-xs text-ink-muted shrink-0 mr-2">{{ t('supply.appSecret') }}</span>
                <code v-if="supplySecretVisible" class="flex-1 min-w-0 text-xs text-ink font-mono truncate text-right break-all">{{ supplySecretPlain }}</code>
                <code v-else class="text-xs text-ink-muted font-mono">{{ supplyAccount.api_secret_masked }}</code>
                <div class="flex items-center gap-1 ml-2 shrink-0">
                  <button class="text-primary text-xs hover:opacity-70" @click="toggleSupplySecret">
                    {{ supplySecretVisible ? t('supply.hide') : t('supply.show') }}
                  </button>
                  <button
                    v-if="supplySecretVisible"
                    class="text-primary text-xs hover:opacity-70"
                    @click="copyText(supplySecretPlain)"
                  >
                    {{ t('supply.copy') }}
                  </button>
                  <button class="text-ink-muted text-xs hover:opacity-70" @click="regenerateSupplySecret">
                    {{ supplyRegenerating ? '...' : t('supply.regenerate') }}
                  </button>
                </div>
              </div>
            </div>

            <!-- 对接文档(可折叠) -->
            <div class="mt-3 border border-border rounded-field overflow-hidden">
              <button
                @click="supplyDocOpen = !supplyDocOpen"
                class="w-full px-4 py-2.5 flex items-center justify-between text-left text-xs font-medium text-ink-soft hover:bg-surface-subtle/50 transition"
              >
                <span class="inline-flex items-center gap-1"><AppIcon name="ri:book-open-line" class="w-4 h-4" /> {{ t('supply.docTitle') }}</span>
                <span class="transition-transform" :class="supplyDocOpen ? 'rotate-180' : ''">▾</span>
              </button>
              <div v-show="supplyDocOpen" class="px-4 pb-3 text-[11px] text-ink-muted leading-relaxed space-y-1.5">
                <p>1. {{ t('supply.docStep1') }}</p>
                <p>2. {{ t('supply.docStep2') }}</p>
                <p>3. {{ t('supply.docStep3') }}</p>
                <p>4. {{ t('supply.docStep4') }}</p>
              </div>
            </div>
          </template>
        </div>
      </section>

      <!-- 最近订单 -->
      <section class="bg-white rounded-card border border-border overflow-hidden">
        <div class="px-5 py-3 border-b border-border flex items-center justify-between">
          <h2 class="text-sm font-semibold text-ink">{{ t('userCenter.myOrders') }}</h2>
          <button @click="go('my-orders')" class="text-xs text-primary hover:underline">{{ t('userCenter.myOrders') }} →</button>
        </div>        <div v-loading="loadingOrders">
          <div v-if="orders.length === 0" class="text-center py-10 text-ink-muted text-sm">
            {{ t('userCenter.ordersEmpty') }}
          </div>
          <div v-else class="divide-y divide-border">
            <div v-for="o in orders.slice(0, 5)" :key="o.order_no" class="px-5 py-3 flex items-center gap-3">
              <div class="flex-1 min-w-0">
                <div class="text-sm text-ink truncate">{{ o.product_name || t('common.productOffShelf') }}</div>
                <div class="text-xs text-ink-muted mt-0.5 font-mono">{{ o.order_no }}</div>
              </div>
              <div class="text-right shrink-0">
                <div class="text-sm font-semibold text-price">{{ formatMoney(o.amount_display ?? o.amount, prefs.currencyOf(o.display_currency)) }}</div>
                <span class="inline-block text-[10px] font-semibold px-1.5 py-0.5 rounded-pill mt-0.5" :class="statusClass(o.status)">
                  {{ statusText(o.status) }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- 账号设置 -->
      <section class="bg-white rounded-card border border-border overflow-hidden">
        <div class="px-5 py-3 border-b border-border">
          <h2 class="text-sm font-semibold text-ink">{{ t('userCenter.accountSettings') }}</h2>
        </div>
        <div class="px-5 py-4">
          <!-- 提示 -->
          <div v-if="okMsg" class="mb-3 text-xs text-green-600 bg-green-50 rounded-field px-3 py-2">✓ {{ okMsg }}</div>
          <div v-if="errMsg" class="mb-3 text-xs text-red-600 bg-red-50 rounded-field px-3 py-2 inline-flex items-center gap-1"><AppIcon name="ri:alert-line" class="w-3.5 h-3.5" /> {{ errMsg }}</div>

          <h3 class="text-xs font-semibold text-ink-soft mb-2">{{ t('userCenter.profile') }}</h3>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-6">
            <div>
              <label class="block text-xs text-ink-muted mb-1">{{ t('userCenter.username') }}</label>
              <input v-model="profileForm.username" type="text"
                class="w-full px-3 py-2 border border-border rounded-field text-sm outline-none focus:border-primary" />
            </div>
            <div>
              <label class="block text-xs text-ink-muted mb-1">{{ t('userCenter.email') }}</label>
              <input v-model="profileForm.email" type="email"
                class="w-full px-3 py-2 border border-border rounded-field text-sm outline-none focus:border-primary" />
            </div>
          </div>
          <button @click="handleSaveProfile" :disabled="savingProfile"
            class="bg-primary text-white text-sm font-medium px-5 py-2 rounded-field hover:bg-primary-hover transition disabled:opacity-50">
            {{ savingProfile ? '...' : t('userCenter.saveProfile') }}
          </button>

          <hr class="my-5 border-border" />

          <h3 class="text-xs font-semibold text-ink-soft mb-2">{{ t('userCenter.changePassword') }}</h3>
          <div class="space-y-3 max-w-md">
            <div>
              <label class="block text-xs text-ink-muted mb-1">{{ t('userCenter.currentPassword') }}</label>
              <input v-model="passwordForm.current_password" type="password"
                class="w-full px-3 py-2 border border-border rounded-field text-sm outline-none focus:border-primary" />
            </div>
            <div>
              <label class="block text-xs text-ink-muted mb-1">{{ t('userCenter.newPassword') }}</label>
              <input v-model="passwordForm.password" type="password"
                class="w-full px-3 py-2 border border-border rounded-field text-sm outline-none focus:border-primary" />
            </div>
            <div>
              <label class="block text-xs text-ink-muted mb-1">{{ t('userCenter.confirmPassword') }}</label>
              <input v-model="passwordForm.password_confirmation" type="password"
                class="w-full px-3 py-2 border border-border rounded-field text-sm outline-none focus:border-primary" />
            </div>
          </div>
          <button @click="handleSavePassword" :disabled="savingPassword"
            class="mt-3 bg-primary text-white text-sm font-medium px-5 py-2 rounded-field hover:bg-primary-hover transition disabled:opacity-50">
            {{ savingPassword ? '...' : t('userCenter.submitPassword') }}
          </button>
        </div>
      </section>
    </template>
  </div>
</template>
