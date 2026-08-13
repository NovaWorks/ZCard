<!-- 工作台 - 运营数据控制台：指标卡 + ECharts 图表 + 排行 + 告警 -->
<template>
  <div class="console-page art-full-height" v-loading="loading">
    <!-- Row 1: 时间范围选择 -->
    <div class="toolbar-row">
      <div class="toolbar-left">
        <ElSelect v-model="days" style="width: 160px" @change="loadAll">
          <ElOption :label="t('zcard.dashboard.rangeToday')" :value="1" />
          <ElOption :label="t('zcard.dashboard.range7d')" :value="7" />
          <ElOption :label="t('zcard.dashboard.range30d')" :value="30" />
          <ElOption :label="t('zcard.dashboard.range90d')" :value="90" />
        </ElSelect>
      </div>
      <div class="toolbar-right">
        <span class="updated-text" v-if="overview">
          {{ t('zcard.dashboard.salesTrend') }}
        </span>
      </div>
    </div>

    <!-- Row 2: KPI 指标卡 -->
    <div class="kpi-grid">
      <div
        v-for="card in statCards"
        :key="card.key"
        class="kpi-card"
        :style="{ '--accent': card.color }"
      >
        <div class="kpi-icon"><ArtSvgIcon :icon="card.icon" /></div>
        <div class="kpi-body">
          <div class="kpi-label">{{ t(card.label) }}</div>
          <div class="kpi-value">{{ formatValue(card) }}</div>
          <div class="kpi-sub" v-if="card.sub">{{ card.sub }}</div>
        </div>
      </div>
    </div>

    <!-- Row 3: 销售趋势图（全宽） -->
    <ElCard class="chart-card" shadow="never">
      <template #header>
        <div class="card-header">
          <span class="card-title">{{ t('zcard.dashboard.salesTrend') }}</span>
          <span class="card-sub">¥ / {{ t('zcard.dashboard.profit') }}</span>
        </div>
      </template>
      <div ref="trendChartEl" class="chart-box"></div>
    </ElCard>

    <!-- Row 3.5: 流量走势 + 退款率趋势 -->
    <div class="row-two row-50-50">
      <ElCard class="chart-card" shadow="never">
        <template #header>
          <div class="card-header">
            <span class="card-title">{{ t('zcard.dashboard.trafficTitle') }}</span>
          </div>
        </template>
        <div ref="trafficChartEl" class="chart-box"></div>
      </ElCard>

      <ElCard class="chart-card" shadow="never">
        <template #header>
          <div class="card-header">
            <span class="card-title">{{ t('zcard.dashboard.refundTitle') }}</span>
          </div>
        </template>
        <div ref="refundChartEl" class="chart-box"></div>
      </ElCard>
    </div>

    <!-- Row 4: 订单趋势柱状图 + 商品排行 -->
    <div class="row-two row-60-40">
      <ElCard class="chart-card" shadow="never">
        <template #header>
          <div class="card-header">
            <span class="card-title">{{ t('zcard.dashboard.orderTrend') }}</span>
          </div>
        </template>
        <div ref="orderChartEl" class="chart-box"></div>
      </ElCard>

      <ElCard class="rank-card" shadow="never">
        <template #header>
          <div class="card-header">
            <span class="card-title">{{ t('zcard.dashboard.topProducts') }}</span>
          </div>
        </template>
        <ElTable :data="topProducts" size="small" stripe>
          <ElTableColumn :label="t('zcard.dashboard.rank')" width="60" align="center">
            <template #default="{ $index }">
              <span class="rank-badge" :class="rankClass($index)">{{ $index + 1 }}</span>
            </template>
          </ElTableColumn>
          <ElTableColumn :label="t('zcard.dashboard.productName')" min-width="120" show-overflow-tooltip>
            <template #default="{ row }">{{ row.product_name }}</template>
          </ElTableColumn>
          <ElTableColumn :label="t('zcard.dashboard.orderCount')" width="80" align="right">
            <template #default="{ row }">{{ formatCount(row.order_count) }}</template>
          </ElTableColumn>
          <ElTableColumn :label="t('zcard.dashboard.paidAmount')" width="100" align="right">
            <template #default="{ row }">¥{{ formatAmount(row.paid_amount) }}</template>
          </ElTableColumn>
          <ElTableColumn :label="t('zcard.dashboard.profit')" width="100" align="right">
            <template #default="{ row }">
              <span class="profit-text">¥{{ formatAmount(row.profit) }}</span>
            </template>
          </ElTableColumn>
        </ElTable>
      </ElCard>
    </div>

    <!-- Row 5: 支付通道 + 告警 -->
    <div class="row-two row-50-50">
      <ElCard class="chart-card" shadow="never">
        <template #header>
          <div class="card-header">
            <span class="card-title">{{ t('zcard.dashboard.topChannels') }}</span>
          </div>
        </template>
        <ElTable :data="topChannels" size="small" stripe>
          <ElTableColumn :label="t('zcard.dashboard.channel')" min-width="120">
            <template #default="{ row }">{{ row.channel }}</template>
          </ElTableColumn>
          <ElTableColumn :label="t('zcard.dashboard.successCount')" width="90" align="right">
            <template #default="{ row }">{{ formatCount(row.success_count) }}</template>
          </ElTableColumn>
          <ElTableColumn :label="t('zcard.dashboard.failedCount')" width="90" align="right">
            <template #default="{ row }">{{ formatCount(row.failed_count) }}</template>
          </ElTableColumn>
          <ElTableColumn :label="t('zcard.dashboard.successRate')" width="110" align="center">
            <template #default="{ row }">
              <ElTag :type="rateTagType(row.success_rate)" size="small">
                {{ Number(row.success_rate || 0).toFixed(1) }}%
              </ElTag>
            </template>
          </ElTableColumn>
        </ElTable>
      </ElCard>

      <ElCard class="alert-card" shadow="never">
        <template #header>
          <div class="card-header">
            <span class="card-title">{{ t('zcard.dashboard.alerts') }}</span>
          </div>
        </template>
        <div class="alert-list">
          <div class="alert-item alert-warn" @click="goProducts">
            <div class="alert-icon"><ArtSvgIcon icon="ri:archive-line" /></div>
            <div class="alert-body">
              <div class="alert-title">
                {{ t('zcard.dashboard.statLowStock') }}：
                <strong>{{ formatCount(overview?.low_stock_products) }}</strong>
                {{ t('zcard.dashboard.productUnit') }}
              </div>
              <div class="alert-desc">{{ t('zcard.dashboard.lowStockTip') }}</div>
            </div>
            <div class="alert-arrow">›</div>
          </div>

          <div class="alert-item alert-danger" @click="goWithdrawals">
            <div class="alert-icon"><ArtSvgIcon icon="ri:money-dollar-circle-line" /></div>
            <div class="alert-body">
              <div class="alert-title">
                {{ t('zcard.dashboard.statPendingWithdrawals') }}：
                <strong>{{ formatCount(overview?.pending_withdrawals) }}</strong>
                {{ t('zcard.dashboard.withdrawalUnit') }}
              </div>
              <div class="alert-desc">{{ t('zcard.dashboard.pendingWithdrawalsTip') }}</div>
            </div>
            <div class="alert-arrow">›</div>
          </div>

          <div class="alert-item alert-pending">
            <div class="alert-icon"><ArtSvgIcon icon="ri:time-line" /></div>
            <div class="alert-body">
              <div class="alert-title">
                {{ t('zcard.dashboard.statPendingAmount') }}：
                <strong>¥{{ formatAmount(overview?.pending_amount) }}</strong>
              </div>
              <div class="alert-desc">{{ t('zcard.dashboard.pendingAmountTip') }}</div>
            </div>
          </div>
        </div>
      </ElCard>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import * as echarts from 'echarts'
