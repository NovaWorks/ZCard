<script setup lang="ts">
import { ref, computed, onActivated } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import { getBills, getBillStats, adjustBalance, type Bill, type BillStats } from '@/api/bills'

defineOptions({ name: 'BillList' })

const { t } = useI18n()
const loading = ref(false)
const list = ref<Bill[]>([])
const keyword = ref('')
const typeFilter = ref<number | ''>('')
const dateRange = ref<[string, string] | null>(null)

const pagination = reactive({ page: 1, pageSize: 15, total: 0 })
const stats = ref<BillStats>({ total_income: 0, total_expense: 0, net_amount: 0, total_count: 0 })

const statCards = computed(() => [
  { label: t('zcard.bill.statIncome'), value: stats.value.total_income, icon: '💰', color: '#67c23a', isAmount: true },
  { label: t('zcard.bill.statExpense'), value: stats.value.total_expense, icon: '📤', color: '#f56c6c', isAmount: true },
  { label: t('zcard.bill.statNet'), value: stats.value.net_amount, icon: '📊', color: '#409eff', isAmount: true },
  { label: t('zcard.bill.statCount'), value: stats.value.total_count, icon: '📦', color: '#909399', isAmount: false },
])

const formatAmount = (fen: number) => (Number(fen || 0) / 100).toFixed(2)
const formatCount = (n: number) => Number(n || 0).toLocaleString()
const formatTime = (d: string) => (d ? String(d).slice(0, 19).replace('T', ' ') : '-')

const buildParams = () => ({
  page: pagination.page,
  pageSize: pagination.pageSize,
  keyword: keyword.value || undefined,
  type: typeFilter.value !== '' ? typeFilter.value : undefined,
  start_date: dateRange.value?.[0] || undefined,
  end_date: dateRange.value?.[1] || undefined,
})

const fetchData = async () => {
  loading.value = true
  try {
    const [pageData, statsData] = await Promise.all([
      getBills(buildParams()),
      getBillStats(buildParams()),
    ])
    list.value = pageData.data || []
    pagination.total = pageData.total || 0
    stats.value = statsData
  } catch { list.value = [] }
  finally { loading.value = false }
}

const handleSearch = () => { pagination.page = 1; fetchData() }
const resetSearch = () => {
  keyword.value = ''; typeFilter.value = ''; dateRange.value = null
  pagination.page = 1; fetchData()
}

// 手动调账
const adjustVisible = ref(false)
const adjustSaving = ref(false)
const adjustForm = ref({ user_id: 0, amount: 0, type: 1, log: '' })

const openAdjust = () => {
  adjustForm.value = { user_id: 0, amount: 0, type: 1, log: '' }
  adjustVisible.value = true
}

const handleAdjust = async () => {
  if (!adjustForm.value.user_id) { ElMessage.warning(t('zcard.bill.adjustUserRequired')); return }
  if (!adjustForm.value.amount || adjustForm.value.amount <= 0) { ElMessage.warning(t('zcard.bill.adjustAmountRequired')); return }
  if (!adjustForm.value.log.trim()) { ElMessage.warning(t('zcard.bill.adjustLogRequired')); return }
  adjustSaving.value = true
  try {
    await adjustBalance({
      user_id: adjustForm.value.user_id,
      amount: adjustForm.value.amount,
      type: adjustForm.value.type,
      log: adjustForm.value.log,
    })
    ElMessage.success(t('zcard.bill.adjustSuccess'))
    adjustVisible.value = false
    fetchData()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || t('zcard.bill.adjustFailed'))
  } finally { adjustSaving.value = false }
}

onActivated(fetchData)
</script>

