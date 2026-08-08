<script setup lang="ts">
import { ref, computed, onActivated } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import { useListTableHeight } from '@/hooks'
import {
  getWithdrawals, getWithdrawalStats, approveWithdrawal, rejectWithdrawal,
  type Withdrawal, type WithdrawalStats,
} from '@/api/withdrawals'

defineOptions({ name: 'WithdrawalList' })

const { t } = useI18n()
const loading = ref(false)
const list = ref<Withdrawal[]>([])
const keyword = ref('')
const statusFilter = ref('')
const methodFilter = ref('')
const dateRange = ref<[string, string] | null>(null)
const pagination = reactive({ page: 1, pageSize: 15, total: 0 })
// 表格高度自适应:数据满页时表格内容撑高会被卡片裁掉分页栏,固定表格高度使其内部滚动
const { cardRef, tableRef, paginationRef, tableHeight } = useListTableHeight()
const stats = ref<WithdrawalStats>({ pending_count: 0, approved_count: 0, rejected_count: 0, pending_amount: 0, approved_amount: 0, total_count: 0 })

const statCards = computed(() => [
  { label: t('zcard.withdrawal.statPending'), value: stats.value.pending_count, icon: 'ri:time-line', color: '#e6a23c', isAmount: false },
  { label: t('zcard.withdrawal.statPendingAmount'), value: stats.value.pending_amount, icon: 'ri:money-cny-circle-line', color: '#e6a23c', isAmount: true },
  { label: t('zcard.withdrawal.statApproved'), value: stats.value.approved_count, icon: 'ri:checkbox-circle-line', color: '#67c23a', isAmount: false },
  { label: t('zcard.withdrawal.statApprovedAmount'), value: stats.value.approved_amount, icon: 'ri:money-dollar-circle-line', color: '#67c23a', isAmount: true },
  { label: t('zcard.withdrawal.statRejected'), value: stats.value.rejected_count, icon: 'ri:close-circle-line', color: '#f56c6c', isAmount: false },
  { label: t('zcard.withdrawal.statTotal'), value: stats.value.total_count, icon: 'ri:archive-line', color: '#909399', isAmount: false },
])

const formatAmount = (fen: number) => (Number(fen || 0) / 100).toFixed(2)
const formatCount = (n: number) => Number(n || 0).toLocaleString()
const formatTime = (d: string) => (d ? String(d).slice(0, 19).replace('T', ' ') : '-')

const statusTagType = (s: string) => ({ pending: 'warning', approved: 'success', rejected: 'danger' }[s] || 'info') as any
const statusLabel = (s: string) => ({ pending: t('zcard.withdrawal.statusPending'), approved: t('zcard.withdrawal.statusApproved'), rejected: t('zcard.withdrawal.statusRejected') }[s] || s)
const methodLabel = (m: string) => ({ alipay: '支付宝', wechat: '微信', usdt: 'USDT' }[m] || m)

const buildParams = () => ({
  page: pagination.page, pageSize: pagination.pageSize,
  keyword: keyword.value || undefined,
  status: statusFilter.value || undefined,
  method: methodFilter.value || undefined,
  start_date: dateRange.value?.[0] || undefined,
  end_date: dateRange.value?.[1] || undefined,
})

const fetchData = async () => {
  loading.value = true
  try {
    const [pageData, statsData] = await Promise.all([getWithdrawals(buildParams()), getWithdrawalStats(buildParams())])
    list.value = pageData.data || []
    pagination.total = pageData.total || 0
    stats.value = statsData
  } catch { list.value = [] }
  finally { loading.value = false }
}

const handleSearch = () => { pagination.page = 1; fetchData() }
const resetSearch = () => { keyword.value = ''; statusFilter.value = ''; methodFilter.value = ''; dateRange.value = null; pagination.page = 1; fetchData() }

const handleApprove = (row: Withdrawal) => {
  ElMessageBox.confirm(t('zcard.withdrawal.approveConfirm'), t('zcard.common.tips'), { type: 'warning' })
    .then(async () => {
      try { await approveWithdrawal(row.id); ElMessage.success(t('zcard.withdrawal.approveSuccess')); fetchData() }
      catch (e: any) { ElMessage.error(e?.response?.data?.message || t('zcard.withdrawal.approveFailed')) }
    }).catch(() => {})
}

const handleReject = (row: Withdrawal) => {
  ElMessageBox.prompt(t('zcard.withdrawal.rejectReasonPrompt'), t('zcard.withdrawal.reject'), {
    type: 'warning',
    inputPlaceholder: t('zcard.withdrawal.rejectReasonPlaceholder'),
    inputValidator: (v) => !!v?.trim() || t('zcard.withdrawal.rejectReasonRequired'),
  })
    .then(async ({ value }) => {
      try { await rejectWithdrawal(row.id, value); ElMessage.success(t('zcard.withdrawal.rejectSuccess')); fetchData() }
      catch (e: any) { ElMessage.error(e?.response?.data?.message || t('zcard.withdrawal.rejectFailed')) }
    }).catch(() => {})
}

onActivated(fetchData)
</script>

