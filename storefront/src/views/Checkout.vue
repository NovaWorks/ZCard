<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { getProduct, type Product } from '@/api/products'
import { createOrder, createBatchOrders } from '@/api/orders'
import { getChannels, createPayment, createBatchPayment, type PaymentChannel, type PaymentResult } from '@/api/payments'
import { useSettingsStore } from '@/stores/settings'
import { useAuthStore } from '@/stores/auth'
import { formatMoney } from '@/utils/money'
import { usePreferencesStore } from '@/stores/preferences'
import { useCartStore, type CartItem } from '@/stores/cart'

interface ControlField {
  type: string; label: string; name: string; required: boolean; options?: string[]
}

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const settings = useSettingsStore()
const auth = useAuthStore()
const prefs = usePreferencesStore()
const cart = useCartStore()

/** 收银台商品行(购物车模式 = cart items;单品模式 = 单条) */
interface LineItem {
  product_id: number
  sku_id: number | null
  qty: number
  slug: string
  name: string
  cover: string | null
  price: number
  price_display: number
  sku_name: string | null
}

const isCartMode = computed(() => route.query.cart === '1' || cart.items.length > 0)
const items = ref<LineItem[]>([])
const loading = ref(true)
const err = ref('')

// 单品模式数据
const singleProduct = ref<Product | null>(null)
const selectedSku = ref<number | null>(null)

// 表单
const contact = ref('')
const password = ref('')
const captcha = ref('')
const couponCode = ref('')
const couponDiscount = ref(0)
const couponMsg = ref('')
const couponChecking = ref(false)
const controlValues = ref<Record<string, string>>({})

// 支付渠道
const channels = ref<PaymentChannel[]>([])
const selectedChannelId = ref<number | null>(null)
const submitting = ref(false)

// 下单验证码
const needCaptcha = computed(() => !!settings.config?.trade_captcha)
const captchaSrc = ref('')
const refreshCaptcha = async () => {
  try {
    const res = await fetch(`/api/captcha/trade?${Date.now()}`)
    const data = await res.json()
    captchaSrc.value = data.src || ''
  } catch {
    captchaSrc.value = ''
  }
}

const contactIsPhone = computed(() => {
  const globalType = settings.config?.contact_type
  const productType = (singleProduct.value as any)?.contact_type
  return (productType || globalType) === 'phone'
})

const guestCheckoutAllowed = computed(() => settings.config?.guest_checkout !== false)

const controlFields = computed<ControlField[]>(() => {
  const cc = (singleProduct.value as any)?.control_config
  return Array.isArray(cc) ? cc : []
})

/** 购物车模式行(引用 cart store,数量编辑直接生效) */
const cartItems = computed<CartItem[]>(() => cart.items)

onMounted(async () => {
  await settings.load()
  void prefs.load()
  await loadChannels()
  loading.value = false
  if (needCaptcha.value) refreshCaptcha()

  if (isCartMode.value) {
    if (!cart.items.length) {
      // 明确请求购物车模式但购物车为空 → 回退单品模式(URL 有 product)
      const slug = route.query.product as string
      if (slug) await loadSingle(slug)
      return
    }
    items.value = cart.items.map((i) => ({ ...i }))
    return
  }
  const slug = route.query.product as string
  if (slug) await loadSingle(slug)
})

async function loadSingle(slug: string) {
  try {
    singleProduct.value = await getProduct(slug)
    selectedSku.value = route.query.sku ? Number(route.query.sku) : (singleProduct.value.skus?.[0]?.id ?? null)
    const sku = singleProduct.value.skus?.find((s) => s.id === selectedSku.value)
    const qty = route.query.qty ? Number(route.query.qty) : 1
    items.value = [{
      product_id: singleProduct.value.id,
      sku_id: selectedSku.value,
      qty,
      slug: singleProduct.value.slug,
      name: singleProduct.value.name,
      cover: singleProduct.value.cover ?? null,
      price: sku ? sku.price : singleProduct.value.price,
      price_display: sku ? (sku.price_display ?? sku.price) : (singleProduct.value.price_display ?? singleProduct.value.price),
      sku_name: sku?.name ?? null,
    }]
  } catch {
    err.value = t('product.detail.notFound')
  }
}