import {
  getOverview,
  getTrends,
  getTopProducts,
  getTopChannels,
  getTraffic,
  type OverviewData,
  type TrendPoint,
  type TrafficPoint,
  type TopProduct,
  type TopChannel,
} from '@/api/dashboard'

defineOptions({ name: 'Console' })

const { t } = useI18n()
const router = useRouter()

const loading = ref(false)
const days = ref(7)

const overview = ref<OverviewData | null>(null)
const trends = ref<TrendPoint[]>([])
const topProducts = ref<TopProduct[]>([])
const topChannels = ref<TopChannel[]>([])
const traffic = ref<TrafficPoint[]>([])

// ===== 格式化 =====
const formatAmount = (fen: number | null | undefined): string =>
  (Number(fen || 0) / 100).toFixed(2)

const formatCount = (n: number | null | undefined): string =>
  Number(n || 0).toLocaleString()

const formatValue = (card: StatCard): string => {
  if (card.isAmount) return '¥' + formatAmount(card.value)
  if (card.isPercent) return Number(card.value || 0).toFixed(card.decimals ?? 2) + '%'
  return formatCount(card.value)
}

// ===== KPI 卡片 =====
interface StatCard {
  key: string
  label: string
  value: number | null | undefined
  sub?: string
  icon: string
  color: string
  isAmount?: boolean
  isPercent?: boolean
  decimals?: number
}