<template>
  <div class="withdrawal-page art-full-height">
    <div class="stats-grid">
      <div v-for="card in statCards" :key="card.label" class="stat-card" :style="{ '--accent': card.color }">
        <div class="stat-icon"><ArtSvgIcon :icon="card.icon" /></div>
        <div class="stat-body">
          <div class="stat-label">{{ card.label }}</div>
          <div class="stat-value">{{ card.isAmount ? '¥' + formatAmount(card.value) : formatCount(card.value) }}</div>
        </div>
      </div>
    </div>

    <ElCard ref="cardRef" class="art-table-card" shadow="never">
      <div class="toolbar">
        <div class="toolbar-left">
          <ElInput v-model="keyword" :placeholder="t('zcard.withdrawal.searchPlaceholder')" clearable style="width: 200px" @keyup.enter="handleSearch" @clear="resetSearch" />
          <ElSelect v-model="statusFilter" :placeholder="t('zcard.withdrawal.status')" style="width: 120px" @change="handleSearch">
            <ElOption :label="t('zcard.order.allStatus')" value="" />
            <ElOption :label="t('zcard.withdrawal.statusPending')" value="pending" />
            <ElOption :label="t('zcard.withdrawal.statusApproved')" value="approved" />
            <ElOption :label="t('zcard.withdrawal.statusRejected')" value="rejected" />
          </ElSelect>
          <ElSelect v-model="methodFilter" :placeholder="t('zcard.withdrawal.method')" style="width: 120px" @change="handleSearch">
            <ElOption :label="t('zcard.order.allStatus')" value="" />
            <ElOption label="支付宝" value="alipay" />
            <ElOption label="微信" value="wechat" />
            <ElOption label="USDT" value="usdt" />
          </ElSelect>
          <ElDatePicker v-model="dateRange" type="daterange" range-separator="-" start-placeholder="开始" end-placeholder="结束" value-format="YYYY-MM-DD" style="width: 240px" @change="handleSearch" />
          <ElButton type="primary" @click="handleSearch">{{ t('zcard.common.search') }}</ElButton>
          <ElButton @click="resetSearch">{{ t('zcard.common.reset') }}</ElButton>
        </div>
      </div>

      <ElTable ref="tableRef" :data="list" v-loading="loading" :height="tableHeight" border stripe>
        <ElTableColumn :label="t('zcard.withdrawal.user')" min-width="120">
          <template #default="{ row }">{{ row.user?.username || `#${row.user_id}` }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.withdrawal.amount')" width="100" align="right">
          <template #default="{ row }"><span class="amount-text">¥{{ formatAmount(row.amount) }}</span></template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.withdrawal.fee')" width="80" align="right">
          <template #default="{ row }"><span class="text-muted">¥{{ formatAmount(row.fee) }}</span></template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.withdrawal.actual')" width="100" align="right">
          <template #default="{ row }"><span class="actual-text">¥{{ formatAmount(row.actual_amount) }}</span></template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.withdrawal.method')" width="100" align="center">
          <template #default="{ row }">{{ methodLabel(row.method) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.withdrawal.account')" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">
            <div>{{ row.account_name }}</div>
            <div class="text-muted text-xs">{{ row.account }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.withdrawal.status')" width="90" align="center">
          <template #default="{ row }"><ElTag :type="statusTagType(row.status)" size="small">{{ statusLabel(row.status) }}</ElTag></template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.withdrawal.time')" width="160" align="center">
          <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.common.actions')" width="160" align="center" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 'pending'">
              <ElButton text type="success" size="small" @click="handleApprove(row)">{{ t('zcard.withdrawal.approve') }}</ElButton>
              <ElButton text type="danger" size="small" @click="handleReject(row)">{{ t('zcard.withdrawal.reject') }}</ElButton>
            </template>
            <span v-else-if="row.status === 'rejected'" class="text-muted text-xs">{{ row.reject_reason }}</span>
            <span v-else class="text-muted text-xs">{{ formatTime(row.processed_at) }}</span>
          </template>
        </ElTableColumn>
      </ElTable>

      <div ref="paginationRef" class="pagination-wrap">
        <ElPagination v-model:current-page="pagination.page" v-model:page-size="pagination.pageSize"
          :total="pagination.total" :page-sizes="[15, 30, 50, 100]"
          layout="total, sizes, prev, pager, next" @size-change="fetchData" @current-change="fetchData" />
      </div>
    </ElCard>
  </div>
</template>

<style lang="scss" scoped>
  .withdrawal-page { display: flex; flex-direction: column; gap: 16px; }
  .stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
  .stat-card { display: flex; align-items: center; gap: 12px; padding: 16px; background: var(--el-bg-color); border: 1px solid var(--el-border-color-lighter); border-radius: 8px; border-left: 3px solid var(--accent); }
  .stat-icon { font-size: 28px; }
  .stat-label { font-size: 12px; color: var(--el-text-color-secondary); }
  .stat-value { margin-top: 4px; font-size: 20px; font-weight: 700; }
  .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
  .toolbar-left { display: flex; gap: 8px; flex-wrap: wrap; }
  .amount-text { font-weight: 600; }
  .actual-text { font-weight: 600; color: var(--el-color-success); }
  .text-muted { color: var(--el-text-color-placeholder); }
  .pagination-wrap { display: flex; justify-content: flex-end; margin-top: 16px; }
</style>