/** 单品模式:修改 SKU 时同步行 */
function onSkuChange() {
  if (!singleProduct.value) return
  const sku = singleProduct.value.skus?.find((s) => s.id === selectedSku.value)
  if (!items.value.length) return
  items.value[0].sku_id = selectedSku.value
  items.value[0].sku_name = sku?.name ?? null
  items.value[0].price = sku ? sku.price : singleProduct.value.price
  items.value[0].price_display = sku ? (sku.price_display ?? sku.price) : (singleProduct.value.price_display ?? singleProduct.value.price)
}

/** 金额合计(基础货币分) */
const subtotal = computed(() => items.value.reduce((n, i) => n + i.price * i.qty, 0))
const total = computed(() => Math.max(0, subtotal.value - couponDiscount.value))
/** 展示货币合计(折扣按展示币种比例折算) */
const totalDisplay = computed(() => {
  const base = items.value.reduce((n, i) => n + i.price_display * i.qty, 0)
  const ratio = subtotal.value ? totalDisplayRatio.value : 1
  return Math.max(0, base - couponDiscount.value * ratio)
})
const totalDisplayRatio = computed(() => {
  const skuTotal = items.value.reduce((n, i) => n + i.price * i.qty, 0)
  const dispTotal = items.value.reduce((n, i) => n + i.price_display * i.qty, 0)
  return skuTotal ? dispTotal / skuTotal : 1
})