const statCards = computed<StatCard[]>(() => {
  const o = overview.value
  const subOrders = `${t('zcard.dashboard.statTotalOrders')} ${formatCount(o?.total_orders)}`
  const subProfit = `${t('zcard.dashboard.statProfit')} ¥${formatAmount(o?.profit)}`
  const subCost = `${t('zcard.dashboard.statCost')} ¥${formatAmount(o?.total_cost)}`
  const subPayRate = `${t('zcard.dashboard.statPaymentSuccess')} ${formatCount(o?.payment_success)} / ${t('zcard.dashboard.statPaymentFailed')} ${formatCount(o?.payment_failed)}`
  const subPending = t('zcard.dashboard.pendingOrderTip')
  const subNewUser = `${t('zcard.dashboard.statTotalProducts')} ${formatCount(o?.total_products)}`
  const subStock = `${t('zcard.dashboard.statLowStock')} ${formatCount(o?.low_stock_products)} ${t('zcard.dashboard.productUnit')}`
  const subOnline = t('zcard.dashboard.onlineTip')
  return [
    {
      key: 'onlineUsers',
      label: 'zcard.dashboard.statOnline',
      value: o?.online_users,
      sub: subOnline,
      icon: 'ri:wifi-line',
      color: '#10b981',
    },
    {
      key: 'paidOrders',
      label: 'zcard.dashboard.statPaidOrders',
      value: o?.paid_orders,
      sub: subOrders,
      icon: 'ri:bill-line',
      color: '#409eff',
    },
    {
      key: 'paidAmount',
      label: 'zcard.dashboard.statPaidAmount',
      value: o?.paid_amount,
      sub: subProfit,
      icon: 'ri:money-cny-circle-line',
      color: '#67c23a',
      isAmount: true,
    },
    {
      key: 'profitMargin',
      label: 'zcard.dashboard.statProfitMargin',
      value: o?.profit_margin,
      sub: subCost,
      icon: 'ri:line-chart-line',
      color: '#16a34a',
      isPercent: true,
      decimals: 1,
    },
    {
      key: 'paymentRate',
      label: 'zcard.dashboard.statPaymentRate',
      value: o?.payment_rate,
      sub: subPayRate,
      icon: 'ri:checkbox-circle-line',
      color: '#0ea5e9',
      isPercent: true,
      decimals: 1,
    },
    {
      key: 'pendingAmount',
      label: 'zcard.dashboard.statPendingAmount',
      value: o?.pending_amount,
      sub: subPending,
      icon: 'ri:time-line',
      color: '#e6a23c',
      isAmount: true,
    },
    {
      key: 'newUsers',
      label: 'zcard.dashboard.statNewUsers',
      value: o?.new_users,
      sub: subNewUser,
      icon: 'ri:user-3-line',
      color: '#9254de',
    },
    {
      key: 'stock',
      label: 'zcard.dashboard.statStock',
      value: o?.total_stock,
      sub: subStock,
      icon: 'ri:archive-line',
      color: '#14b8a6',
    },
    {
      key: 'pendingWithdrawals',
      label: 'zcard.dashboard.statPendingWithdrawals',
      value: o?.pending_withdrawals,
      sub: t('zcard.dashboard.pendingWithdrawalsTip'),
      icon: 'ri:money-dollar-circle-line',
      color: '#f56c6c',
    },
  ]
})

// ===== 跳转 =====
const goProducts = () => router.push({ path: '/product/list', query: { low_stock: '1' } })
const goWithdrawals = () => router.push('/withdrawal/list')

// ===== 表格辅助 =====
const rankClass = (index: number) => {
  if (index === 0) return 'rank-gold'
  if (index === 1) return 'rank-silver'
  if (index === 2) return 'rank-bronze'
  return ''
}

const rateTagType = (rate: number): 'success' | 'warning' | 'danger' => {
  const r = Number(rate || 0)
  if (r >= 95) return 'success'
  if (r >= 80) return 'warning'
  return 'danger'
}

