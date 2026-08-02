<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { getProduct, type Product } from '@/api/products'
import { createOrder } from '@/api/orders'
import { useSettingsStore } from '@/stores/settings'
import { useAuthStore } from '@/stores/auth'
import { formatMoney } from '@/utils/money'
import { usePreferencesStore } from '@/stores/preferences'

interface ControlField {
  type: string; label: string; name: string; required: boolean; options?: string[]
}

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const settings = useSettingsStore()
const auth = useAuthStore()
const prefs = usePreferencesStore()
const product = ref<Product | null>(null)
const selectedSku = ref<number | null>(null)
const qty = ref(1)
const contact = ref('')
const password = ref('')
const captcha = ref('')
const couponCode = ref('')
const couponDiscount = ref(0)
const couponMsg = ref('')
const couponChecking = ref(false)
const controlValues = ref<Record<string, string>>({})
const loading = ref(false)
const err = ref('')

// 下单验证码
const needCaptcha = computed(() => !!settings.config?.trade_captcha)
const captchaSrc = ref('')
const refreshCaptcha = () => {
  captchaSrc.value = `/api/captcha/trade?${Date.now()}`
}
// 联系方式类型(全局配置优先,商品级别覆盖)
const contactIsPhone = computed(() => {
  const globalType = settings.config?.contact_type
  const productType = (product.value as any)?.contact_type
  return (productType || globalType) === 'phone'
})
// 游客下单开关
const guestCheckoutAllowed = computed(() => settings.config?.guest_checkout !== false)

const controlFields = computed<ControlField[]>(() => {
  const cc = (product.value as any)?.control_config
  return Array.isArray(cc) ? cc : []
})

onMounted(async () => {
  await settings.load()
  const slug = route.query.product as string
  if (slug) {
    product.value = await getProduct(slug)
    selectedSku.value = route.query.sku ? Number(route.query.sku) : (product.value.skus?.[0]?.id ?? null)
  }
  qty.value = route.query.qty ? Number(route.query.qty) : 1
})

const price = () => {
  if (!product.value) return 0
  const sku = product.value.skus?.find(s => s.id === selectedSku.value)
  return sku ? sku.price : product.value.price
}
/** 展示币种最小单价(优先 _display 字段) */
const priceDisplay = () => {
  if (!product.value) return 0
  const sku = product.value.skus?.find(s => s.id === selectedSku.value)
  if (sku) return sku.price_display ?? sku.price
  return product.value.price_display ?? product.value.price
}
const total = () => {
  const base = price() * qty.value
  return Math.max(0, base - couponDiscount.value)
}
/** 展示金额合计(展示币种最小单位;优惠券折扣按展示币种比例折算) */
const totalDisplay = () => {
  const base = priceDisplay() * qty.value
  const ratio = price() ? priceDisplay() / price() : 1
  const discountDisplay = couponDiscount.value * ratio
  return Math.max(0, base - discountDisplay)
}

// 优惠券验证
async function validateCoupon() {
  couponMsg.value = ''
  couponDiscount.value = 0
  if (!couponCode.value.trim() || !product.value) return
  couponChecking.value = true
  try {
    const res = await fetch('/api/coupons/validate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code: couponCode.value.trim(), product_id: product.value.id, amount: price() * qty.value }),
    })
    const data = await res.json()
    if (data.valid) {
      couponDiscount.value = data.discount
      couponMsg.value = t('order.checkout.couponValid', { amount: data.discount_display })
    } else {
      couponMsg.value = data.message || t('order.checkout.couponInvalid')
    }
  } catch {
    couponMsg.value = t('order.checkout.couponValidateFailed')
  } finally {
    couponChecking.value = false
  }
}

