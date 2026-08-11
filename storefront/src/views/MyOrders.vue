<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { getMyOrders, type OrderDetail } from '@/api/orders'
import { createReview } from '@/api/reviews'
import { useSettingsStore } from '@/stores/settings'
import { formatMoney } from '@/utils/money'
import { usePreferencesStore } from '@/stores/preferences'

const router = useRouter()
const { t } = useI18n()
const prefs = usePreferencesStore()
const settings = useSettingsStore()
const list = ref<OrderDetail[]>([])
const loading = ref(true)
const err = ref('')
// 已展开卡密的订单号集合
const expanded = ref<Set<string>>(new Set())

const statusText = (s: string) => t(`common.orderStatus.${s}`)

const statusClass = (s: string) => ({
  pending: 'bg-orange-100 text-orange-700',
  paid: 'bg-green-100 text-green-700',
  closed: 'bg-gray-100 text-gray-600',
  refunded: 'bg-red-100 text-red-700',
}[s] || 'bg-gray-100 text-gray-600')

const fmtDate = (d?: string) => {
  if (!d) return ''
  // 后端返回带时区 ISO8601(如 '2026-08-11T05:30:32+00:00'),截取到分钟展示
  return String(d).slice(0, 16).replace('T', ' ')
}

function toggle(orderNo: string) {
  if (expanded.value.has(orderNo)) expanded.value.delete(orderNo)
  else expanded.value.add(orderNo)
}

function copy(text: string) {
  navigator.clipboard.writeText(text)
}

async function copyAll(cards: string[]) {
  await navigator.clipboard.writeText(cards.join('\n'))
}

// ===== 评价 =====
const reviewVisible = ref(false)
const reviewOrder = ref<OrderDetail | null>(null)
const reviewRating = ref(5)
const reviewContent = ref('')
const reviewSubmitting = ref(false)
const reviewMsg = ref('')

function openReview(o: OrderDetail) {
  reviewOrder.value = o
  reviewRating.value = 5
  reviewContent.value = ''
  reviewMsg.value = ''
  reviewVisible.value = true
}

async function submitReview() {
  if (!reviewOrder.value || !reviewOrder.value.product_id) return
  reviewSubmitting.value = true
  reviewMsg.value = ''
  try {
    await createReview({
      product_id: reviewOrder.value.product_id,
      order_id: reviewOrder.value.id ?? 0,
      rating: reviewRating.value,
      content: reviewContent.value.trim() || undefined,
    } as any)
    reviewMsg.value = t('order.myOrders.reviewSuccess')
    // 标记已评价
    const target = list.value.find(o => o.order_no === reviewOrder.value?.order_no)
    if (target) target.reviewed = true
    setTimeout(() => { reviewVisible.value = false }, 1200)
  } catch (e: any) {
    reviewMsg.value = e?.response?.data?.message || t('order.myOrders.reviewFailed')
  } finally {
    reviewSubmitting.value = false
  }
}

