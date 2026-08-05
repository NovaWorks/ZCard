<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useListTableHeight } from '@/hooks'
import {
  getReviews,
  getReviewStats,
  approveReview,
  rejectReview,
  type ReviewRecord,
  type ReviewStats,
} from '@/api/review'

defineOptions({ name: 'ReviewList' })

const { t } = useI18n()
const loading = ref(false)
const list = ref<ReviewRecord[]>([])
const statusFilter = ref<string>('')
const pagination = reactive({ page: 1, pageSize: 20, total: 0 })
// 表格高度自适应:数据满页时表格内容撑高会被卡片裁掉分页栏,固定表格高度使其内部滚动
const { cardRef, tableRef, paginationRef, tableHeight } = useListTableHeight()
const stats = ref<ReviewStats>({ total: 0, pending: 0, approved: 0, rejected: 0 })

const statCards = computed(() => [
  { label: t('zcard.review.statTotal'), value: stats.value.total, icon: '📝', color: '#909399' },
  { label: t('zcard.review.statPending'), value: stats.value.pending, icon: '⏳', color: '#e6a23c' },
  { label: t('zcard.review.statApproved'), value: stats.value.approved, icon: '✅', color: '#67c23a' },
  { label: t('zcard.review.statRejected'), value: stats.value.rejected, icon: '🚫', color: '#f56c6c' },
])

const formatCount = (n: number) => Number(n || 0).toLocaleString()
const formatTime = (d: string) => (d ? String(d).slice(0, 19).replace('T', ' ') : '-')

const statusTagType = (status: string) =>
  status === 'approved' ? 'success' : status === 'rejected' ? 'danger' : 'warning'

const statusLabel = (status: string) => {
  if (status === 'approved') return t('zcard.review.approved')
  if (status === 'rejected') return t('zcard.review.rejected')
  return t('zcard.review.pending')
}

const buildParams = () => ({
  page: pagination.page,
  page_size: pagination.pageSize,
  status: statusFilter.value || undefined,
})

const fetchData = async () => {
  loading.value = true
  try {
    const [pageData, statsData] = await Promise.all([getReviews(buildParams()), getReviewStats()])
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
  statusFilter.value = ''
  pagination.page = 1
  fetchData()
}

const handleApprove = async (row: ReviewRecord) => {
  try {
    await approveReview(row.id)
    row.status = 'approved'
    stats.value.pending = Math.max(0, stats.value.pending - 1)
    stats.value.approved += 1
  } catch {
    // 错误已由拦截器提示
  }
}

const handleReject = async (row: ReviewRecord) => {
  try {
    await rejectReview(row.id)
    row.status = 'rejected'
    stats.value.pending = Math.max(0, stats.value.pending - 1)
    stats.value.rejected += 1
  } catch {
    // 错误已由拦截器提示
  }
}

onMounted(fetchData)
</script>

<template>
  <div class="review-page art-full-height">
    <!-- 统计卡片 -->
    <div class="stats-grid">
      <div v-for="card in statCards" :key="card.label" class="stat-card" :style="{ '--accent': card.color }">
        <div class="stat-icon">{{ card.icon }}</div>
        <div class="stat-body">
          <div class="stat-label">{{ card.label }}</div>
          <div class="stat-value">{{ formatCount(card.value) }}</div>
        </div>
      </div>
    </div>

    <ElCard ref="cardRef" class="art-table-card" shadow="never">
      <!-- 工具栏 -->
      <div class="toolbar">
        <div class="toolbar-left">
          <ElSelect v-model="statusFilter" :placeholder="t('zcard.review.status')" style="width: 160px" @change="handleSearch">
            <ElOption :label="t('zcard.review.allStatus')" value="" />
            <ElOption :label="t('zcard.review.pending')" value="pending" />
            <ElOption :label="t('zcard.review.approved')" value="approved" />
            <ElOption :label="t('zcard.review.rejected')" value="rejected" />
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
        <ElTableColumn :label="t('zcard.review.productName')" min-width="160">
          <template #default="{ row }">{{ row.product?.name || `#${row.product_id}` }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.review.username')" min-width="120">
          <template #default="{ row }">{{ row.user?.username || `#${row.user_id}` }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.review.rating')" width="130" align="center">
          <template #default="{ row }">
            <span class="rating-stars">
              <span v-for="n in 5" :key="n" :class="n <= (row.rating || 0) ? 'star-on' : 'star-off'">★</span>
              <span class="rating-num">{{ row.rating }}</span>
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.review.content')" min-width="240">
          <template #default="{ row }">
            <span class="content-text">{{ row.content || '-' }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.review.status')" width="100" align="center">
          <template #default="{ row }">
            <ElTag :type="statusTagType(row.status)" size="small">{{ statusLabel(row.status) }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.review.date')" width="160" align="center">
          <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.review.operation')" width="150" align="center" fixed="right">
          <template #default="{ row }">
            <template v-if="row.status === 'pending'">
              <ElButton type="success" size="small" link @click="handleApprove(row)">
                {{ t('zcard.review.approve') }}
              </ElButton>
              <ElButton type="danger" size="small" link @click="handleReject(row)">
                {{ t('zcard.review.reject') }}
              </ElButton>
            </template>
            <span v-else class="text-muted">-</span>
          </template>
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
  .review-page { display: flex; flex-direction: column; gap: 16px; }
  .stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
  .stat-card { display: flex; align-items: center; gap: 12px; padding: 16px; background: var(--el-bg-color); border: 1px solid var(--el-border-color-lighter); border-radius: 8px; border-left: 3px solid var(--accent); }
  .stat-icon { font-size: 28px; }
  .stat-label { font-size: 12px; color: var(--el-text-color-secondary); }
  .stat-value { margin-top: 4px; font-size: 20px; font-weight: 700; }
  .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
  .toolbar-left { display: flex; gap: 8px; flex-wrap: wrap; }
  .rating-stars { display: inline-flex; align-items: center; gap: 4px; }
  .star-on { color: #f7ba2a; }
  .star-off { color: var(--el-border-color); }
  .rating-num { font-size: 12px; color: var(--el-text-color-secondary); }
  .content-text { display: inline-block; max-width: 100%; word-break: break-word; white-space: pre-wrap; }
  .text-muted { color: var(--el-text-color-placeholder); }
  .pagination-wrap { display: flex; justify-content: flex-end; margin-top: 16px; }
</style>
