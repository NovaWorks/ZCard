<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { getProduct, type Product } from '@/api/products'
import { createOrder, createBatchOrders } from '@/api/orders'
import { getChannels, createPayment, createBatchPayment, balancePay, balanceBatchPay, type PaymentChannel, type PaymentResult } from '@/api/payments'
import { useSettingsStore } from '@/stores/settings'
import { useAuthStore } from '@/stores/auth'
import { formatMoney } from '@/utils/money'
import { calcChannelFee } from '@/utils/fee'
import { usePreferencesStore } from '@/stores/preferences'
import { useCartStore, type CartItem } from '@/stores/cart'
import AppIcon from '@/components/AppIcon.vue'
import PayBrandIcon from '@/components/PayBrandIcon.vue'
import { orderAccessTokensById, storeOrderAccessToken } from '@/utils/orderAccess'
import { navigateToPaymentUrl, submitPaymentForm } from '@/utils/paymentNavigation'

interface ControlField {
  type: string
  label: string
  name: string
  required: boolean
  options?: string[]
  option_labels?: Record<string, string>
  placeholder?: string
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
  /** 靓号自选:选定的卡密 id */
  card_id?: number | null
}

const isCartMode = computed(() => route.query.cart === '1' || cart.items.length > 0)
/** 靓号自选:单品模式下隐藏 SKU/数量选择 */
const singlePremium = computed(() => singleProduct.value?.pick_type === 'premium')
/** 展示货币:优先跟随后端返回的 display_currency,避免符号与金额错乱 */
const displayCur = computed(() => prefs.currencyOf(singleProduct.value?.display_currency))
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
const controlValues = ref<Record<string, any>>({})

const controlOptionLabel = (field: ControlField, option: string) =>
  field.option_labels?.[option] || option

// 支付渠道
const channels = ref<PaymentChannel[]>([])
const selectedChannelId = ref<number | null>(null)
const selectedPayType = ref<string | undefined>(undefined)
const submitting = ref(false)

/**
 * 查询密码是否必填:开启该功能且以游客身份下单时必填。
 * 登录用户可在「我的订单」凭账号读取卡密,不受此限制。
 */
const queryPasswordRequired = computed(() => !!settings.config?.order_query_password && !auth.isLoggedIn)

// 下单验证码
const needCaptcha = computed(() => !!settings.config?.trade_captcha)
const captchaSrc = ref('')
const captchaKey = ref('')
let captchaRequestId = 0
const refreshCaptcha = async () => {
  const requestId = ++captchaRequestId
  captcha.value = ''
  captchaKey.value = ''
  try {
    const res = await fetch(`/api/captcha/trade?${Date.now()}`)
    const data = await res.json()
    if (requestId !== captchaRequestId) return
    captchaSrc.value = data.src || ''
    captchaKey.value = data.key || ''
  } catch {
    if (requestId !== captchaRequestId) return
    captchaSrc.value = ''
    captchaKey.value = ''
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

  if (isCartMode.value) {
    if (!cart.items.length) {
      // 明确请求购物车模式但购物车为空 → 回退单品模式(URL 有 product)
      const slug = route.query.product as string
      if (slug) await loadSingle(slug)
    } else {
      items.value = cart.items.map((i) => ({ ...i }))
    }
  } else {
    const slug = route.query.product as string
    if (slug) await loadSingle(slug)
  }

  // 验证码最后生成并等待完成,避免首屏其他 API 请求覆盖首次 Session。
  if (needCaptcha.value) await refreshCaptcha()
  loading.value = false
})

