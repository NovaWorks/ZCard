<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { queryOrders, type OrderDetail } from '@/api/orders'
import { useSettingsStore } from '@/stores/settings'
import { formatMoney } from '@/utils/money'
import { usePreferencesStore } from '@/stores/preferences'

const { t } = useI18n()
const settings = useSettingsStore()
const prefs = usePreferencesStore()
// 确保配置已加载(直达本页时 config 可能为 null)
onMounted(() => { settings.load() })
const keyword = ref('')
const password = ref('')
const results = ref<OrderDetail[]>([])
const err = ref('')
const loading = false
const searched = ref(false)
const expanded = ref<Set<string>>(new Set())
/** 当前展开的 FAQ 索引,-1 表示全部收起 */
const faqOpen = ref(-1)

/** 发卡系统客户高频问题 */
const faqKeys = ['q1', 'q2', 'q3', 'q4', 'q5', 'q6'] as const
const faqList = computed(() => faqKeys.map(k => ({
  q: t(`order.query.faq.${k}`),
  a: t(`order.query.faq.a${k.slice(1)}`),
})))

const needPassword = computed(() => !!settings.config?.order_query_password)
const hasSearched = computed(() => searched.value)

const statusCls: Record<string, string> = {
  pending: 'bg-amber-100 text-amber-700',
  paid: 'bg-green-100 text-green-700',
  closed: 'bg-gray-100 text-gray-500',
  refunded: 'bg-red-100 text-red-700',
}
const statusText = (s: string) => t(`common.orderStatus.${s}`)
const statusClass = (s: string) => statusCls[s] || 'bg-gray-100 text-gray-500'

const fmtDate = (d?: string) => (d ? String(d).slice(0, 16).replace('T', ' ') : '')

async function search() {
  err.value = ''
  results.value = []
  if (!keyword.value.trim()) {
    err.value = t('order.query.enterKeyword')
    return
  }
  searched.value = true
  try {
    results.value = await queryOrders(keyword.value.trim(), password.value || undefined)
  } catch (e: any) {
    const msg = e?.response?.data?.message
    err.value = msg || t('order.query.notFound')
  }
}

function toggle(orderNo: string) {
  if (expanded.value.has(orderNo)) expanded.value.delete(orderNo)
  else expanded.value.add(orderNo)
}

async function copy(text: string) {
  try { await navigator.clipboard.writeText(text) } catch {}
}
async function copyAll(cards: string[]) {
  try { await navigator.clipboard.writeText(cards.join('\n')) } catch {}
}
</script>