// ===== ECharts =====
const trendChartEl = ref<HTMLElement>()
const orderChartEl = ref<HTMLElement>()
const trafficChartEl = ref<HTMLElement>()
const refundChartEl = ref<HTMLElement>()
let trendChart: echarts.ECharts | null = null
let orderChart: echarts.ECharts | null = null
let trafficChart: echarts.ECharts | null = null
let refundChart: echarts.ECharts | null = null

const handleResize = () => {
  trendChart?.resize()
  orderChart?.resize()
  trafficChart?.resize()
  refundChart?.resize()
}

const trendDates = computed(() => trends.value.map((p) => p.date.slice(5)))
const trendPaid = computed(() => trends.value.map((p) => Number((p.paid_amount || 0) / 100)))
const trendProfit = computed(() => trends.value.map((p) => Number((p.profit || 0) / 100)))
const orderTotal = computed(() => trends.value.map((p) => Number(p.order_count || 0)))
const orderPaid = computed(() => trends.value.map((p) => Number(p.paid_count || 0)))

const initTrendChart = () => {
  if (!trendChartEl.value) return
  trendChart = echarts.init(trendChartEl.value)
  updateTrendChart()
}

const updateTrendChart = () => {
  if (!trendChart) return
  trendChart.setOption({
    tooltip: {
      trigger: 'axis',
      axisPointer: { type: 'cross' },
      valueFormatter: (v: any) => '¥' + Number(v || 0).toFixed(2),
    },
    legend: {
      data: [t('zcard.dashboard.statPaidAmount'), t('zcard.dashboard.statProfit')],
      top: 0,
    },
    grid: { left: 50, right: 24, top: 40, bottom: 30 },
    xAxis: {
      type: 'category',
      boundaryGap: false,
      data: trendDates.value,
      axisLine: { lineStyle: { color: '#e5e7eb' } },
    },
    yAxis: {
      type: 'value',
      axisLabel: { formatter: (v: number) => '¥' + v },
      splitLine: { lineStyle: { color: '#f0f0f0' } },
    },
    series: [
      {
        name: t('zcard.dashboard.statPaidAmount'),
        type: 'line',
        smooth: true,
        showSymbol: false,
        data: trendPaid.value,
        lineStyle: { width: 3, color: '#409eff' },
        itemStyle: { color: '#409eff' },
        areaStyle: {
          color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
            { offset: 0, color: 'rgba(64,158,255,0.35)' },
            { offset: 1, color: 'rgba(64,158,255,0.02)' },
          ]),
        },
      },
      {
        name: t('zcard.dashboard.statProfit'),
        type: 'line',
        smooth: true,
        showSymbol: false,
        data: trendProfit.value,
        lineStyle: { width: 3, color: '#67c23a' },
        itemStyle: { color: '#67c23a' },
        areaStyle: {
          color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
            { offset: 0, color: 'rgba(103,194,58,0.35)' },
            { offset: 1, color: 'rgba(103,194,58,0.02)' },
          ]),
        },
      },
    ],
  })
}

// ===== 流量走势(PV/UV) =====
const trafficDates = computed(() => traffic.value.map((p) => p.date.slice(5)))
const trafficPv = computed(() => traffic.value.map((p) => Number(p.pv || 0)))
const trafficUv = computed(() => traffic.value.map((p) => Number(p.uv || 0)))

const initTrafficChart = () => {
  if (!trafficChartEl.value) return
  trafficChart = echarts.init(trafficChartEl.value)
  updateTrafficChart()
}

