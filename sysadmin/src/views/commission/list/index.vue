<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useListTableHeight } from '@/hooks'
import {
  getCommissions,
  getCommissionStats,
  type CommissionRecord,
  type CommissionStats,
} from '@/api/commission'

defineOptions({ name: 'CommissionList' })

const { t } = useI18n()
const loading = ref(false)
const list = ref<CommissionRecord[]>([])
const keyword = ref('')
const tierFilter = ref<number | ''>('')
const statusFilter = ref<string>('')

const pagination = reactive({ page: 1, pageSize: 20, total: 0 })
// 表格高度自适应:数据满页时表格内容撑高会被卡片裁掉分页栏,固定表格高度使其内部滚动
const { cardRef, tableRef, paginationRef, tableHeight } = useListTableHeight()
const stats = ref<CommissionStats>({
  total_amount: 0,
  total_count: 0,
  available_amount: 0,
  pending_amount: 0,
  paid_amount: 0,
})

const statCards = computed(() => [
  { label: t('zcard.commission.statTotal'), value: stats.value.total_amount, icon: 'ri:money-cny-circle-line', color: '#67c23a', isAmount: true },
  { label: t('zcard.commission.statCount'), value: stats.value.total_count, icon: 'ri:archive-line', color: '#909399', isAmount: false },
  { label: t('zcard.commission.statAvailable'), value: stats.value.available_amount, icon: 'ri:bank-line', color: '#409eff', isAmount: true },
])

const formatAmount = (fen: number) => (Number(fen || 0) / 100).toFixed(2)
const formatCount = (n: number) => Number(n || 0).toLocaleString()
const formatTime = (d: string) => (d ? String(d).slice(0, 19).replace('T', ' ') : '-')

const tierTagType = (tier: number) => (tier === 1 ? 'success' : tier === 2 ? 'warning' : 'info')
const tierLabel = (tier: number) => (tier === 1 ? t('zcard.commission.tier1') : tier === 2 ? t('zcard.commission.tier2') : t('zcard.commission.tier3'))
const statusTagType = (status: string) => (status === 'available' ? 'success' : status === 'pending' ? 'warning' : 'info')
const statusLabel = (status: string) => {
  if (status === 'available') return t('zcard.commission.available')
  if (status === 'pending') return t('zcard.commission.pending')
  if (status === 'paid') return t('zcard.commission.paid')
  return status
}

const buildParams = () => ({
  page: pagination.page,
  page_size: pagination.pageSize,
  keyword: keyword.value || undefined,
  tier: tierFilter.value !== '' ? tierFilter.value : undefined,
  status: statusFilter.value !== '' ? statusFilter.value : undefined,
})

const fetchData = async () => {
  loading.value = true
  try {
    const [pageData, statsData] = await Promise.all([
      getCommissions(buildParams()),
      getCommissionStats(),
    ])
    list.value = pageData.data || []
    pagination.total = pageData.total || 0
    stats.value = statsData
  } catch {
    list.value = []
  } finally {
    loading.value = false
  }
}

const handleSearch = () => {
  pagination.page = 1
  fetchData()
}

const resetSearch = () => {
  keyword.value = ''
  tierFilter.value = ''
  statusFilter.value = ''
  pagination.page = 1
  fetchData()
}

onMounted(fetchData)
</script>

<template>
  <div class="commission-page art-full-height">
    <!-- 统计卡片 -->
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
      <!-- 工具栏 -->
      <div class="toolbar">
        <div class="toolbar-left">
          <ElInput
            v-model="keyword"
            :placeholder="t('zcard.commission.keywordPlaceholder')"
            clearable
            style="width: 220px"
            @keyup.enter="handleSearch"
            @clear="resetSearch"
          />
          <ElSelect v-model="tierFilter" :placeholder="t('zcard.commission.tier')" style="width: 140px" @change="handleSearch">
            <ElOption :label="t('zcard.commission.allTiers')" value="" />
            <ElOption :label="t('zcard.commission.tier1')" :value="1" />
            <ElOption :label="t('zcard.commission.tier2')" :value="2" />
            <ElOption :label="t('zcard.commission.tier3')" :value="3" />
          </ElSelect>
          <ElSelect v-model="statusFilter" :placeholder="t('zcard.commission.status')" style="width: 140px" @change="handleSearch">
            <ElOption :label="t('zcard.commission.allStatus')" value="" />
            <ElOption :label="t('zcard.commission.available')" value="available" />
            <ElOption :label="t('zcard.commission.pending')" value="pending" />
            <ElOption :label="t('zcard.commission.paid')" value="paid" />
          </ElSelect>
          <ElButton type="primary" @click="handleSearch">{{ t('zcard.common.search') }}</ElButton>
          <ElButton @click="resetSearch">{{ t('zcard.common.reset') }}</ElButton>
        </div>
      </div>

      <!-- 表格 -->
      <ElTable ref="tableRef" :data="list" v-loading="loading" :height="tableHeight" border stripe>
        <ElTableColumn label="ID" width="70" align="center">
          <template #default="{ row }">{{ row.id }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.commission.orderNo')" min-width="160">
          <template #default="{ row }">{{ row.order?.order_no || `#${row.order_id}` }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.commission.referrer')" min-width="130">
          <template #default="{ row }">{{ row.referrer?.username || `#${row.referrer_id}` }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.commission.buyer')" min-width="130">
          <template #default="{ row }">{{ row.buyer?.username || `#${row.buyer_id}` }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.commission.tier')" width="90" align="center">
          <template #default="{ row }">
            <ElTag :type="tierTagType(row.tier)" size="small">{{ tierLabel(row.tier) }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.commission.rate')" width="90" align="center">
          <template #default="{ row }">{{ row.rate }}%</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.commission.base')" width="120" align="right">
          <template #default="{ row }"><span class="text-muted">¥{{ formatAmount(row.base_amount) }}</span></template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.commission.amount')" width="120" align="right">
          <template #default="{ row }"><span class="income-text">¥{{ formatAmount(row.amount) }}</span></template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.commission.status')" width="100" align="center">
          <template #default="{ row }">
            <ElTag :type="statusTagType(row.status)" size="small">{{ statusLabel(row.status) }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.commission.date')" width="160" align="center">
          <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
        </ElTableColumn>
      </ElTable>

      <div ref="paginationRef" class="pagination-wrap">
        <ElPagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.pageSize"
          :total="pagination.total"
          :page-sizes="[20, 30, 50, 100]"
          layout="total, sizes, prev, pager, next"
          @size-change="fetchData"
          @current-change="fetchData"
        />
      </div>
    </ElCard>
  </div>
</template>

<style lang="scss" scoped>
  .commission-page { display: flex; flex-direction: column; gap: 16px; }
  .stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
  .stat-card { display: flex; align-items: center; gap: 12px; padding: 16px; background: var(--el-bg-color); border: 1px solid var(--el-border-color-lighter); border-radius: 8px; border-left: 3px solid var(--accent); }
  .stat-icon { font-size: 28px; }
  .stat-label { font-size: 12px; color: var(--el-text-color-secondary); }
  .stat-value { margin-top: 4px; font-size: 20px; font-weight: 700; }
  .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
  .toolbar-left { display: flex; gap: 8px; flex-wrap: wrap; }
  .income-text { font-weight: 600; color: var(--el-color-success); }
  .text-muted { color: var(--el-text-color-placeholder); }
  .pagination-wrap { display: flex; justify-content: flex-end; margin-top: 16px; }
</style>