// 优惠券验证
async function validateCoupon() {
  couponMsg.value = ''
  couponDiscount.value = 0
  if (!couponCode.value.trim() || !items.value.length) return
  couponChecking.value = true
  try {
    const res = await fetch('/api/coupons/validate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        code: couponCode.value.trim(),
        product_id: items.value[0].product_id,
        amount: subtotal.value,
      }),
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

/** 数量增减(购物车模式同步 store) */
function changeQty(idx: number, delta: number) {
  const it = items.value[idx]
  if (!it) return
  const next = it.qty + delta
  if (next < 1) return
  it.qty = next
  if (isCartMode.value) cart.updateQty(it.product_id, it.sku_id, next)
}
function removeItem(idx: number) {
  const it = items.value[idx]
  if (!it) return
  if (isCartMode.value) {
    cart.remove(it.product_id, it.sku_id)
    items.value = cart.items.map((i) => ({ ...i }))
  } else {
    items.value.splice(idx, 1)
  }
}

async function loadChannels() {
  try {
    channels.value = await getChannels()
    if (channels.value.length && selectedChannelId.value === null) {
      selectedChannelId.value = channels.value[0].id
    }
  } catch {
    channels.value = []
  }
}

/** 支付结果:跳转链接 / 展示二维码 / 自动提交表单 */function handleResult(result: PaymentResult) {
  if (result.type === 'redirect' && result.redirect_url) {
    window.location.href = result.redirect_url
    return
  }
  if (result.type === 'qrcode' && result.qrcode_content) {
    // 二维码场景:跳结果页展示(轮询逻辑在结果页/支付页已有)
    router.push({ path: '/pay/result', query: { order_no: '', qrcode: result.qrcode_content } })
    return
  }
  if (result.type === 'form' && result.form_html) {
    const container = document.createElement('div')
    container.innerHTML = result.form_html
    document.body.appendChild(container)
    const form = container.querySelector('form')
    if (form) form.submit()
    return
  }
  err.value = t('order.pay.payUnknown')
}

async function submit() {
  if (!items.value.length) { err.value = t('order.checkout.cartEmpty'); return }
  if (!selectedChannelId.value) { err.value = t('order.checkout.selectChannel'); return }
  if (!auth.isLoggedIn && !guestCheckoutAllowed.value) {
    err.value = t('order.checkout.guestOnlyHint'); return
  }
  if (!contact.value.trim()) { err.value = t('order.checkout.fillContact'); return }
  if (needCaptcha.value && !captcha.value) {
    err.value = t('common.validation.fillCaptcha'); return
  }
  // 单品模式:校验必填控件
  for (const f of controlFields.value) {
    if (f.required && !(controlValues.value[f.name]?.trim())) {
      err.value = t('order.checkout.fillField', { name: f.label })
      return
    }
  }

  err.value = ''
  submitting.value = true
  try {
    if (isCartMode.value) {
      const res = await createBatchOrders({
        items: items.value.map((i) => ({ product_id: i.product_id, sku_id: i.sku_id ?? undefined, qty: i.qty })),
        contact: contact.value,
        password: password.value || undefined,
        captcha: needCaptcha.value ? captcha.value : undefined,
        coupon_code: couponCode.value.trim() || undefined,
        extra: undefined,
      })
      const result = await createBatchPayment(res.order_ids, selectedChannelId.value)
      cart.clear()
      handleResult(result)
    } else {
      const it = items.value[0]
      const res = await createOrder({
        product_id: it.product_id,
        sku_id: it.sku_id ?? undefined,
        qty: it.qty,
        contact: contact.value,
        password: password.value || undefined,
        extra: { ...controlValues.value },
      } as any)
      const result = await createPayment(res.order_no, selectedChannelId.value)
      handleResult(result)
    }
  } catch (e: any) {
    err.value = e?.response?.data?.message || t('order.checkout.submitFailed')
    if (needCaptcha.value) refreshCaptcha()
  } finally {
    submitting.value = false
  }
}

const channelLabel = (ch: PaymentChannel) => ch.name
</script>

<template>
  <div class="max-w-6xl mx-auto px-4 py-6">
    <!-- 标题 -->
    <div class="flex items-center justify-between mb-5">
      <h1 class="text-xl font-bold text-ink">{{ t('order.checkout.title') }}</h1>
      <span class="text-xs text-ink-muted">{{ items.length }} {{ t('order.checkout.kinds') }}</span>
    </div>

    <div v-if="loading" class="text-center text-ink-muted py-20">{{ t('product.detail.loading') }}</div>
    <div v-else-if="err && !items.length" class="text-center text-danger py-20">{{ err }}</div>

    <div v-else class="grid grid-cols-1 lg:grid-cols-3 gap-5">
      <!-- 左:商品明细 -->
      <div class="lg:col-span-2 space-y-3">
        <!-- 空购物车 -->
        <div v-if="!items.length" class="bg-white rounded-card border border-border p-16 text-center">
          <div class="text-5xl mb-3 opacity-40">🛒</div>
          <p class="text-sm text-ink-muted mb-4">{{ t('order.checkout.cartEmpty') }}</p>
          <router-link to="/" class="inline-block px-5 py-2 rounded-field bg-primary text-white text-sm font-medium hover:bg-primary-hover transition">{{ t('order.checkout.continueShopping') }}</router-link>
        </div>

        <!-- 商品行 -->
        <div v-for="(it, idx) in items" :key="it.product_id + '-' + (it.sku_id ?? '')"
          class="bg-white rounded-card border border-border p-4 flex gap-4 items-center">
          <router-link :to="`/product/${it.slug}`" class="w-16 h-16 rounded-field bg-gradient-to-br from-primary-soft to-primary-light flex items-center justify-center text-primary/40 text-xs flex-shrink-0 overflow-hidden">
            <img v-if="it.cover" :src="it.cover" class="w-full h-full object-cover" />
            <span v-else>{{ t('common.noImage') }}</span>
          </router-link>
          <div class="flex-1 min-w-0">
            <router-link :to="`/product/${it.slug}`" class="text-sm font-semibold text-ink line-clamp-2 leading-snug hover:text-primary transition">{{ it.name }}</router-link>
            <div v-if="it.sku_name" class="mt-1 inline-block px-2 py-0.5 rounded bg-surface-subtle text-[10px] text-ink-muted">{{ it.sku_name }}</div>
          </div>
          <div class="text-right shrink-0">
            <div class="text-price font-bold">{{ formatMoney(it.price_display, prefs.currentCurrency) }}</div>
            <div class="text-[10px] text-ink-muted">{{ t('order.checkout.unitPrice') }}</div>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <button @click="changeQty(idx, -1)" class="w-7 h-7 rounded-field border border-border text-ink-soft hover:bg-surface-subtle transition">−</button>
            <span class="w-8 text-center text-sm font-semibold">{{ it.qty }}</span>
            <button @click="changeQty(idx, 1)" class="w-7 h-7 rounded-field border border-border text-ink-soft hover:bg-surface-subtle transition">+</button>
          </div>
          <div class="w-24 text-right shrink-0">
            <div class="text-sm font-bold text-ink">{{ formatMoney(it.price_display * it.qty, prefs.currentCurrency) }}</div>
            <div class="text-[10px] text-ink-muted">{{ t('order.checkout.lineTotal') }}</div>
          </div>
          <button @click="removeItem(idx)" class="shrink-0 w-7 h-7 rounded-full text-ink-muted hover:text-danger hover:bg-red-50 transition" :title="t('common.remove')">✕</button>
        </div>

        <!-- 单品模式:SKU 选择(仅单商品) -->
        <div v-if="!isCartMode && singleProduct?.skus?.length" class="bg-white rounded-card border border-border p-4">
          <div class="text-xs font-semibold text-ink-soft mb-2">{{ t('product.detail.skuTitle') }}</div>
          <div class="flex flex-wrap gap-2">
            <button v-for="s in singleProduct.skus" :key="s.id" @click="selectedSku = s.id; onSkuChange()"
              :class="['border-2 rounded-card px-3 py-2 text-xs cursor-pointer transition', selectedSku === s.id ? 'border-primary bg-primary-light text-primary font-semibold' : 'border-border text-ink-soft hover:border-primary/40']">
              {{ s.name }}
              <span class="block text-price font-bold mt-0.5">{{ formatMoney(s.price_display ?? s.price, prefs.currentCurrency) }}</span>
            </button>
          </div>
        </div>

        <!-- 单品模式:动态控件 -->
        <div v-if="!isCartMode && controlFields.length" class="bg-white rounded-card border border-border p-4 space-y-3">
          <div v-for="f in controlFields" :key="f.name">
            <label class="text-xs font-semibold text-ink-soft">{{ f.label }} <span v-if="f.required" class="text-danger">*</span></label>
            <select v-if="f.type === 'select'" v-model="controlValues[f.name]"
              class="w-full mt-1 px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition bg-white">
              <option value="">{{ t('order.checkout.selectPlaceholder') }}</option>
              <option v-for="opt in (f.options || [])" :key="opt" :value="opt">{{ opt }}</option>
            </select>
            <textarea v-else-if="f.type === 'textarea'" v-model="controlValues[f.name]" rows="3"
              :placeholder="t('order.checkout.inputPlaceholder', { name: f.label })"
              class="w-full mt-1 px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition"></textarea>
            <input v-else v-model="controlValues[f.name]" :type="f.type" :placeholder="t('order.checkout.inputPlaceholder', { name: f.label })"
              class="w-full mt-1 px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
          </div>
        </div>
      </div>

      <!-- 右:结算摘要(sticky) -->
      <div class="lg:col-span-1">
        <div class="lg:sticky lg:top-4 space-y-4">
          <!-- 联系方式 -->
          <div class="bg-white rounded-card border border-border p-4">
            <h3 class="text-sm font-bold text-ink mb-3">{{ t('order.checkout.contactTitle') }}</h3>
            <input v-model="contact" :type="contactIsPhone ? 'tel' : 'email'"
              :placeholder="contactIsPhone ? t('order.checkout.phonePlaceholder') : t('order.checkout.emailPlaceholder')"
              class="w-full px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
            <div v-if="settings.config?.order_query_password" class="mt-2">
              <input v-model="password" type="password" :placeholder="t('order.checkout.queryPasswordPlaceholder')"
                class="w-full px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
            </div>
            <div v-if="needCaptcha" class="mt-2 flex gap-2">
              <input v-model="captcha" type="text" :placeholder="t('common.validation.fillCaptcha')" maxlength="6"
                class="flex-1 px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
              <img v-if="captchaSrc" :src="captchaSrc" @click="refreshCaptcha" class="h-9 cursor-pointer rounded-field border border-border"
                :alt="t('common.captcha')" :title="t('order.checkout.captchaRefreshTitle')" />
            </div>
            <div v-if="(singleProduct as any)?.only_user && !auth.isLoggedIn" class="mt-3 p-2 bg-orange-50 border border-orange-200 rounded text-xs text-orange-700">
              {{ t('order.checkout.onlyMemberHint') }} <router-link to="/login" class="text-primary underline">{{ t('order.checkout.onlyMemberLink') }}</router-link>
            </div>
          </div>

          <!-- 优惠券 -->
          <div class="bg-white rounded-card border border-border p-4">
            <h3 class="text-sm font-bold text-ink mb-3">{{ t('order.checkout.couponLabel') }}</h3>
            <div class="flex gap-2">
              <input v-model="couponCode" type="text" :placeholder="t('order.checkout.couponPlaceholder')" @blur="validateCoupon"
                class="flex-1 px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
              <button type="button" @click="validateCoupon" :disabled="couponChecking"
                class="px-3 py-2 text-xs bg-surface-subtle text-ink-soft rounded-field border border-border hover:bg-border transition whitespace-nowrap disabled:opacity-50">
                {{ couponChecking ? t('order.checkout.couponChecking') : t('order.checkout.couponValidate') }}
              </button>
            </div>
            <div v-if="couponMsg" :class="['text-xs mt-1.5', couponDiscount > 0 ? 'text-success' : 'text-danger']">{{ couponMsg }}</div>
          </div>

          <!-- 支付渠道 -->
          <div class="bg-white rounded-card border border-border p-4">
            <h3 class="text-sm font-bold text-ink mb-3">{{ t('order.checkout.payMethod') }}</h3>
            <div v-if="channels.length" class="space-y-2">
              <button v-for="ch in channels" :key="ch.id" type="button" @click="selectedChannelId = ch.id"
                :class="[
                  'w-full flex items-center gap-3 border rounded-card px-3 py-2.5 text-left transition',
                  selectedChannelId === ch.id ? 'border-primary bg-primary-light ring-2 ring-primary/15' : 'border-border hover:border-primary/40 hover:bg-primary-light/30'
                ]">
                <span :class="['w-4 h-4 rounded-full border-2 flex items-center justify-center shrink-0 transition', selectedChannelId === ch.id ? 'border-primary' : 'border-ink-muted/40']">
                  <span v-if="selectedChannelId === ch.id" class="w-2 h-2 rounded-full bg-primary"></span>
                </span>
                <span class="text-xl leading-none shrink-0">{{ ch.icon || '💳' }}</span>
                <span class="flex-1 min-w-0">
                  <span class="block text-sm font-medium text-ink">{{ channelLabel(ch) }}</span>
                  <span v-if="ch.target_currency" class="block text-[10px] text-ink-muted">{{ t('order.pay.receiveLabel', { symbol: '', code: ch.target_currency }) }}</span>
                </span>
              </button>
            </div>
            <div v-else class="text-xs text-ink-muted py-2">{{ t('order.pay.noChannels') }}</div>
          </div>

          <!-- 合计 + 提交 -->
          <div class="bg-white rounded-card border border-border p-4">
            <div class="flex justify-between items-center text-sm mb-1">
              <span class="text-ink-soft">{{ t('order.checkout.subtotal') }} ({{ items.length }})</span>
              <span class="text-ink font-semibold">{{ formatMoney(subtotal, prefs.currentCurrency) }}</span>
            </div>
            <div v-if="couponDiscount > 0" class="flex justify-between items-center text-sm mb-1 text-success">
              <span>{{ t('order.checkout.discount') }}</span>
              <span>-{{ formatMoney(couponDiscount * totalDisplayRatio, prefs.currentCurrency) }}</span>
            </div>
            <div class="flex justify-between items-center py-2 mt-2 border-t border-border">
              <span class="text-sm font-semibold text-ink">{{ t('order.checkout.payable') }}</span>
              <span class="text-2xl font-extrabold text-price">{{ formatMoney(totalDisplay, prefs.currentCurrency) }}</span>
            </div>

            <div v-if="err" class="text-danger text-xs mb-2">{{ err }}</div>

            <button @click="submit" :disabled="submitting || !items.length || !channels.length"
              class="w-full mt-2 bg-gradient-to-r from-primary to-primary-hover text-white font-bold py-3.5 rounded-card shadow-md hover:shadow-pop disabled:opacity-50 transition">
              {{ submitting ? t('order.checkout.submitting') : t('order.checkout.submitOrder', { amount: formatMoney(totalDisplay, prefs.currentCurrency) }) }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
