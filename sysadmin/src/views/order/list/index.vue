<!-- 订单列表 - 后台管理（统计卡片 + 筛选 + 导出/清理） -->
<template>
  <div class="order-page art-full-height">
    <!-- 统计卡片 -->
    <div class="stats-grid">
      <div v-for="card in statCards" :key="card.key" class="stat-card" :style="{ '--accent': card.color }">
        <div class="stat-icon"><ArtSvgIcon :icon="card.icon" /></div>
        <div class="stat-body">
          <div class="stat-label">{{ t(card.label) }}</div>
          <div class="stat-value">{{ card.isAmount ? '¥' + formatAmount(card.value) : formatCount(card.value) }}</div>
        </div>
      </div>
    </div>

    <ElCard ref="cardRef" class="art-table-card" shadow="never">
      <!-- 工具栏 -->
      <div class="toolbar">
        <div class="toolbar-left">
          <ElButton @click="handleClear" :loading="clearing">
            <template #icon><ArtSvgIcon icon="ri:eraser-line" /></template>{{ t('zcard.order.clearOrders') }}
          </ElButton>
          <ElButton type="success" plain @click="handleExport" :loading="exporting">
            <template #icon><ArtSvgIcon icon="ri:download-line" /></template>{{ t('zcard.order.exportOrders') }}
          </ElButton>
        </div>
        <div class="toolbar-right">
          <ElButton @click="showSearch = !showSearch" text>
            {{ showSearch ? t('zcard.common.collapse') : t('zcard.common.expand') }}{{ t('zcard.order.filter') }}
          </ElButton>
        </div>
      </div>

      <!-- 筛选区 -->
      <ElCollapseTransition>
        <div v-show="showSearch" class="search-bar">
          <ElForm :inline="true" :model="searchForm" @submit.prevent>
            <ElFormItem :label="t('zcard.order.orderNo')">
              <ElInput v-model="searchForm.keyword" :placeholder="t('zcard.order.searchPlaceholder')" clearable style="width: 180px" @keyup.enter="handleSearch" @clear="handleSearch" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.order.userType')">
              <ElSelect v-model="searchForm.user_type" clearable :placeholder="t('zcard.order.allStatus')" style="width: 130px" @change="handleSearch">
                <ElOption :label="t('zcard.order.userGuest')" value="guest" />
                <ElOption :label="t('zcard.order.userMember')" value="member" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="t('zcard.order.status')">
              <ElSelect v-model="searchForm.status" clearable :placeholder="t('zcard.order.allStatus')" style="width: 120px" @change="handleSearch">
                <ElOption :label="t('zcard.order.statusPending')" value="pending" />
                <ElOption :label="t('zcard.order.statusPaid')" value="paid" />
                <ElOption :label="t('zcard.order.statusClosed')" value="closed" />
                <ElOption :label="t('zcard.order.statusRefunded')" value="refunded" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="t('zcard.order.deliveryStatus')">
              <ElSelect v-model="searchForm.delivery_status" clearable :placeholder="t('zcard.order.allStatus')" style="width: 120px" @change="handleSearch">
                <ElOption :label="t('zcard.order.deliveryPending')" value="pending" />
                <ElOption :label="t('zcard.order.deliveryDelivered')" value="delivered" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="t('zcard.order.paymentChannel')">
              <ElSelect v-model="searchForm.payment_channel" clearable :placeholder="t('zcard.order.allStatus')" style="width: 130px" @change="handleSearch">
                <ElOption v-for="ch in channels" :key="ch.id" :label="ch.name" :value="ch.code" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="t('zcard.order.createDevice')">
              <ElSelect v-model="searchForm.create_device" clearable :placeholder="t('zcard.order.allStatus')" style="width: 110px" @change="handleSearch">
                <ElOption :label="t('zcard.order.deviceWin')" value="win" />
                <ElOption :label="t('zcard.order.deviceMac')" value="mac" />
                <ElOption :label="t('zcard.order.deviceIOS')" value="ios" />
                <ElOption :label="t('zcard.order.deviceAndroid')" value="android" />
                <ElOption :label="t('zcard.order.deviceOther')" value="other" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="t('zcard.order.createIp')">
              <ElInput v-model="searchForm.create_ip" :placeholder="t('zcard.order.createIpPlaceholder')" clearable style="width: 140px" @keyup.enter="handleSearch" @clear="handleSearch" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.order.dateRange')">
              <ElDatePicker v-model="dateRange" type="daterange" range-separator="-" start-placeholder="开始" end-placeholder="结束" value-format="YYYY-MM-DD" style="width: 240px" @change="handleSearch" />
            </ElFormItem>
            <ElFormItem>
              <ElButton type="primary" @click="handleSearch">{{ t('zcard.common.search') }}</ElButton>
              <ElButton @click="handleReset">{{ t('zcard.common.reset') }}</ElButton>
            </ElFormItem>
          </ElForm>
        </div>
      </ElCollapseTransition>

      <!-- 表格 -->
      <ElTable ref="tableRef" :data="orders" v-loading="loading" :height="tableHeight" border stripe class="order-table">
        <ElTableColumn :label="t('zcard.order.orderNo')" min-width="190">
          <template #default="{ row }">
            <div class="order-no-cell">
              <span class="order-no-text">{{ row.order_no }}</span>
              <ElIcon class="copy-icon" @click="copyText(row.order_no)"><CopyDocument /></ElIcon>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.order.product')" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.product?.name || '-' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.order.sku')" min-width="100" show-overflow-tooltip>
          <template #default="{ row }">
            <ElTag v-if="row.sku_name" size="small" type="info">{{ row.sku_name }}</ElTag>
            <span v-else class="text-muted">-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.order.amount')" width="100" align="right">
          <template #default="{ row }">
            <span class="amount-text">¥{{ formatAmount(row.amount) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.order.quantity')" width="70" align="center">
          <template #default="{ row }">{{ row.quantity }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.order.paymentChannel')" width="100" align="center">
          <template #default="{ row }">
            <span v-if="row.payment_channel" class="channel-tag">{{ channelName(row.payment_channel) }}</span>
            <span v-else class="text-muted">-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.order.cost')" width="90" align="right">
          <template #default="{ row }">
            <span class="text-muted">¥{{ formatAmount(row.cost) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.order.commission')" width="90" align="right">
          <template #default="{ row }">
            <span class="commission-text">¥{{ formatAmount(row.amount - row.cost) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.order.cards')" width="80" align="center">
          <template #default="{ row }">
            <ElButton v-if="row.order_deliveries_count > 0" text type="primary" size="small" @click="showCards(row)">
              {{ row.order_deliveries_count }} {{ t('zcard.order.cardUnit') }}
            </ElButton>
            <span v-else class="text-muted">0</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.order.status')" width="90" align="center">
          <template #default="{ row }">
            <ElTag :type="statusTagType(row.status)" size="small">{{ statusLabel(row.status) }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.order.deliveryStatus')" width="100" align="center">
          <template #default="{ row }">
            <ElTag :type="row.delivery_status === 'delivered' ? 'success' : 'warning'" size="small">
              {{ row.delivery_status === 'delivered' ? t('zcard.order.deliveryDelivered') : t('zcard.order.deliveryPending') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.order.contact')" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">{{ row.contact || '-' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.order.createTime')" width="160" align="center">
          <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.common.actions')" width="190" fixed="right" align="center">
          <template #default="{ row }">
            <ElButton text type="primary" size="small" @click="showDetail(row)">{{ t('zcard.order.detail') }}</ElButton>
            <ElButton
              v-if="row.status === 'paid' && row.delivery_status === 'pending' && row.fulfillment_type_snapshot === 'manual'"
              text
              type="success"
              size="small"
              @click="openFulfill(row)"
            >{{ t('zcard.order.manualFulfill') }}</ElButton>
            <ElButton v-if="row.status === 'pending'" text type="danger" size="small" @click="handleClose(row)">{{ t('zcard.order.close') }}</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <!-- 分页 -->
      <div ref="paginationRef" class="pagination-wrap">
        <ElPagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.pageSize"
          :total="pagination.total"
          :page-sizes="[15, 30, 50, 100]"
          layout="total, sizes, prev, pager, next"
          @size-change="fetchOrders"
          @current-change="fetchOrders"
        />
      </div>
    </ElCard>

    <!-- 详情/卡密弹窗 -->
    <ElDialog v-model="detailVisible" :title="t('zcard.order.detail')" width="600px" destroy-on-close>
      <div v-if="currentOrder" class="detail-content">
        <ElDescriptions :column="2" border>
          <ElDescriptionsItem :label="t('zcard.order.orderNo')">{{ currentOrder.order_no }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.order.status')">
            <ElTag :type="statusTagType(currentOrder.status)" size="small">{{ statusLabel(currentOrder.status) }}</ElTag>
          </ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.order.deliveryStatus')">
            {{ currentOrder.delivery_status === 'delivered' ? t('zcard.order.deliveryDelivered') : t('zcard.order.deliveryPending') }}
          </ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.order.fulfillmentType')">
            {{ fulfillmentTypeLabel(currentOrder.fulfillment_type_snapshot) }}
          </ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.order.product')">{{ currentOrder.product?.name || '-' }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.order.sku')">{{ currentOrder.sku_name || '-' }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.order.amount')">¥{{ formatAmount(currentOrder.amount) }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.order.quantity')">{{ currentOrder.quantity }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.order.cost')">¥{{ formatAmount(currentOrder.cost) }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.order.commission')">¥{{ formatAmount(currentOrder.amount - currentOrder.cost) }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.order.paymentChannel')">{{ currentOrder.payment_channel || '-' }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.order.contact')">{{ currentOrder.contact || '-' }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.order.createTime')">{{ formatTime(currentOrder.created_at) }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.order.paidTime')">{{ currentOrder.paid_at ? formatTime(currentOrder.paid_at) : '-' }}</ElDescriptionsItem>
        </ElDescriptions>

        <!-- 卡密列表 -->
        <div v-if="currentOrder.deliveries && currentOrder.deliveries.length" class="cards-section">
          <div class="cards-title">{{ t('zcard.order.deliveryContents') }}（{{ currentOrder.deliveries.length }}）</div>
          <div v-for="(d, i) in currentOrder.deliveries" :key="i" class="card-item">
            <div class="card-header">
              <span class="card-index">#{{ i + 1 }}</span>
              <ElButton text type="primary" size="small" @click="copyText(d.card_content)">{{ t('zcard.order.copy') }}</ElButton>
            </div>
            <code class="card-content">{{ d.card_content }}</code>
            <div class="card-time">{{ formatTime(d.delivered_at) }}</div>
          </div>
        </div>
      </div>
    </ElDialog>

    <ElDialog
      v-model="fulfillVisible"
      :title="t('zcard.order.manualFulfill')"
      width="600px"
      :close-on-click-modal="!fulfilling"
      :close-on-press-escape="!fulfilling"
    >
      <template v-if="fulfillTarget">
        <ElDescriptions :column="2" border class="mb-4">
          <ElDescriptionsItem :label="t('zcard.order.orderNo')">{{ fulfillTarget.order_no }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.order.product')">{{ fulfillTarget.product?.name || '-' }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.order.quantity')">{{ fulfillTarget.quantity }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.order.contact')">{{ fulfillTarget.contact || '-' }}</ElDescriptionsItem>
        </ElDescriptions>
        <ElAlert type="warning" :closable="false" :title="t('zcard.order.manualFulfillTip')" class="mb-4" />
        <ElFormItem :label="t('zcard.order.deliveryContents')" required>
          <ElInput
            v-model="fulfillContent"
            type="textarea"
            :rows="8"
            maxlength="10000"
            show-word-limit
            :placeholder="t('zcard.order.manualFulfillPlaceholder')"
          />
        </ElFormItem>
      </template>
      <template #footer>
        <ElButton :disabled="fulfilling" @click="fulfillVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="fulfilling" @click="submitFulfill">{{ t('zcard.order.confirmFulfill') }}</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { CopyDocument } from '@element-plus/icons-vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import { useListTableHeight } from '@/hooks'
  import {
    getOrders,
    getOrder,
    closeOrder,
    fulfillOrder,
    refetchUpstreamOrder,
    getStats,
    clearOrders,
    type Order,
    type OrderStats,
    type OrderStatus,
  } from '@/api/orders'
  import { getChannels, type PaymentChannel } from '@/api/payment'

  defineOptions({ name: 'OrderList' })

  const { t } = useI18n()

  const loading = ref(false)
  const exporting = ref(false)
  const clearing = ref(false)
  const showSearch = ref(true)
  const orders = ref<Order[]>([])
  const channels = ref<PaymentChannel[]>([])

  const pagination = reactive({ page: 1, pageSize: 15, total: 0 })
  // 表格高度自适应:数据满页时表格内容撑高会被卡片裁掉分页栏,固定表格高度使其内部滚动
  const { cardRef, tableRef, paginationRef, tableHeight } = useListTableHeight()
  const searchForm = reactive({
    keyword: '',
    status: '' as OrderStatus | '',
    payment_channel: '',
    delivery_status: '' as 'pending' | 'delivered' | '',
    user_type: '' as 'guest' | 'member' | '',
    create_device: '' as 'win' | 'mac' | 'ios' | 'android' | 'other' | '',
    create_ip: '',
  })
  const dateRange = ref<[string, string] | null>(null)

  // 统计数据
  const stats = ref<OrderStats>({
    total_count: 0,
    pending_amount: 0,
    total_amount: 0,
    paid_amount: 0,
    refunded_amount: 0,
    total_cost: 0,
  })

  const statCards = computed(() => [
    { key: 'count', label: 'zcard.order.statCount', value: stats.value.total_count, icon: 'ri:archive-line', color: '#409eff', isAmount: false },
    { key: 'pending', label: 'zcard.order.statPending', value: stats.value.pending_amount, icon: 'ri:time-line', color: '#e6a23c', isAmount: true },
    { key: 'total', label: 'zcard.order.statTotal', value: stats.value.total_amount, icon: 'ri:money-cny-circle-line', color: '#67c23a', isAmount: true },
    { key: 'paid', label: 'zcard.order.statPaid', value: stats.value.paid_amount, icon: 'ri:checkbox-circle-line', color: '#67c23a', isAmount: true },
    { key: 'refunded', label: 'zcard.order.statRefunded', value: stats.value.refunded_amount, icon: 'ri:refund-2-line', color: '#f56c6c', isAmount: true },
    { key: 'cost', label: 'zcard.order.statCost', value: stats.value.total_cost, icon: 'ri:bar-chart-2-line', color: '#909399', isAmount: true },
  ])

  /** 分(整数)→元(2位小数字符串) */
  const formatAmount = (fen: number | string | null | undefined): string =>
    (Number(fen || 0) / 100).toFixed(2)

  /** 数量直接显示 */
  const formatCount = (n: number | string | null | undefined): string =>
    Number(n || 0).toLocaleString()

  const formatTime = (d: string | null) => (d ? String(d).slice(0, 19).replace('T', ' ') : '-')

  const statusLabel = (s: OrderStatus) => ({
    pending: t('zcard.order.statusPending'),
    paid: t('zcard.order.statusPaid'),
    closed: t('zcard.order.statusClosed'),
    refunded: t('zcard.order.statusRefunded'),
  }[s] || s)

  const statusTagType = (s: OrderStatus) => ({
    pending: 'warning',
    paid: 'success',
    closed: 'info',
    refunded: 'danger',
  }[s] || 'info') as any

  const fulfillmentTypeLabel = (type: Order['fulfillment_type_snapshot']) => ({
    auto_card: t('zcard.order.fulfillmentAutoCard'),
    fixed: t('zcard.order.fulfillmentFixed'),
    manual: t('zcard.order.fulfillmentManual'),
    upstream: t('zcard.order.fulfillmentUpstream'),
  }[type] || type)

  const channelName = (code: string) => {
    // 余额支付:未在支付通道表中,单独映射
    if (code === 'balance') return t('zcard.order.payBalance')
    const ch = channels.value.find(c => c.code === code)
    return ch ? ch.name : code
  }

  const buildParams = () => ({
    page: pagination.page,
    pageSize: pagination.pageSize,
    keyword: searchForm.keyword || undefined,
    status: searchForm.status || undefined,
    payment_channel: searchForm.payment_channel || undefined,
    delivery_status: searchForm.delivery_status || undefined,
    user_type: searchForm.user_type || undefined,
    create_device: searchForm.create_device || undefined,
    create_ip: searchForm.create_ip || undefined,
    start_date: dateRange.value?.[0] || undefined,
    end_date: dateRange.value?.[1] || undefined,
  })

  const fetchOrders = async () => {
    loading.value = true
    try {
      const res = await getOrders(buildParams())
      orders.value = res.data || []
      pagination.total = res.total || 0
    } catch (e) {
      orders.value = []
    } finally {
      loading.value = false
    }
  }

  const fetchStats = async () => {
    try {
      stats.value = await getStats(buildParams())
    } catch (e) {
      // ignore
    }
  }

  const fetchChannels = async () => {
    try {
      channels.value = await getChannels()
    } catch (e) {
      channels.value = []
    }
  }

  const fetchAll = () => {
    fetchOrders()
    fetchStats()
  }

  const handleSearch = () => {
    pagination.page = 1
    fetchAll()
  }

  const handleReset = () => {
    searchForm.keyword = ''
    searchForm.status = ''
    searchForm.payment_channel = ''
    searchForm.delivery_status = ''
    searchForm.user_type = ''
    searchForm.create_device = ''
    searchForm.create_ip = ''
    dateRange.value = null
    pagination.page = 1
    fetchAll()
  }

  const copyText = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text)
      ElMessage.success(t('zcard.order.copied'))
    } catch {
      ElMessage.warning(text)
    }
  }

  const currentOrder = ref<Order | null>(null)
  const detailVisible = ref(false)

  const showDetail = async (row: Order) => {
    try {
      const detail = await getOrder(row.id)
      currentOrder.value = detail
      detailVisible.value = true
    } catch (e) {
      // ignore
    }
  }

  const showCards = async (row: Order) => {
    // 复用详情弹窗
    await showDetail(row)
  }

  const fulfillVisible = ref(false)
  const fulfilling = ref(false)
  const fulfillTarget = ref<Order | null>(null)
  const fulfillContent = ref('')

  const openFulfill = (row: Order) => {
    fulfillTarget.value = row
    fulfillContent.value = ''
    fulfillVisible.value = true
  }

  const submitFulfill = async () => {
    if (!fulfillTarget.value) return
    if (!fulfillContent.value.trim()) {
      ElMessage.warning(t('zcard.order.manualFulfillRequired'))
      return
    }
    fulfilling.value = true
    try {
      const detail = await fulfillOrder(fulfillTarget.value.id, fulfillContent.value)
      ElMessage.success(t('zcard.order.fulfillSuccess'))
      fulfillVisible.value = false
      if (currentOrder.value?.id === detail.id) currentOrder.value = detail
      fetchAll()
    } finally {
      fulfilling.value = false
    }
  }

  const handleClose = async (row: Order) => {
    try {
      await ElMessageBox.confirm(
        t('zcard.order.closeOrderConfirm'),
        t('zcard.order.closeTitle'),
        { type: 'warning' }
      )
      await closeOrder(row.id)
      ElMessage.success(t('zcard.order.closed'))
      fetchAll()
    } catch (e) {
      // cancelled
    }
  }

  const handleExport = async () => {
    exporting.value = true
    try {
      const params = new URLSearchParams()
      Object.entries(buildParams()).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') params.append(k, String(v))
      })
      const token = localStorage.getItem('token') || ''
      const resp = await fetch(`/api/admin/orders/export?${params}`, {
        headers: { Authorization: `Bearer ${token}` },
      })
      if (!resp.ok) throw new Error('export failed')
      const blob = await resp.blob()
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `orders_${Date.now()}.csv`
      a.click()
      URL.revokeObjectURL(url)
      ElMessage.success(t('zcard.order.exportSuccess'))
    } catch (e) {
      ElMessage.error(t('zcard.order.exportFailed'))
    } finally {
      exporting.value = false
    }
  }

  const handleClear = async () => {
    try {
      await ElMessageBox.confirm(
        t('zcard.order.clearConfirm'),
        t('zcard.order.clearTitle'),
        { type: 'warning' }
      )
      clearing.value = true
      const res = await clearOrders()
      ElMessage.success(t('zcard.order.clearSuccess', { count: res.cleared }))
      fetchAll()
    } catch (e) {
      // cancelled
    } finally {
      clearing.value = false
    }
  }

  onActivated(() => {
    fetchChannels()
    fetchAll()
  })
</script>

<style lang="scss" scoped>
  .order-page {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  // 统计卡片
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 12px;
  }

  .stat-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 8px;
    border-left: 3px solid var(--accent);
  }

  .stat-icon {
    font-size: 28px;
  }

  .stat-label {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .stat-value {
    margin-top: 4px;
    font-size: 20px;
    font-weight: 700;
    color: var(--el-text-color-primary);
  }

  // 工具栏
  .toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
  }

  .toolbar-left {
    display: flex;
    gap: 8px;
  }

  // 表格
  .order-no-cell {
    display: flex;
    align-items: center;
    gap: 4px;
  }

  .order-no-text {
    font-family: monospace;
    font-size: 12px;
  }

  .copy-icon {
    cursor: pointer;
    color: var(--el-text-color-secondary);

    &:hover {
      color: var(--el-color-primary);
    }
  }

  .amount-text {
    font-weight: 600;
    color: var(--el-color-success);
  }

  .commission-text {
    font-weight: 600;
    color: var(--el-color-primary);
  }

  .channel-tag {
    padding: 2px 8px;
    font-size: 12px;
    background: var(--el-fill-color-light);
    border-radius: 4px;
  }

  .text-muted {
    color: var(--el-text-color-placeholder);
  }

  .pagination-wrap {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }

  // 详情弹窗
  .cards-section {
    margin-top: 16px;
  }

  .cards-title {
    margin-bottom: 8px;
    font-size: 14px;
    font-weight: 600;
  }

  .card-item {
    padding: 8px;
    margin-bottom: 8px;
    background: var(--el-fill-color-light);
    border-radius: 4px;
  }

  .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .card-index {
    font-size: 12px;
    font-weight: 600;
    color: var(--el-text-color-secondary);
  }

  .card-content {
    display: block;
    margin-top: 4px;
    font-size: 13px;
    word-break: break-all;
  }

  .card-time {
    margin-top: 4px;
    font-size: 11px;
    color: var(--el-text-color-placeholder);
  }

.profit-negative {
  color: var(--el-color-danger);
}
.upstream-link {
  color: var(--el-color-primary);
  word-break: break-all;
}
</style>
