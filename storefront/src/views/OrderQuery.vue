<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { queryOrders, type OrderDetail } from '@/api/orders'
import { useSettingsStore } from '@/stores/settings'
import { formatMoney } from '@/utils/money'
import { usePreferencesStore } from '@/stores/preferences'

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
const faqList = [
  { q: '下单后多久能收到卡密?', a: '付款成功后系统自动发货,通常几秒内即可在订单中查看并复制卡密。如遇高峰可能略有延迟,请耐心刷新订单页面。' },
  { q: '找不到订单怎么办?', a: '请确认输入的订单号或联系方式与下单时完全一致。使用邮箱或手机号可查询该联系方式下的全部历史订单。' },
  { q: '已付款但看不到卡密?', a: '在订单卡片中点击「查看卡密」即可展开并复制。若订单显示已支付但仍无卡密内容,可能是库存补货中,请联系在线客服处理。' },
  { q: '订单各状态是什么意思?', a: '待支付:订单已创建但未完成付款;已支付:付款成功,卡密已自动发货;已关闭:超时未支付,系统自动取消并释放库存。' },
  { q: '购买的卡密无法使用怎么办?', a: '请先核对卡密是否完整复制(注意前后空格)。若确认卡密无效,请保留订单号和卡密截图,第一时间联系客服,核实后可补发或退款。' },
  { q: '可以申请退款吗?', a: '虚拟商品具有可复制性,原则上卡密一经查看不支持无理由退款。如遇卡密本身质量问题,请联系客服核实处理。' },
]

const needPassword = computed(() => !!settings.config?.order_query_password)
const hasSearched = computed(() => searched.value)

const statusMeta: Record<string, { text: string; cls: string }> = {
  pending: { text: '待支付', cls: 'bg-amber-100 text-amber-700' },
  paid: { text: '已支付', cls: 'bg-green-100 text-green-700' },
  closed: { text: '已关闭', cls: 'bg-gray-100 text-gray-500' },
  refunded: { text: '已退款', cls: 'bg-red-100 text-red-700' },
}
const statusText = (s: string) => statusMeta[s]?.text || s
const statusCls = (s: string) => statusMeta[s]?.cls || 'bg-gray-100 text-gray-500'

const fmtDate = (d?: string) => (d ? String(d).slice(0, 16).replace('T', ' ') : '')

async function search() {
  err.value = ''
  results.value = []
  if (!keyword.value.trim()) {
    err.value = '请输入订单号或联系方式'
    return
  }
  searched.value = true
  try {
    results.value = await queryOrders(keyword.value.trim(), password.value || undefined)
  } catch (e: any) {
    const msg = e?.response?.data?.message
    err.value = msg || '未找到相关订单'
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
        <h1 class="text-3xl font-extrabold tracking-tight">订单查询</h1>
        <p class="mt-2 text-white/85 text-sm">输入订单号或联系方式,查询全部历史订单</p>

        <!-- 搜索框 -->
        <form @submit.prevent="search" class="mt-6">
          <div class="bg-white rounded-2xl shadow-pop p-2 flex items-center gap-2">
            <span class="pl-3 text-ink-muted text-xl shrink-0">🔍</span>
            <input
              v-model="keyword"
              type="text"
              placeholder="输入订单号 / 邮箱 / 手机号"
              class="flex-1 min-w-0 py-2.5 px-1 text-sm text-ink placeholder:text-ink-muted outline-none bg-transparent"
            />
            <button
              type="submit"
              class="bg-gradient-to-r from-primary to-primary-hover text-white font-semibold text-sm px-6 sm:px-8 py-2.5 rounded-xl hover:shadow-lg transition whitespace-nowrap shrink-0"
            >
              查询
            </button>
          </div>

          <!-- 查询密码(后台开启时显示) -->
          <div v-if="needPassword" class="mt-3 flex justify-center">
            <div class="flex items-center gap-2 bg-white rounded-2xl shadow-md px-4 py-2 max-w-full">
              <span class="text-sm font-semibold text-ink whitespace-nowrap">🔑 查询密码</span>
              <input
                v-model="password"
                type="password"
                placeholder="订单设置的查询密码"
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
          共查询到 <span class="font-bold text-primary">{{ results.length }}</span> 笔订单
        </div>

        <!-- 空状态 -->
        <div v-if="hasSearched && !err && !results.length" class="text-center py-14">
          <div class="text-6xl mb-4 opacity-30">🔍</div>
          <div class="text-ink-soft text-sm">未找到相关订单</div>
          <div class="text-ink-muted text-xs mt-1">请检查订单号或联系方式是否正确</div>
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
                <span v-else>无图</span>
              </div>
              <div class="flex-1 min-w-0">
                <div class="text-sm font-semibold text-ink truncate">{{ o.product_name || '商品已下架' }}</div>
                <div class="text-xs text-ink-muted mt-0.5">× {{ o.quantity }}</div>
                <div class="flex items-baseline gap-1 mt-1">
                  <span class="text-price font-extrabold">{{ formatMoney(o.amount_display ?? o.amount, prefs.currentCurrency) }}</span>
                </div>
              </div>
              <span class="text-xs font-semibold px-2.5 py-1 rounded-pill shrink-0" :class="statusCls(o.status)">
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
                <span>{{ expanded.has(o.order_no) ? '收起卡密' : `查看卡密 (${o.cards.length})` }}</span>
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
                    复制
                  </button>
                </div>
                <button v-if="o.cards.length > 1" @click="copyAll(o.cards)"
                  class="text-xs text-ink-soft hover:text-primary transition mt-1 flex items-center gap-1">
                  📋 一键复制全部
                </button>
              </div>
            </div>

            <!-- 待支付提示 -->
            <div v-else-if="o.status === 'pending'" class="px-4 py-3 text-xs text-amber-600 flex items-center gap-1.5">
              <span>⏳</span> 订单待支付,支付后将自动发货
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
                <span class="text-[11px] text-ink-soft truncate">输入信息</span>
              </div>
              <div class="flex items-center justify-center gap-1.5 min-w-0">
                <span class="w-5 h-5 bg-primary text-white rounded-full flex items-center justify-center text-[10px] font-bold shrink-0">2</span>
                <span class="text-[11px] text-ink-soft truncate">点击查询</span>
              </div>
              <div class="flex items-center justify-center gap-1.5 min-w-0">
                <span class="w-5 h-5 bg-primary text-white rounded-full flex items-center justify-center text-[10px] font-bold shrink-0">3</span>
                <span class="text-[11px] text-ink-soft truncate">查看卡密</span>
              </div>
            </div>

            <!-- 下:常见问题(手风琴:标题默认可见,点击展开答案) -->
            <div class="border-t border-border">
              <div class="px-4 py-2 text-xs font-semibold text-ink flex items-center gap-2 bg-surface-subtle/50">
                <span>💡</span>常见问题
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