const updateTrafficChart = () => {
  if (!trafficChart) return
  trafficChart.setOption({
    tooltip: { trigger: 'axis', axisPointer: { type: 'cross' } },
    legend: {
      data: [t('zcard.dashboard.trafficPv'), t('zcard.dashboard.trafficUv')],
      top: 0,
    },
    grid: { left: 44, right: 16, top: 40, bottom: 30 },
    xAxis: {
      type: 'category',
      boundaryGap: false,
      data: trafficDates.value,
      axisLine: { lineStyle: { color: '#e5e7eb' } },
    },
    yAxis: {
      type: 'value',
      minInterval: 1,
      splitLine: { lineStyle: { color: '#f0f0f0' } },
    },
    series: [
      {
        name: t('zcard.dashboard.trafficPv'),
        type: 'line',
        smooth: true,
        showSymbol: false,
        data: trafficPv.value,
        lineStyle: { width: 2.5, color: '#0ea5e9' },
        itemStyle: { color: '#0ea5e9' },
        areaStyle: {
          color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
            { offset: 0, color: 'rgba(14,165,233,0.3)' },
            { offset: 1, color: 'rgba(14,165,233,0.02)' },
          ]),
        },
      },
      {
        name: t('zcard.dashboard.trafficUv'),
        type: 'line',
        smooth: true,
        showSymbol: false,
        data: trafficUv.value,
        lineStyle: { width: 2.5, color: '#9254de' },
        itemStyle: { color: '#9254de' },
        areaStyle: {
          color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
            { offset: 0, color: 'rgba(146,84,222,0.25)' },
            { offset: 1, color: 'rgba(146,84,222,0.02)' },
          ]),
        },
      },
    ],
  })
}

// ===== 退款率趋势 =====
const refundDates = computed(() => trends.value.map((p) => p.date.slice(5)))
const refundCount = computed(() => trends.value.map((p) => Number(p.refunded_count || 0)))
const refundRate = computed(() => trends.value.map((p) => Number(p.refund_rate || 0)))

const initRefundChart = () => {
  if (!refundChartEl.value) return
  refundChart = echarts.init(refundChartEl.value)
  updateRefundChart()
}

const updateRefundChart = () => {
  if (!refundChart) return
  refundChart.setOption({
    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
    legend: {
      data: [t('zcard.dashboard.refundCount'), t('zcard.dashboard.refundRate')],
      top: 0,
    },
    grid: { left: 44, right: 40, top: 40, bottom: 30 },
    xAxis: {
      type: 'category',
      data: refundDates.value,
      axisLine: { lineStyle: { color: '#e5e7eb' } },
    },
    yAxis: [
      {
        type: 'value',
        minInterval: 1,
        splitLine: { lineStyle: { color: '#f0f0f0' } },
      },
      {
        type: 'value',
        axisLabel: { formatter: '{value}%' },
        splitLine: { show: false },
      },
    ],
    series: [
      {
        name: t('zcard.dashboard.refundCount'),
        type: 'bar',
        data: refundCount.value,
        barWidth: '40%',
        itemStyle: { color: '#f56c6c', borderRadius: [4, 4, 0, 0] },
      },
      {
        name: t('zcard.dashboard.refundRate'),
        type: 'line',
        yAxisIndex: 1,
        smooth: true,
        data: refundRate.value,
        lineStyle: { width: 2.5, color: '#e6a23c' },
        itemStyle: { color: '#e6a23c' },
      },
    ],
  })
}

const initOrderChart = () => {
  if (!orderChartEl.value) return
  orderChart = echarts.init(orderChartEl.value)
  updateOrderChart()
}

const updateOrderChart = () => {
  if (!orderChart) return
  orderChart.setOption({
    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
    legend: {
      data: [t('zcard.dashboard.statTotalOrders'), t('zcard.dashboard.statPaidOrders')],
      top: 0,
    },
    grid: { left: 44, right: 16, top: 40, bottom: 30 },
    xAxis: {
      type: 'category',
      data: trendDates.value,
      axisLine: { lineStyle: { color: '#e5e7eb' } },
    },
    yAxis: {
      type: 'value',
      splitLine: { lineStyle: { color: '#f0f0f0' } },
    },
    series: [
      {
        name: t('zcard.dashboard.statTotalOrders'),
        type: 'bar',
        data: orderTotal.value,
        barGap: '10%',
        itemStyle: { color: '#909399', borderRadius: [4, 4, 0, 0] },
      },
      {
        name: t('zcard.dashboard.statPaidOrders'),
        type: 'bar',
        data: orderPaid.value,
        itemStyle: { color: '#409eff', borderRadius: [4, 4, 0, 0] },
      },
    ],
  })
}