async function loadSingle(slug: string) {
  try {
    singleProduct.value = await getProduct(slug)
    controlValues.value = Object.fromEntries(
      ((singleProduct.value as any).control_config || []).map((field: ControlField) => [
        field.name,
        field.type === 'checkbox' ? [] : '',
      ]),
    )
    selectedSku.value = route.query.sku ? Number(route.query.sku) : (singleProduct.value.skus?.[0]?.id ?? null)
    const sku = singleProduct.value.skus?.find((s) => s.id === selectedSku.value)
    const qty = route.query.qty ? Number(route.query.qty) : 1
    // 靓号自选:从 query.card_id 找到对应号码(分页结构,可能不在第一页 → 用 card_id 精查)
    let premiumPick = route.query.card_id
      ? (singleProduct.value.premium_numbers?.list || []).find((n) => String(n.card_id) === String(route.query.card_id))
      : null
    if (!premiumPick && route.query.card_id) {
      const fresh = await getProduct(slug, { card_id: String(route.query.card_id) })
      singleProduct.value = fresh
      premiumPick = (fresh.premium_numbers?.list || []).find((n) => String(n.card_id) === String(route.query.card_id))
    }
    items.value = [{
      product_id: singleProduct.value.id,
      sku_id: selectedSku.value,
      qty,
      slug: singleProduct.value.slug,
      name: singleProduct.value.name,
      cover: singleProduct.value.cover ?? null,
      price: premiumPick ? premiumPick.price : (sku ? sku.price : singleProduct.value.price),
      price_display: premiumPick ? (premiumPick.price_display ?? premiumPick.price) : (sku ? (sku.price_display ?? sku.price) : (singleProduct.value.price_display ?? singleProduct.value.price)),
      sku_name: premiumPick ? premiumPick.number : (sku?.name ?? null),
      card_id: premiumPick ? premiumPick.card_id : undefined,
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
/** 展示货币小计(无券时=应付总额;与商品行单价同口径) */
const subtotalDisplay = computed(() => items.value.reduce((n, i) => n + i.price_display * i.qty, 0))
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

/** 当前选中渠道(用于计算手续费) */
const selectedChannel = computed<PaymentChannel | null>(
  () => channels.value.find((c) => c.id === selectedChannelId.value) ?? null,
)

/** 手续费(展示货币分):仅客户承担时展示并计入应付 */
const channelFee = computed(() => {
  // 应付总额(展示货币,含优惠券折扣)作为手续费计算基数
  const base = Math.max(0, totalDisplay.value)
  return calcChannelFee(base, selectedChannel.value)
})

/** 含手续费的最终应付(展示货币分):客户承担时=应付+手续费 */
const finalPayDisplay = computed(() => {
  const fee = calcChannelFee(totalDisplay.value, selectedChannel.value)
  return fee.payFen
})

/** 是否需展示手续费明细(客户承担且手续费>0) */
const showFeeDetail = computed(() => channelFee.value.feeFen > 0 && selectedChannel.value?.fee_bearer === 'customer')

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

/** 数量增减(购物车模式同步 store;靓号行固定 1 个不允许增减) */
function changeQty(idx: number, delta: number) {
  const it = items.value[idx]
  if (!it || it.card_id) return
  const next = it.qty + delta
  if (next < 1) return
  it.qty = next
  if (isCartMode.value) cart.updateQty(it.product_id, it.sku_id, next, it.card_id)
}
function removeItem(idx: number) {
  const it = items.value[idx]
  if (!it) return
  if (isCartMode.value) {
    cart.remove(it.product_id, it.sku_id, it.card_id)
    items.value = cart.items.map((i) => ({ ...i }))
  } else {
    items.value.splice(idx, 1)
  }
}

async function loadChannels() {
  try {
    channels.value = await getChannels()
    if (channels.value.length && selectedChannelId.value === null) {
      const first = channels.value[0]
      selectedChannelId.value = first.id
      selectedPayType.value = first.code === 'epay' ? first.pay_types?.[0] : undefined
    }
  } catch {
    channels.value = []
  }
}

/** 支付结果:跳转链接 / 展示二维码 / 自动提交表单 */
function handleResult(result: PaymentResult, orderNo = '') {
  if (result.type === 'redirect' && result.redirect_url) {
    if (navigateToPaymentUrl(result.redirect_url)) return
  }
  if (result.type === 'qrcode' && result.qrcode_content) {
    // 二维码场景:跳结果页展示(带订单号供轮询;结果页渲染二维码)
    router.push({ path: '/pay/result', query: { order_no: orderNo, qrcode: result.qrcode_content } })
    return
  }
  if (result.type === 'form' && result.form_html) {
    if (submitPaymentForm(result.form_html)) return
  }
  err.value = t('order.pay.payUnknown')
}

async function submit() {
  if (!items.value.length) { err.value = t('order.checkout.cartEmpty'); return }
  if (selectedChannelId.value === null) { err.value = t('order.checkout.selectChannel'); return }
  if (!auth.isLoggedIn && !guestCheckoutAllowed.value) {
    err.value = t('order.checkout.guestOnlyHint'); return
  }
  if (!contact.value.trim()) { err.value = t('order.checkout.fillContact'); return }
  // 游客订单的另一条读取凭证只存在本浏览器,换设备即失效 → 查询密码开启时必填
  if (queryPasswordRequired.value && password.value.trim().length < 6) {
    err.value = t('order.checkout.fillQueryPassword'); return
  }
  if (needCaptcha.value && !captcha.value) {
    err.value = t('common.validation.fillCaptcha'); return
  }
  // 单品模式:校验必填控件
  for (const f of controlFields.value) {
    const value = controlValues.value[f.name]
    const empty = Array.isArray(value) ? value.length === 0 : !String(value ?? '').trim()
    if (f.required && empty) {
      err.value = t('order.checkout.fillField', { name: f.label })
      return
    }
  }

  // 结算确认:含靓号自选时先弹确认框(展示所选号码+单价)
  const premiumLines = items.value.filter((i) => i.card_id)
  if (premiumLines.length) {
    confirmItems.value = premiumLines
    confirmVisible.value = true
    return
  }

  await doSubmit()
}

async function doSubmit() {
  if (selectedChannelId.value === null) return
  const channelId = selectedChannelId.value
  const isBalance = selectedChannel.value?.code === 'balance'
  // 余额支付必须登录
  if (isBalance && !auth.isLoggedIn) {
    err.value = t('order.checkout.guestOnlyHint')
    return
  }
  err.value = ''
  submitting.value = true
  try {
    if (isCartMode.value) {
      const res = await createBatchOrders({
        items: items.value.map((i) => ({
          product_id: i.product_id,
          sku_id: i.sku_id ?? undefined,
          qty: i.qty,
          card_id: i.card_id ?? undefined,
        })),
        contact: contact.value,
        password: password.value || undefined,
        captcha: needCaptcha.value ? captcha.value : undefined,
        captcha_key: needCaptcha.value ? captchaKey.value : undefined,
        coupon_code: couponCode.value.trim() || undefined,
        extra: undefined,
      })
      res.orders.forEach((order) => storeOrderAccessToken(order.order_no, order.access_token, contact.value))
      if (isBalance) {
        await balanceBatchPay(res.order_ids)
        cart.clear()
        router.push({ path: '/pay/result', query: { order_no: res.orders[0]?.order_no || '' } })
        return
      }
      const result = await createBatchPayment(
        res.order_ids,
        channelId,
        selectedPayType.value,
        orderAccessTokensById(res.orders),
      )
      cart.clear()
      handleResult(result, res.orders[0]?.order_no || '')
    } else {
      const it = items.value[0]
      const res = await createOrder({
        product_id: it.product_id,
        sku_id: it.sku_id ?? undefined,
        qty: it.qty,
        card_id: it.card_id ?? undefined,
        contact: contact.value,
        password: password.value || undefined,
        captcha: needCaptcha.value ? captcha.value : undefined,
        captcha_key: needCaptcha.value ? captchaKey.value : undefined,
        coupon_code: couponCode.value.trim() || undefined,
        extra: { ...controlValues.value },
      } as any)
      storeOrderAccessToken(res.order_no, res.access_token, contact.value)
      if (isBalance) {
        await balancePay(res.order_no)
        router.push({ path: '/pay/result', query: { order_no: res.order_no } })
        return
      }
      const result = await createPayment(
        res.order_no,
        channelId,
        selectedPayType.value,
        res.access_token,
      )
      handleResult(result, res.order_no)
    }
  } catch (e: any) {
    err.value = e?.response?.data?.message || t('order.checkout.submitFailed')
    if (needCaptcha.value) refreshCaptcha()
  } finally {
    submitting.value = false
  }
}

const channelLabel = (ch: PaymentChannel) => ch.name

/** 结算确认弹窗(含靓号自选时提交前展示所选号码+单价) */
const confirmVisible = ref(false)
const confirmItems = ref<LineItem[]>([])
function doSubmitConfirmed() {
  confirmVisible.value = false
  doSubmit()
}

/** 支付方式标识 → 展示信息(图标/名称)。参考 dujiao-next/acg-faka 收银台:显示具体支付方式而非通道名 */
const PAY_TYPE_META: Record<string, { icon: string; label: string }> = {
  alipay: { icon: 'alipay', label: '支付宝' },
  wechat: { icon: 'wechat', label: '微信支付' },
  wxpay: { icon: 'wechat', label: '微信支付' },
  qqpay: { icon: 'qqpay', label: 'QQ 钱包' },
  bank: { icon: 'bank', label: '云闪付 / 网银' },
  jdpay: { icon: 'jdpay', label: '京东支付' },
  paypal: { icon: 'paypal', label: 'PayPal' },
  stripe: { icon: 'stripe', label: 'Stripe' },
  usdt: { icon: 'usdt', label: 'USDT' },
  tron: { icon: 'usdt', label: 'TRON' },
  trx: { icon: 'usdt', label: 'TRX' },
  balance: { icon: 'balance', label: '余额支付' },
  h5: { icon: 'alipay', label: '手机网站支付' },
  scan: { icon: 'alipay', label: '当面付扫码' },
  pos: { icon: 'alipay', label: '刷卡支付' },
}

/** 通道对应的支付方式列表(带图标与名称);无 pay_types 时回退到通道自身 */
const channelPayTypes = (ch: PaymentChannel) => {
  const types = (ch.pay_types || []).filter(Boolean)
  if (types.length) {
    return types.map((t) => ({
      type: t,
      icon: PAY_TYPE_META[t]?.icon || ch.icon || 'ri:bank-card-2-line',
      label: PAY_TYPE_META[t]?.label || t,
    }))
  }
  return [{ type: ch.code, icon: ch.icon || 'ri:bank-card-2-line', label: channelLabel(ch) }]
}

const paymentOptions = computed(() => channels.value.flatMap((channel) => {
  const types = channelPayTypes(channel)
  if (channel.code === 'epay') {
    return types.map((payType) => ({
      key: `${channel.id}:${payType.type}`,
      channel,
      payType: payType.type as string | undefined,
      types: [payType],
    }))
  }
  return [{ key: String(channel.id), channel, payType: undefined as string | undefined, types }]
}))

const selectedPaymentKey = computed(() =>
  selectedChannelId.value === null
    ? ''
    : `${selectedChannelId.value}${selectedPayType.value ? `:${selectedPayType.value}` : ''}`
)

const selectPaymentOption = (channel: PaymentChannel, payType?: string) => {
  selectedChannelId.value = channel.id
  selectedPayType.value = payType
}
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
          <div class="text-5xl mb-3 opacity-40"><AppIcon name="ri:shopping-cart-2-line" class="w-12 h-12" /></div>
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
            <div class="text-price font-bold">{{ formatMoney(it.price_display, displayCur) }}</div>
            <div class="text-[10px] text-ink-muted">{{ t('order.checkout.unitPrice') }}</div>
          </div>
          <div class="flex items-center gap-2 shrink-0">
            <button @click="changeQty(idx, -1)" class="w-7 h-7 rounded-field border border-border text-ink-soft hover:bg-surface-subtle transition">−</button>
            <span class="w-8 text-center text-sm font-semibold">{{ it.qty }}</span>
            <button @click="changeQty(idx, 1)" class="w-7 h-7 rounded-field border border-border text-ink-soft hover:bg-surface-subtle transition">+</button>
          </div>
          <div class="w-24 text-right shrink-0">
            <div class="text-sm font-bold text-ink">{{ formatMoney(it.price_display * it.qty, displayCur) }}</div>
            <div class="text-[10px] text-ink-muted">{{ t('order.checkout.lineTotal') }}</div>
          </div>
          <button @click="removeItem(idx)" class="shrink-0 w-7 h-7 rounded-full text-ink-muted hover:text-danger hover:bg-red-50 transition inline-flex items-center justify-center" :title="t('common.remove')"><AppIcon name="ri:close-line" class="w-4 h-4" /></button>
        </div>

        <!-- 单品模式:SKU 选择(仅单商品;靓号自选无 SKU) -->
        <div v-if="!isCartMode && !singlePremium && singleProduct?.skus?.length" class="bg-white rounded-card border border-border p-4">
          <div class="text-xs font-semibold text-ink-soft mb-2">{{ t('product.detail.skuTitle') }}</div>
          <div class="flex flex-wrap gap-2">
            <button v-for="s in singleProduct.skus" :key="s.id" @click="selectedSku = s.id; onSkuChange()"
              :class="['border-2 rounded-card px-3 py-2 text-xs cursor-pointer transition', selectedSku === s.id ? 'border-primary bg-primary-light text-primary font-semibold' : 'border-border text-ink-soft hover:border-primary/40']">
              {{ s.name }}
              <span class="block text-price font-bold mt-0.5">{{ formatMoney(s.price_display ?? s.price, displayCur) }}</span>
            </button>
          </div>
        </div>

        <!-- 单品模式:动态控件 -->
        <div v-if="!isCartMode && controlFields.length" class="bg-white rounded-card border border-border p-4 space-y-3">
          <div v-for="f in controlFields" :key="f.name">
            <label class="text-xs font-semibold text-ink-soft">{{ f.label }} <span v-if="f.required" class="text-danger">*</span></label>
            <select v-if="f.type === 'select' || f.type === 'radio'" v-model="controlValues[f.name]"
              class="w-full mt-1 px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition bg-white">
              <option value="">{{ t('order.checkout.selectPlaceholder') }}</option>
              <option v-for="opt in (f.options || [])" :key="opt" :value="opt">{{ controlOptionLabel(f, opt) }}</option>
            </select>
            <div v-else-if="f.type === 'checkbox'" class="mt-2 flex flex-wrap gap-3">
              <label v-for="opt in (f.options || [])" :key="opt" class="inline-flex items-center gap-1.5 text-sm text-ink-soft">
                <input v-model="controlValues[f.name]" type="checkbox" :value="opt" class="rounded border-border text-primary focus:ring-primary/30" />
                <span>{{ controlOptionLabel(f, opt) }}</span>
              </label>
            </div>
            <textarea v-else-if="f.type === 'textarea'" v-model="controlValues[f.name]" rows="3"
              :placeholder="f.placeholder || t('order.checkout.inputPlaceholder', { name: f.label })"
              class="w-full mt-1 px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition"></textarea>
            <input v-else v-model="controlValues[f.name]" :type="f.type" :placeholder="f.placeholder || t('order.checkout.inputPlaceholder', { name: f.label })"
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
              <input v-model="password" type="password"
                :placeholder="queryPasswordRequired ? t('order.checkout.queryPasswordRequiredPlaceholder') : t('order.checkout.queryPasswordPlaceholder')"
                class="w-full px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
              <p v-if="queryPasswordRequired" class="mt-1 text-xs text-ink-muted">{{ t('order.checkout.queryPasswordHint') }}</p>
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
            <div v-if="paymentOptions.length" class="space-y-2">
              <button
                v-for="option in paymentOptions"
                :key="option.key"
                type="button"
                @click="selectPaymentOption(option.channel, option.payType)"
                :class="[
                  'w-full flex items-center gap-3 border rounded-card px-3 py-2.5 text-left transition',
                  selectedPaymentKey === option.key
                    ? 'border-primary bg-primary-light ring-2 ring-primary/15'
                    : 'border-border hover:border-primary/40 hover:bg-primary-light/30'
                ]"
              >
                <span
                  :class="[
                    'w-4 h-4 rounded-full border-2 flex items-center justify-center shrink-0 transition',
                    selectedPaymentKey === option.key ? 'border-primary' : 'border-ink-muted/40'
                  ]"
                >
                  <span v-if="selectedPaymentKey === option.key" class="w-2 h-2 rounded-full bg-primary"></span>
                </span>
                <!-- 支付方式(按 pay_types 展示图标+名称,非通道名) -->
                <span class="flex items-center gap-2 shrink-0">
                  <template v-for="pt in option.types" :key="pt.type">
                    <span class="w-8 h-8 rounded-lg bg-surface-subtle flex items-center justify-center text-lg">
                      <PayBrandIcon :brand="pt.icon" :size="18" />
                    </span>
                  </template>
                </span>
                <span class="flex-1 min-w-0">
                  <span class="block text-sm font-medium text-ink">
                    {{ option.types.map((p) => p.label).join(' / ') }}
                  </span>
                  <span
                    v-if="option.channel.code === 'balance' && option.channel.balance !== undefined"
                    class="block text-[10px] text-ink-muted"
                  >{{ t('order.checkout.balanceLabel', { amount: formatMoney(option.channel.balance, null) }) }}</span
                  >
                  <span
                    v-else-if="option.channel.target_currency"
                    class="block text-[10px] text-ink-muted"
                    >{{ t('order.pay.receiveLabel', { symbol: '', code: option.channel.target_currency }) }}</span
                  >
                </span>
              </button>
            </div>
            <div v-else class="text-xs text-ink-muted py-2">{{ t('order.pay.noChannels') }}</div>
          </div>

          <!-- 合计 + 提交 -->
          <div class="bg-white rounded-card border border-border p-4">
            <div class="flex justify-between items-center text-sm mb-1">
              <span class="text-ink-soft">{{ t('order.checkout.subtotal') }} ({{ items.length }})</span>
              <span class="text-ink font-semibold">{{ formatMoney(subtotalDisplay, displayCur) }}</span>
            </div>
            <div v-if="couponDiscount > 0" class="flex justify-between items-center text-sm mb-1 text-success">
              <span>{{ t('order.checkout.discount') }}</span>
              <span>-{{ formatMoney(couponDiscount * totalDisplayRatio, displayCur) }}</span>
            </div>
            <!-- 手续费明细:仅客户承担时展示 -->
            <div v-if="showFeeDetail" class="flex justify-between items-center text-sm mb-1 text-ink-muted">
              <span>{{ t('order.checkout.feeLabel') }}</span>
              <span>+{{ formatMoney(channelFee.feeFen, displayCur) }}</span>
            </div>
            <div class="flex justify-between items-center py-2 mt-2 border-t border-border">
              <span class="text-sm font-semibold text-ink">{{ t('order.checkout.payable') }}</span>
              <span class="text-2xl font-extrabold text-price">{{ formatMoney(finalPayDisplay, displayCur) }}</span>
            </div>

            <div v-if="err" class="text-danger text-xs mb-2">{{ err }}</div>

            <button @click="submit" :disabled="submitting || !items.length || !channels.length"
              class="w-full mt-2 bg-gradient-to-r from-primary to-primary-hover text-white font-bold py-3.5 rounded-card shadow-md hover:shadow-pop disabled:opacity-50 transition">
              {{ submitting ? t('order.checkout.submitting') : t('order.checkout.submitOrder', { amount: formatMoney(finalPayDisplay, displayCur) }) }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- 结算确认弹窗:含靓号自选时提交前展示所选号码+单价 -->
    <Teleport to="body">
      <div
        v-if="confirmVisible"
        class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4"
        @click.self="confirmVisible = false"
      >
        <div class="w-full max-w-md bg-white rounded-card shadow-2xl overflow-hidden">
          <div class="px-5 py-3.5 border-b border-border">
            <h3 class="text-base font-bold text-ink">{{ t('product.premium.buyConfirmTitle') }}</h3>
          </div>
          <div class="px-5 py-4 max-h-[45vh] overflow-y-auto">
            <div v-for="(line, idx) in confirmItems" :key="line.card_id ?? `c${idx}`" class="flex items-center justify-between gap-3 py-2 border-b border-border last:border-0">
              <div class="min-w-0">
                <div class="text-sm font-semibold text-ink font-mono truncate">{{ line.sku_name }}</div>
                <div class="text-[10px] text-ink-muted">{{ line.name }}</div>
              </div>
              <span class="text-price font-bold text-sm shrink-0">{{ formatMoney(line.price_display, displayCur) }}</span>
            </div>
          </div>
          <div class="flex gap-2 px-5 py-3.5 border-t border-border">
            <button
              @click="confirmVisible = false"
              class="flex-1 border border-border text-ink-soft font-medium py-2.5 rounded-card hover:bg-surface-subtle transition text-sm"
            >{{ t('common.cancel') }}</button>
            <button
              @click="doSubmitConfirmed"
              :disabled="submitting"
              class="flex-1 bg-gradient-to-r from-primary to-primary-hover text-white font-bold py-2.5 rounded-card shadow-md hover:shadow-pop transition text-sm disabled:opacity-50"
            >{{ submitting ? t('order.checkout.submitting') : t('product.premium.confirmBtn') }}</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