<template>
  <div class="bill-page art-full-height">
    <!-- 统计卡片 -->
    <div class="stats-grid">
      <div v-for="card in statCards" :key="card.label" class="stat-card" :style="{ '--accent': card.color }">
        <div class="stat-icon">{{ card.icon }}</div>
        <div class="stat-body">
          <div class="stat-label">{{ card.label }}</div>
          <div class="stat-value">{{ card.isAmount ? '¥' + formatAmount(card.value) : formatCount(card.value) }}</div>
        </div>
      </div>
    </div>

    <ElCard class="art-table-card" shadow="never">
      <!-- 工具栏 -->
      <div class="toolbar">
        <div class="toolbar-left">
          <ElInput v-model="keyword" :placeholder="t('zcard.bill.searchPlaceholder')" clearable style="width: 220px" @keyup.enter="handleSearch" @clear="resetSearch" />
          <ElSelect v-model="typeFilter" :placeholder="t('zcard.bill.type')" style="width: 120px" @change="handleSearch">
            <ElOption :label="t('zcard.order.allStatus')" value="" />
            <ElOption :label="t('zcard.bill.typeIncome')" :value="1" />
            <ElOption :label="t('zcard.bill.typeExpense')" :value="0" />
          </ElSelect>
          <ElDatePicker v-model="dateRange" type="daterange" range-separator="-" start-placeholder="开始" end-placeholder="结束" value-format="YYYY-MM-DD" style="width: 240px" @change="handleSearch" />
          <ElButton type="primary" @click="handleSearch">{{ t('zcard.common.search') }}</ElButton>
          <ElButton @click="resetSearch">{{ t('zcard.common.reset') }}</ElButton>
        </div>
        <ElButton type="warning" plain @click="openAdjust">✏️ {{ t('zcard.bill.adjust') }}</ElButton>
      </div>

      <!-- 表格 -->
      <ElTable :data="list" v-loading="loading" border stripe>
        <ElTableColumn :label="t('zcard.bill.user')" min-width="130">
          <template #default="{ row }">{{ row.user?.username || `#${row.user_id}` }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.bill.amount')" width="120" align="right">
          <template #default="{ row }">
            <span :class="row.type === 1 ? 'income-text' : 'expense-text'">
              {{ row.type === 1 ? '+' : '-' }}¥{{ formatAmount(row.amount) }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.bill.balanceAfter')" width="120" align="right">
          <template #default="{ row }"><span class="text-muted">¥{{ formatAmount(row.balance_after) }}</span></template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.bill.type')" width="90" align="center">
          <template #default="{ row }">
            <ElTag :type="row.type === 1 ? 'success' : 'danger'" size="small">
              {{ row.type === 1 ? t('zcard.bill.typeIncome') : t('zcard.bill.typeExpense') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.bill.log')" min-width="200" show-overflow-tooltip>
          <template #default="{ row }">
            <span>{{ row.log }}</span>
            <span v-if="row.order" class="text-muted text-xs ml-1">({{ row.order.order_no }})</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.bill.time')" width="160" align="center">
          <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
        </ElTableColumn>
      </ElTable>

      <div class="pagination-wrap">
        <ElPagination v-model:current-page="pagination.page" v-model:page-size="pagination.pageSize"
          :total="pagination.total" :page-sizes="[15, 30, 50, 100]"
          layout="total, sizes, prev, pager, next" @size-change="fetchData" @current-change="fetchData" />
      </div>
    </ElCard>

    <!-- 手动调账弹窗 -->
    <ElDialog v-model="adjustVisible" :title="t('zcard.bill.adjust')" width="480px" destroy-on-close>
      <ElForm :model="adjustForm" label-width="90px">
        <ElFormItem :label="t('zcard.bill.userId')" required>
          <ElInputNumber v-model="adjustForm.user_id" :min="1" :placeholder="t('zcard.bill.userIdPlaceholder')" style="width: 100%" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.bill.type')" required>
          <ElRadioGroup v-model="adjustForm.type">
            <ElRadio :value="1">{{ t('zcard.bill.typeIncome') }}</ElRadio>
            <ElRadio :value="0">{{ t('zcard.bill.typeExpense') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem :label="t('zcard.bill.amount')" required>
          <ElInputNumber v-model="adjustForm.amount" :min="0.01" :precision="2" :placeholder="t('zcard.bill.amountPlaceholder')" style="width: 100%" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.bill.log')" required>
          <ElInput v-model="adjustForm.log" :placeholder="t('zcard.bill.logPlaceholder')" />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="adjustVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="adjustSaving" @click="handleAdjust">{{ t('zcard.common.ok') }}</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<style lang="scss" scoped>
  .bill-page { display: flex; flex-direction: column; gap: 16px; }
  .stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
  .stat-card { display: flex; align-items: center; gap: 12px; padding: 16px; background: var(--el-bg-color); border: 1px solid var(--el-border-color-lighter); border-radius: 8px; border-left: 3px solid var(--accent); }
  .stat-icon { font-size: 28px; }
  .stat-label { font-size: 12px; color: var(--el-text-color-secondary); }
  .stat-value { margin-top: 4px; font-size: 20px; font-weight: 700; }
  .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
  .toolbar-left { display: flex; gap: 8px; flex-wrap: wrap; }
  .income-text { font-weight: 600; color: var(--el-color-success); }
  .expense-text { font-weight: 600; color: var(--el-color-danger); }
  .text-muted { color: var(--el-text-color-placeholder); }
  .pagination-wrap { display: flex; justify-content: flex-end; margin-top: 16px; }
</style>