<template>
  <div>
    <!-- 蓝色 Hero 搜索区 (居中靠上) -->
    <section class="bg-gradient-to-br from-primary-hover via-primary to-blue-500 text-white">
      <div class="mx-auto w-full max-w-2xl px-4 sm:px-6 pt-10 pb-14 text-center">
        <h1 class="text-3xl font-extrabold tracking-tight">{{ t('order.query.title') }}</h1>
        <p class="mt-2 text-white/85 text-sm">{{ t('order.query.subtitle') }}</p>

        <!-- 搜索框 -->
        <form @submit.prevent="search" class="mt-6">
          <div class="bg-white rounded-2xl shadow-pop p-2 flex items-center gap-2">
            <span class="pl-3 text-ink-muted text-xl shrink-0">🔍</span>
            <input
              v-model="keyword"
              type="text"
              :placeholder="t('order.query.searchPlaceholder')"
              class="flex-1 min-w-0 py-2.5 px-1 text-sm text-ink placeholder:text-ink-muted outline-none bg-transparent"
            />
            <button
              type="submit"
              class="bg-gradient-to-r from-primary to-primary-hover text-white font-semibold text-sm px-6 sm:px-8 py-2.5 rounded-xl hover:shadow-lg transition whitespace-nowrap shrink-0"
            >
              {{ t('order.query.searchButton') }}
            </button>
          </div>

          <!-- 查询密码(后台开启时显示) -->
          <div v-if="needPassword" class="mt-3 flex justify-center">
            <div class="flex items-center gap-2 bg-white rounded-2xl shadow-md px-4 py-2 max-w-full">
              <span class="text-sm font-semibold text-ink whitespace-nowrap">{{ t('order.query.passwordLabel') }}</span>
              <input
                v-model="password"
                type="password"
                :placeholder="t('order.query.passwordPlaceholder')"
                class="bg-transparent text-ink text-sm placeholder:text-ink-muted outline-none w-44 sm:w-56 min-w-0"
              />
            </div>
          </div>
        </form>

        <!-- 错误提示(浮于 Hero 底部) -->
        <div v-if="err" class="mt-2 inline-block bg-red-500/90 text-white text-xs px-4 py-1.5 rounded-full">
          ⚠ {{ err }}
        </div>
      </div>
    </section>

    <!-- 结果区:浅灰,与页脚同色连成一片,FAQ白卡悬浮其上 -->
    <div class="bg-surface-subtle pb-2">
      <div class="mx-auto w-full max-w-2xl px-4 sm:px-6 -mt-5 relative">
        <!-- 查询统计 -->
        <div v-if="hasSearched && !err && results.length" class="mb-3 pt-2 text-sm text-ink-soft">
          {{ t('order.query.totalCount', { n: results.length }) }}
        </div>

        <!-- 空状态 -->
        <div v-if="hasSearched && !err && !results.length" class="text-center py-14">
          <div class="text-6xl mb-4 opacity-30">🔍</div>
          <div class="text-ink-soft text-sm">{{ t('order.query.notFound') }}</div>
          <div class="text-ink-muted text-xs mt-1">{{ t('order.query.checkInput') }}</div>
        </div>

        <!-- 订单卡片列表 -->
        <div v-if="results.length" class="space-y-3">
          <div v-for="o in results" :key="o.order_no"
            class="bg-white rounded-card border border-border overflow-hidden hover:shadow-card-hover transition">

            <!-- 卡片头:商品信息 + 状态 -->
            <div class="flex items-start gap-3 p-4">
              <div class="w-14 h-14 rounded-field bg-gradient-to-br from-primary-soft to-primary-light flex items-center justify-center text-primary/40 text-xs shrink-0 overflow-hidden">
                <img v-if="o.product_cover" :src="o.product_cover" :alt="o.product_name"
                  class="w-full h-full object-cover rounded-field" />
                <span v-else>{{ t('common.noImage') }}</span>
              </div>
              <div class="flex-1 min-w-0">
                <div class="text-sm font-semibold text-ink truncate">{{ o.product_name || t('common.productOffShelf') }}</div>
                <div class="text-xs text-ink-muted mt-0.5">× {{ o.quantity }}</div>
                <div class="flex items-baseline gap-1 mt-1">
                  <span class="text-price font-extrabold">{{ formatMoney(o.amount_display ?? o.amount, prefs.currentCurrency) }}</span>
                </div>
              </div>
              <span class="text-xs font-semibold px-2.5 py-1 rounded-pill shrink-0" :class="statusClass(o.status)">
                {{ statusText(o.status) }}
              </span>
            </div>

            <!-- 订单元信息 -->
            <div class="flex flex-wrap justify-between items-center gap-x-2 gap-y-1 px-4 py-2.5 bg-surface-subtle border-t border-border text-[11px] text-ink-muted">
              <span class="font-mono break-all">{{ o.order_no }}</span>
              <span class="whitespace-nowrap">{{ fmtDate(o.paid_at || o.created_at) }}</span>
            </div>

            <!-- 卡密(已支付) -->
            <div v-if="o.status === 'paid' && o.cards.length" class="p-4">
              <button @click="toggle(o.order_no)"
                class="text-xs text-primary hover:text-primary-hover flex items-center gap-1.5 font-medium transition">
                <span>{{ expanded.has(o.order_no) ? t('order.query.cardExpanded') : t('order.query.cardCollapsed', { n: o.cards.length }) }}</span>
                <svg class="w-3 h-3 transition-transform" :class="expanded.has(o.order_no) ? 'rotate-180' : ''" viewBox="0 0 12 12" fill="none">
                  <path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>

              <div v-if="expanded.has(o.order_no)" class="mt-3 space-y-2">
                <div v-for="(card, i) in o.cards" :key="i"
                  class="flex items-center gap-2 bg-surface-subtle rounded-field p-2.5 group">
                  <span class="text-[10px] text-ink-muted w-5 shrink-0">#{{ i + 1 }}</span>
                  <code class="flex-1 text-xs text-ink break-all">{{ card }}</code>
                  <button @click="copy(card)"
                    class="text-primary text-xs shrink-0 hover:text-primary-hover transition opacity-60 group-hover:opacity-100">
                    {{ t('common.copy') }}
                  </button>
                </div>
                <button v-if="o.cards.length > 1" @click="copyAll(o.cards)"
                  class="text-xs text-ink-soft hover:text-primary transition mt-1 flex items-center gap-1">
                  {{ t('order.query.copyAllAll') }}
                </button>
              </div>
            </div>

            <!-- 待支付提示 -->
            <div v-else-if="o.status === 'pending'" class="px-4 py-3 text-xs text-amber-600 flex items-center gap-1.5">
              <span>⏳</span> {{ t('order.query.pendingHint') }}
            </div>
          </div>
        </div>

        <!-- 未查询时的引导(单卡片:流程 + 可展开 FAQ) -->
        <div v-if="!hasSearched" class="pt-2">
          <div class="bg-white rounded-card border border-border overflow-hidden">
            <!-- 上:横向流程(窄屏自动堆叠) -->
            <div class="px-4 py-3 grid grid-cols-3 gap-2 items-center">
              <div class="flex items-center justify-center gap-1.5 min-w-0">
                <span class="w-5 h-5 bg-primary text-white rounded-full flex items-center justify-center text-[10px] font-bold shrink-0">1</span>
                <span class="text-[11px] text-ink-soft truncate">{{ t('order.query.stepInput') }}</span>
              </div>
              <div class="flex items-center justify-center gap-1.5 min-w-0">
                <span class="w-5 h-5 bg-primary text-white rounded-full flex items-center justify-center text-[10px] font-bold shrink-0">2</span>
                <span class="text-[11px] text-ink-soft truncate">{{ t('order.query.stepSearch') }}</span>
              </div>
              <div class="flex items-center justify-center gap-1.5 min-w-0">
                <span class="w-5 h-5 bg-primary text-white rounded-full flex items-center justify-center text-[10px] font-bold shrink-0">3</span>
                <span class="text-[11px] text-ink-soft truncate">{{ t('order.query.stepView') }}</span>
              </div>
            </div>

            <!-- 下:常见问题(手风琴:标题默认可见,点击展开答案) -->
            <div class="border-t border-border">
              <div class="px-4 py-2 text-xs font-semibold text-ink flex items-center gap-2 bg-surface-subtle/50">
                <span>💡</span>{{ t('order.query.faqTitle') }}
              </div>
              <div class="divide-y divide-border">
                <div v-for="(item, idx) in faqList" :key="idx">
                  <button type="button" @click="faqOpen = faqOpen === idx ? -1 : idx"
                    class="w-full px-4 py-2.5 flex items-center justify-between text-left hover:bg-surface-subtle/50 transition">
                    <span class="text-[11px] font-medium text-ink-soft pr-2">{{ item.q }}</span>
                    <svg class="w-3 h-3 text-ink-muted transition-transform duration-200 shrink-0"
                      :class="faqOpen === idx ? 'rotate-180' : ''" viewBox="0 0 12 12" fill="none">
                      <path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                  </button>
                  <transition name="faq-slide">
                    <div v-show="faqOpen === idx" class="px-4 pb-3 overflow-hidden">
                      <p class="text-[11px] text-ink-muted leading-relaxed">{{ item.a }}</p>
                    </div>
                  </transition>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* FAQ 平滑展开动画 */
.faq-slide-enter-active,
.faq-slide-leave-active {
  transition: all 0.25s ease;
  max-height: 240px;
  overflow: hidden;
  opacity: 1;
}
.faq-slide-enter-from,
.faq-slide-leave-to {
  max-height: 0;
  opacity: 0;
  padding-top: 0;
  padding-bottom: 0;
}
</style>
