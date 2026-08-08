<!-- 充值单管理 - 后台（统计卡片 + 筛选 + 列表 + 详情） -->
<template>
  <div class="recharge-page art-full-height">
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
          <span class="toolbar-title">{{ t('zcard.recharge.listTitle') }}</span>
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
            <ElFormItem :label="t('zcard.recharge.keyword')">
              <ElInput v-model="searchForm.keyword" :placeholder="t('zcard.recharge.searchPlaceholder')" clearable style="width: 180px" @keyup.enter="handleSearch" @clear="handleSearch" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.recharge.status')">
              <ElSelect v-model="searchForm.status" clearable :placeholder="t('zcard.order.allStatus')" style="width: 120px" @change="handleSearch">
                <ElOption :label="t('zcard.recharge.statusPending')" value="pending" />
                <ElOption :label="t('zcard.recharge.statusPaid')" value="paid" />
                <ElOption :label="t('zcard.recharge.statusClosed')" value="closed" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="t('zcard.recharge.target')">
              <ElSelect v-model="searchForm.target" clearable :placeholder="t('zcard.order.allStatus')" style="width: 130px" @change="handleSearch">
                <ElOption :label="t('zcard.recharge.targetBalance')" value="balance" />
                <ElOption :label="t('zcard.recharge.targetSupply')" value="supply" />
              </ElSelect>
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
      <ElTable ref="tableRef" :data="recharges" v-loading="loading" :height="tableHeight" border stripe>
        <ElTableColumn :label="t('zcard.recharge.rechargeNo')" min-width="200">
          <template #default="{ row }">{{ row.recharge_no }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.recharge.user')" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.user">{{ row.user.username }} <span class="text-muted">({{ row.user.email }})</span></span>
            <span v-else class="text-muted">-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.recharge.amount')" width="110" align="right">
          <template #default="{ row }">
            <span class="amount-text">¥{{ formatAmount(row.amount) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.recharge.target')" width="110" align="center">
          <template #default="{ row }">
            <ElTag :type="row.target === 'supply' ? 'info' : 'primary'" size="small">{{ targetLabel(row.target) }}</ElTag>          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.recharge.status')" width="90" align="center">
          <template #default="{ row }">
            <ElTag :type="statusTagType(row.status)" size="small">{{ statusLabel(row.status) }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.recharge.createTime')" width="160" align="center">
          <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.recharge.paidTime')" width="160" align="center">
          <template #default="{ row }">{{ row.paid_at ? formatTime(row.paid_at) : '-' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.common.actions')" width="100" fixed="right" align="center">
          <template #default="{ row }">
            <ElButton text type="primary" size="small" @click="showDetail(row)">{{ t('zcard.order.detail') }}</ElButton>
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
          @size-change="fetchList"
          @current-change="fetchList"
        />
      </div>
    </ElCard>

    <!-- 详情弹窗 -->
    <ElDialog v-model="detailVisible" :title="t('zcard.recharge.detail')" width="560px" destroy-on-close>
      <div v-if="currentRecharge" class="detail-content">
        <ElDescriptions :column="2" border>
          <ElDescriptionsItem :label="t('zcard.recharge.rechargeNo')">{{ currentRecharge.recharge_no }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.recharge.status')">
            <ElTag :type="statusTagType(currentRecharge.status)" size="small">{{ statusLabel(currentRecharge.status) }}</ElTag>
          </ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.recharge.user')">
            {{ currentRecharge.user?.username || '-' }} ({{ currentRecharge.user?.email || '-' }})
          </ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.recharge.target')">{{ targetLabel(currentRecharge.target) }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.recharge.amount')">¥{{ formatAmount(currentRecharge.amount) }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.recharge.userId')">{{ currentRecharge.user_id }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.recharge.createTime')">{{ formatTime(currentRecharge.created_at) }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.recharge.paidTime')">{{ currentRecharge.paid_at ? formatTime(currentRecharge.paid_at) : '-' }}</ElDescriptionsItem>
        </ElDescriptions>
      </div>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { useI18n } from 'vue-i18n'
  import { useListTableHeight } from '@/hooks'
  import {
    getRecharges,
    getRecharge,
    getRechargeStats,
    type Recharge,
    type RechargeStats,
    type RechargeStatus,
    type RechargeTarget,
  } from '@/api/recharges'

  defineOptions({ name: 'RechargeList' })

  const { t } = useI18n()

  const loading = ref(false)
  const showSearch = ref(true)
  const recharges = ref<Recharge[]>([])

  const pagination = reactive({ page: 1, pageSize: 15, total: 0 })
  const { cardRef, tableRef, paginationRef, tableHeight } = useListTableHeight()
  const searchForm = reactive({
    keyword: '',
    status: '' as RechargeStatus | '',
    target: '' as RechargeTarget | '',
  })
  const dateRange = ref<[string, string] | null>(null)

  const stats = ref<RechargeStats>({
    total_count: 0,
    total_amount: 0,
    pending_amount: 0,
    paid_amount: 0,
    closed_amount: 0,
  })

  const statCards = computed(() => [
    { key: 'count', label: 'zcard.recharge.statCount', value: stats.value.total_count, icon: 'ri:bank-card-line', color: '#409eff', isAmount: false },
    { key: 'pending', label: 'zcard.recharge.statPending', value: stats.value.pending_amount, icon: 'ri:time-line', color: '#e6a23c', isAmount: true },
    { key: 'paid', label: 'zcard.recharge.statPaid', value: stats.value.paid_amount, icon: 'ri:checkbox-circle-line', color: '#67c23a', isAmount: true },
    { key: 'total', label: 'zcard.recharge.statTotal', value: stats.value.total_amount, icon: 'ri:money-cny-circle-line', color: '#67c23a', isAmount: true },
    { key: 'closed', label: 'zcard.recharge.statClosed', value: stats.value.closed_amount, icon: 'ri:close-circle-line', color: '#909399', isAmount: true },
  ])

  const formatAmount = (fen: number | string | null | undefined): string =>
    (Number(fen || 0) / 100).toFixed(2)

  const formatCount = (n: number | string | null | undefined): string =>
    Number(n || 0).toLocaleString()

  const formatTime = (d: string | null) => (d ? String(d).slice(0, 19).replace('T', ' ') : '-')

  const statusLabel = (s: RechargeStatus) => ({
    pending: t('zcard.recharge.statusPending'),
    paid: t('zcard.recharge.statusPaid'),
    closed: t('zcard.recharge.statusClosed'),
  }[s] || s)

  const statusTagType = (s: RechargeStatus): 'warning' | 'success' | 'info' => {
    const map: Record<RechargeStatus, 'warning' | 'success' | 'info'> = {
      pending: 'warning',
      paid: 'success',
      closed: 'info',
    }
    return map[s]
  }

  const targetLabel = (tg: RechargeTarget) =>
    tg === 'supply' ? t('zcard.recharge.targetSupply') : t('zcard.recharge.targetBalance')

  const buildParams = () => {
    const params: Record<string, unknown> = {
      page: pagination.page,
      pageSize: pagination.pageSize,
    }
    if (searchForm.keyword.trim()) params.keyword = searchForm.keyword.trim()
    if (searchForm.status) params.status = searchForm.status
    if (searchForm.target) params.target = searchForm.target
    if (dateRange.value) {
      params.start_date = dateRange.value[0]
      params.end_date = dateRange.value[1]
    }
    return params
  }

  const fetchList = async () => {
    loading.value = true
    try {
      const res = await getRecharges(buildParams())
      recharges.value = res.data
      pagination.total = res.total
    } finally {
      loading.value = false
    }
  }

  const fetchStats = async () => {
    try {
      stats.value = await getRechargeStats(buildParams())
    } catch {
      /* 拦截器处理 */
    }
  }

  const handleSearch = () => {
    pagination.page = 1
    fetchList()
    fetchStats()
  }

  const handleReset = () => {
    searchForm.keyword = ''
    searchForm.status = ''
    searchForm.target = ''
    dateRange.value = null
    pagination.page = 1
    fetchList()
    fetchStats()
  }

  // 详情弹窗
  const detailVisible = ref(false)
  const currentRecharge = ref<Recharge | null>(null)
  const showDetail = async (row: Recharge) => {
    currentRecharge.value = row
    detailVisible.value = true
    try {
      currentRecharge.value = await getRecharge(row.id)
    } catch {
      /* 拦截器处理 */
    }
  }

  onMounted(() => {
    fetchList()
    fetchStats()
  })
</script>

<style lang="scss" scoped>
  .recharge-page {
    display: flex;
    flex-direction: column;
  }

  .toolbar-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }

  .detail-content {
    .text-muted {
      color: var(--el-text-color-secondary);
    }
  }

  .amount-text {
    color: var(--el-color-danger);
    font-weight: 600;
  }

  :deep(.stats-grid) {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    margin-bottom: 12px;
  }

  :deep(.stat-card) {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    border-radius: 8px;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter);
    transition: box-shadow 0.2s;

    &:hover {
      box-shadow: 0 4px 16px rgba(0, 0, 0, 0.06);
    }

    .stat-icon {
      width: 44px;
      height: 44px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 22px;
      color: var(--accent, var(--el-color-primary));
      background: color-mix(in srgb, var(--accent, var(--el-color-primary)) 12%, transparent);
    }

    .stat-label {
      font-size: 12px;
      color: var(--el-text-color-secondary);
    }

    .stat-value {
      font-size: 20px;
      font-weight: 700;
      color: var(--el-text-color-primary);
      line-height: 1.3;
    }
  }
</style>