// ===== 数据加载 =====
const loadAll = async () => {
  loading.value = true
  const params = { days: days.value }
  const results = await Promise.allSettled([
    getOverview(params),
    getTrends(params),
    getTopProducts({ days: days.value, limit: 10 }),
    getTopChannels({ days: days.value }),
    getTraffic(params),
  ])
  if (results[0].status === 'fulfilled') overview.value = results[0].value
  if (results[1].status === 'fulfilled') trends.value = results[1].value || []
  if (results[2].status === 'fulfilled') topProducts.value = results[2].value || []
  if (results[3].status === 'fulfilled') topChannels.value = results[3].value || []
  if (results[4].status === 'fulfilled') traffic.value = results[4].value || []
  loading.value = false
  await nextTick()
  if (!trendChart) initTrendChart()
  else updateTrendChart()
  if (!orderChart) initOrderChart()
  else updateOrderChart()
  if (!trafficChart) initTrafficChart()
  else updateTrafficChart()
  if (!refundChart) initRefundChart()
  else updateRefundChart()
}

onMounted(async () => {
  await loadAll()
  window.addEventListener('resize', handleResize)
})

onUnmounted(() => {
  window.removeEventListener('resize', handleResize)
  trendChart?.dispose()
  orderChart?.dispose()
  trafficChart?.dispose()
  refundChart?.dispose()
  trendChart = null
  orderChart = null
  trafficChart = null
  refundChart = null
})
</script>

<style lang="scss" scoped>
.console-page {
  display: flex;
  flex-direction: column;
  gap: 16px;
  /* 内容超出固定高度(100vh - header)时内部滚动,避免溢出到底部固定栏 */
  overflow-y: auto;
}

// 工具栏
.toolbar-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.updated-text {
  font-size: 13px;
  color: var(--el-text-color-secondary);
}

// KPI 指标卡
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
}
.kpi-card {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 16px;
  background: var(--el-bg-color);
  border: 1px solid var(--el-border-color-lighter);
  border-left: 3px solid var(--accent);
  border-radius: 8px;
  transition: box-shadow 0.2s;
  &:hover {
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
  }
}
.kpi-icon {
  font-size: 30px;
  flex-shrink: 0;
}
.kpi-body {
  min-width: 0;
  flex: 1;
}
.kpi-label {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
.kpi-value {
  margin-top: 4px;
  font-size: 22px;
  font-weight: 700;
  color: var(--el-text-color-primary);
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.kpi-sub {
  margin-top: 4px;
  font-size: 11px;
  color: var(--el-text-color-placeholder);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

// 图表卡片
.chart-card {
  border-radius: 8px;
}
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
}
.card-title {
  font-size: 15px;
  font-weight: 600;
}
.card-sub {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
.chart-box {
  width: 100%;
  height: 320px;
}

// 双列布局
.row-two {
  display: grid;
  gap: 16px;
}
.row-60-40 {
  grid-template-columns: 3fr 2fr;
}
.row-50-50 {
  grid-template-columns: 1fr 1fr;
}

// 商品排行
.rank-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  font-size: 12px;
  font-weight: 700;
  background: #f0f0f0;
  color: #666;
}
.rank-gold {
  background: linear-gradient(135deg, #ffd700, #ffb700);
  color: #fff;
}
.rank-silver {
  background: linear-gradient(135deg, #c0c0c0, #a8a8a8);
  color: #fff;
}
.rank-bronze {
  background: linear-gradient(135deg, #cd7f32, #b06820);
  color: #fff;
}
.profit-text {
  font-weight: 600;
  color: var(--el-color-success);
}

// 告警
.alert-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.alert-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px;
  border-radius: 8px;
  border: 1px solid var(--el-border-color-lighter);
  border-left: 3px solid var(--accent);
  cursor: pointer;
  transition: background 0.2s;
  &:hover {
    background: var(--el-fill-color-light);
  }
}
.alert-warn {
  --accent: #e6a23c;
  background: rgba(230, 162, 60, 0.06);
}
.alert-danger {
  --accent: #f56c6c;
  background: rgba(245, 108, 108, 0.06);
}
.alert-pending {
  --accent: #409eff;
  background: rgba(64, 158, 255, 0.06);
  cursor: default;
}
.alert-icon {
  font-size: 24px;
  flex-shrink: 0;
}
.alert-body {
  flex: 1;
  min-width: 0;
}
.alert-title {
  font-size: 14px;
  color: var(--el-text-color-primary);
}
.alert-desc {
  margin-top: 2px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
.alert-arrow {
  font-size: 20px;
  color: var(--el-text-color-placeholder);
}

// 响应式
@media (max-width: 1200px) {
  .kpi-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .row-60-40,
  .row-50-50 {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 640px) {
  .kpi-grid {
    grid-template-columns: 1fr;
  }
  .chart-box {
    height: 260px;
  }
}
</style>