async function submit() {
  if (!product.value) return
  // 游客下单检查
  if (!auth.isLoggedIn && !guestCheckoutAllowed.value) {
    err.value = t('order.checkout.guestOnlyHint'); return
  }
  if (!contact.value.trim()) { err.value = t('order.checkout.fillContact'); return }

  // 校验必填控件
  for (const f of controlFields.value) {
    if (f.required && !(controlValues.value[f.name]?.trim())) {
      err.value = t('order.checkout.fillField', { name: f.label })
      return
    }
  }

  // 下单验证码
  if (needCaptcha.value && !captcha.value) {
    err.value = t('common.validation.fillCaptcha'); return
  }

  // 仅限会员检查
  if ((product.value as any).only_user && !auth.isLoggedIn) {
    err.value = t('order.checkout.onlyUserSubmitError')
    return
  }

  err.value = ''
  loading.value = true
  try {
    const res = await createOrder({
      product_id: product.value.id,
      sku_id: selectedSku.value ?? undefined,
      qty: qty.value,
      contact: contact.value,
      password: password.value || undefined,
      captcha: needCaptcha.value ? captcha.value : undefined,
      coupon_code: couponCode.value.trim() || undefined,
      extra: { ...controlValues.value },
    } as any)
    router.push(`/pay/${res.order_no}`)
  } catch (e: any) {
    err.value = e?.response?.data?.message || t('order.checkout.submitFailed')
    if (needCaptcha.value) refreshCaptcha()
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="max-w-2xl mx-auto px-4 py-8">
    <h1 class="text-xl font-bold text-ink mb-6">{{ t('order.checkout.title') }}</h1>

    <!-- 商品确认 -->
    <div v-if="product" class="flex gap-3 p-4 bg-white rounded-card border border-border mb-4">
      <div class="w-16 h-16 bg-gradient-to-br from-primary-soft to-primary-light rounded-card flex items-center justify-center text-primary/40 text-xs flex-shrink-0 overflow-hidden">
        <img v-if="product.cover" :src="product.cover" class="w-full h-full object-cover rounded-card" />
        <span v-else>{{ t('common.noImage') }}</span>
      </div>
      <div class="flex-1">
        <div class="text-sm font-semibold text-ink">{{ product.name }}</div>
        <div v-if="product.skus?.length" class="text-xs text-ink-muted mt-1">
          {{ product.skus.find(s => s.id === selectedSku)?.name }} × {{ qty }}
        </div>
      </div>
      <div class="text-right">
        <div class="text-price font-bold">{{ formatMoney(priceDisplay(), prefs.currentCurrency) }}</div>
        <div class="text-xs text-ink-muted">× {{ qty }}</div>
      </div>
    </div>

    <!-- 小计 -->
    <div class="flex justify-between px-4 py-3 mb-4">
      <span class="text-ink-soft">{{ t('order.checkout.subtotal') }}</span>
      <span class="text-2xl font-extrabold text-price">{{ formatMoney(totalDisplay(), prefs.currentCurrency) }}</span>
    </div>

    <!-- 联系方式 -->
    <div class="space-y-3">
      <div>
        <label class="text-xs font-semibold text-ink-soft">{{ contactIsPhone ? t('order.checkout.phoneLabel') : t('order.checkout.emailLabel') }} *</label>
        <input v-model="contact" :type="contactIsPhone ? 'tel' : 'email'" :placeholder="contactIsPhone ? t('order.checkout.phonePlaceholder') : t('order.checkout.emailPlaceholder')"
          class="w-full mt-1 px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
      </div>

      <!-- 动态控件(由后台 control_config 驱动) -->
      <div v-for="f in controlFields" :key="f.name">
        <label class="text-xs font-semibold text-ink-soft">
          {{ f.label }} <span v-if="f.required" class="text-danger">*</span>
        </label>
        <!-- select 下拉 -->
        <select v-if="f.type === 'select'" v-model="controlValues[f.name]"
          class="w-full mt-1 px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition bg-white">
          <option value="">{{ t('order.checkout.selectPlaceholder') }}</option>
          <option v-for="opt in (f.options || [])" :key="opt" :value="opt">{{ opt }}</option>
        </select>
        <!-- textarea -->
        <textarea v-else-if="f.type === 'textarea'" v-model="controlValues[f.name]" rows="3"
          :placeholder="t('order.checkout.inputPlaceholder', { name: f.label })"
          class="w-full mt-1 px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition"></textarea>
        <!-- text/email/number -->
        <input v-else v-model="controlValues[f.name]" :type="f.type" :placeholder="t('order.checkout.inputPlaceholder', { name: f.label })"
          class="w-full mt-1 px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
      </div>

      <!-- 查询密码 -->
      <div v-if="settings.config?.order_query_password">
        <label class="text-xs font-semibold text-ink-soft">{{ t('order.checkout.queryPasswordLabel') }}</label>
        <input v-model="password" type="password" :placeholder="t('order.checkout.queryPasswordPlaceholder')"
          class="w-full mt-1 px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
      </div>
    </div>

    <!-- 仅限会员提示 -->
    <div v-if="(product as any)?.only_user && !auth.isLoggedIn" class="mt-3 p-2 bg-orange-50 border border-orange-200 rounded text-xs text-orange-700">
      {{ t('order.checkout.onlyMemberHint') }} <router-link to="/login" class="text-primary underline">{{ t('order.checkout.onlyMemberLink') }}</router-link>
    </div>

    <!-- 优惠券 -->
    <div class="mt-4">
      <label class="text-xs font-semibold text-ink-soft mb-1 block">{{ t('order.checkout.couponLabel') }}</label>
      <div class="flex gap-2">
        <input v-model="couponCode" type="text" :placeholder="t('order.checkout.couponPlaceholder')" @blur="validateCoupon"
          class="flex-1 px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
        <button type="button" @click="validateCoupon" :disabled="couponChecking"
          class="px-4 py-2 text-xs bg-surface-subtle text-ink-soft rounded-field border border-border hover:bg-border transition whitespace-nowrap disabled:opacity-50">
          {{ couponChecking ? t('order.checkout.couponChecking') : t('order.checkout.couponValidate') }}
        </button>
      </div>
      <div v-if="couponMsg" :class="['text-xs mt-1', couponDiscount > 0 ? 'text-success' : 'text-danger']">{{ couponMsg }}</div>
    </div>

    <div v-if="err" class="text-danger text-xs mt-3">{{ err }}</div>

    <!-- 下单验证码 -->
    <div v-if="needCaptcha" class="mt-4">
      <label class="text-xs font-semibold text-ink-soft mb-1 block">{{ t('common.captcha') }}</label>
      <div class="flex gap-2">
        <input v-model="captcha" type="text" :placeholder="t('common.validation.fillCaptcha')" maxlength="6"
          class="flex-1 px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
        <img v-if="captchaSrc" :src="captchaSrc" @click="refreshCaptcha"
          class="h-10 cursor-pointer rounded-field border border-border" :alt="t('common.captcha')" :title="t('order.checkout.captchaRefreshTitle')" />
        <button v-else @click="refreshCaptcha" class="px-3 text-xs bg-surface-subtle rounded-field border border-border">{{ t('order.checkout.captchaGet') }}</button>
      </div>
    </div>

    <button @click="submit" :disabled="loading"
      class="w-full mt-6 bg-gradient-to-r from-primary to-primary-hover text-white font-bold py-3 rounded-card shadow-md hover:shadow-pop disabled:opacity-50 transition">
      {{ loading ? t('order.checkout.submitting') : t('order.checkout.submitOrder', { amount: formatMoney(totalDisplay(), prefs.currentCurrency) }) }}
    </button>
  </div>
</template>