onMounted(async () => {
  try {
    list.value = await getMyOrders()
  } catch (e: any) {
    err.value = e?.response?.data?.message || t('order.myOrders.loadFailed')
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="max-w-3xl mx-auto px-4 py-6">
    <h1 class="text-xl font-bold text-ink mb-4">{{ t('order.myOrders.title') }}</h1>

    <div v-if="loading" class="text-center text-ink-muted py-20">{{ t('product.detail.loading') }}</div>
    <div v-else-if="err" class="text-center text-danger py-20">{{ err }}</div>
    <div v-else-if="!list.length" class="text-center text-ink-muted py-20">{{ t('order.myOrders.noOrders') }}</div>

    <div v-else class="space-y-3">
      <div v-for="o in list" :key="o.order_no"
        class="bg-white rounded-card border border-border p-4">
        <!-- 头部:商品 + 状态 -->
        <div class="flex items-start gap-3">
          <img v-if="o.product_cover" :src="o.product_cover" :alt="o.product_name"
            class="w-14 h-14 rounded-field object-cover bg-surface-subtle shrink-0" />
          <div v-else class="w-14 h-14 rounded-field bg-surface-subtle shrink-0 flex items-center justify-center text-ink-muted text-xs">
            {{ t('common.noImage') }}
          </div>

          <div class="flex-1 min-w-0">
            <div class="text-sm font-semibold text-ink truncate">{{ o.product_name || t('common.productOffShelf') }}</div>
            <div class="text-xs text-ink-muted mt-0.5">× {{ o.quantity }}</div>
            <div class="text-price font-bold mt-1">{{ formatMoney(o.amount_display ?? o.amount, prefs.currencyOf(o.display_currency)) }}</div>
          </div>

          <span class="text-xs font-bold px-2 py-0.5 rounded-pill shrink-0"
            :class="statusClass(o.status)">
            {{ statusText(o.status) }}
          </span>
        </div>

        <!-- 订单号 + 时间 -->
        <div class="flex justify-between items-center mt-3 pt-2 border-t border-border">
          <span class="text-[11px] text-ink-muted">{{ o.order_no }}</span>
          <span class="text-[11px] text-ink-muted">{{ fmtDate(o.paid_at || o.created_at) }}</span>
        </div>

        <!-- 卡密(已支付且有卡) -->
        <div v-if="o.status === 'paid' && o.cards.length" class="mt-3">
          <button @click="toggle(o.order_no)"
            class="text-xs text-primary hover:text-primary-hover flex items-center gap-1 transition">
            {{ expanded.has(o.order_no) ? t('order.myOrders.cardExpanded') : t('order.myOrders.cardCollapsed', { n: o.cards.length }) }}
            <span class="text-[10px]">{{ expanded.has(o.order_no) ? '▲' : '▼' }}</span>
          </button>

          <div v-if="expanded.has(o.order_no)" class="mt-2 space-y-2">
            <div v-for="(card, i) in o.cards" :key="i" class="flex items-center gap-2">
              <code class="flex-1 text-xs bg-surface-subtle p-2 rounded-field break-all">{{ card }}</code>
              <button @click="copy(card)" class="text-primary text-xs shrink-0 hover:text-primary-hover transition">{{ t('common.copy') }}</button>
            </div>
            <button v-if="o.cards.length > 1" @click="copyAll(o.cards)"
              class="text-xs text-ink-soft underline mt-1 hover:text-primary transition">{{ t('common.copyAll') }}</button>
          </div>
        </div>

        <!-- 待支付:提示 + 去支付按钮(跳转支付页,支持第三方/余额支付) -->
        <div v-else-if="o.status === 'pending'" class="mt-3 pt-3 border-t border-border flex items-center justify-between gap-3">
          <span class="text-xs text-orange-600">{{ t('order.myOrders.pendingHint') }}</span>
          <button @click="router.push('/pay/' + o.order_no)"
            class="shrink-0 px-4 py-1.5 rounded-pill bg-primary text-white text-xs font-medium hover:bg-primary-hover transition">
            {{ t('order.myOrders.payNow') }}
          </button>
        </div>

        <!-- 评价入口:已支付 + 未评价 + 后台允许评价 -->
        <div v-if="o.status === 'paid' && !o.reviewed && settings.config?.allow_post_review" class="mt-3 pt-3 border-t border-border flex justify-end">
          <button @click="openReview(o)"
            class="px-4 py-1.5 rounded-pill border border-primary text-primary text-xs font-medium hover:bg-primary-light transition">
            {{ t('order.myOrders.reviewBtn') }}
          </button>
        </div>
        <div v-else-if="o.status === 'paid' && o.reviewed" class="mt-3 pt-3 border-t border-border flex justify-end">
          <span class="text-xs text-ink-muted">{{ t('order.myOrders.reviewedLabel') }}</span>
        </div>
      </div>
    </div>

    <!-- 评价弹窗 -->
    <Teleport to="body">
      <div v-if="reviewVisible" class="fixed inset-0 z-[90] flex items-center justify-center p-4 bg-black/50" @click.self="reviewVisible = false">
        <div class="bg-white rounded-card shadow-pop max-w-md w-full overflow-hidden">
          <div class="flex items-center justify-between px-5 py-3 border-b border-border bg-surface-subtle">
            <span class="text-sm font-bold text-ink">{{ t('order.myOrders.reviewTitle') }}</span>
            <button @click="reviewVisible = false" class="w-7 h-7 rounded-full flex items-center justify-center text-ink-muted hover:bg-border hover:text-ink transition text-lg leading-none">×</button>
          </div>
          <div class="px-5 py-4">
            <div v-if="reviewOrder" class="text-xs text-ink-muted mb-3">{{ reviewOrder.product_name }} · {{ reviewOrder.order_no }}</div>
            <!-- 星级选择 -->
            <div class="flex items-center gap-1 mb-4">
              <span class="text-xs text-ink-soft mr-2">{{ t('order.myOrders.reviewRatingLabel') }}</span>
              <button v-for="n in 5" :key="n" type="button" @click="reviewRating = n" class="text-2xl leading-none transition">
                <span :class="n <= reviewRating ? 'text-orange-400' : 'text-border'">{{ n <= reviewRating ? '★' : '★' }}</span>
              </button>
            </div>
            <!-- 内容 -->
            <textarea v-model="reviewContent" :placeholder="t('order.myOrders.reviewContentPlaceholder')"
              rows="3" maxlength="1000"
              class="w-full px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition"></textarea>
            <div v-if="reviewMsg" :class="['mt-2 text-xs', reviewMsg.includes('成功') || reviewMsg === t('order.myOrders.reviewSuccess') ? 'text-success' : 'text-danger']">{{ reviewMsg }}</div>
          </div>
          <div class="flex justify-end gap-2 px-5 py-3 border-t border-border">
            <button @click="reviewVisible = false" class="px-4 py-1.5 rounded-field border border-border text-ink-soft text-xs hover:bg-surface-subtle transition">{{ t('common.cancel') }}</button>
            <button @click="submitReview" :disabled="reviewSubmitting"
              class="px-4 py-1.5 rounded-field bg-primary text-white text-xs font-medium hover:bg-primary-hover transition disabled:opacity-50">
              {{ reviewSubmitting ? t('order.myOrders.reviewSubmitting') : t('order.myOrders.reviewSubmit') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
