<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import { getCoupons, getCouponStats, createCoupons, toggleCoupon, deleteCoupon, exportCoupons, type Coupon, type CouponStats } from '@/api/coupons'

defineOptions({ name: 'CouponList' })

const { t } = useI18n()
const loading = ref(false)
const list = ref<Coupon[]>([])
const keyword = ref('')
const statusFilter = ref('')
const typeFilter = ref('')
const dateRange = ref<[string, string] | null>(null)
const pagination = reactive({ page: 1, pageSize: 15, total: 0 })
const stats = ref<CouponStats>({ active_count: 0, used_count: 0, disabled_count: 0, total_count: 0 })

const statCards = computed(() => [
  { label: t('zcard.coupon.statActive'), value: stats.value.active_count, icon: '🎫', color: '#67c23a' },
  { label: t('zcard.coupon.statUsed'), value: stats.value.used_count, icon: '✅', color: '#909399' },
  { label: t('zcard.coupon.statDisabled'), value: stats.value.disabled_count, icon: '🚫', color: '#f56c6c' },
  { label: t('zcard.coupon.statTotal'), value: stats.value.total_count, icon: '📦', color: '#409eff' },
])

const formatAmount = (fen: number) => (Number(fen || 0) / 100).toFixed(2)
const formatTime = (d: string | null) => (d ? String(d).slice(0, 19).replace('T', ' ') : '-')
const statusTagType = (s: string) => ({ active: 'success', used: 'info', disabled: 'danger' }[s] || 'info') as any
const statusLabel = (s: string) => ({ active: t('zcard.coupon.statusActive'), used: t('zcard.coupon.statusUsed'), disabled: t('zcard.coupon.statusDisabled') }[s] || s)
const typeLabel = (ty: string, val: number) => ty === 'fixed' ? `¥${formatAmount(val)}` : `${val}%`
const scopeText = (row: Coupon) => {
  if (row.product_id) return row.product?.name || `商品#${row.product_id}`
  if (row.category_id) return row.category?.name || `分类#${row.category_id}`
  return t('zcard.coupon.scopeAll')
}

const buildParams = () => ({
  page: pagination.page, pageSize: pagination.pageSize,
  keyword: keyword.value || undefined,
  status: statusFilter.value || undefined,
  type: typeFilter.value || undefined,
  start_date: dateRange.value?.[0] || undefined,
  end_date: dateRange.value?.[1] || undefined,
})

const fetchData = async () => {
  loading.value = true
  try {
    const [pageData, statsData] = await Promise.all([getCoupons(buildParams()), getCouponStats()])
    list.value = pageData.data || []
    pagination.total = pageData.total || 0
    stats.value = statsData
  } catch { list.value = [] }
  finally { loading.value = false }
}

const handleSearch = () => { pagination.page = 1; fetchData() }
const resetSearch = () => { keyword.value = ''; statusFilter.value = ''; typeFilter.value = ''; dateRange.value = null; pagination.page = 1; fetchData() }

const copyText = async (text: string) => {
  try { await navigator.clipboard.writeText(text); ElMessage.success(t('zcard.coupon.copied')) }
  catch { ElMessage.warning(text) }
}

/** 导出 CSV */
const exporting = ref(false)
const handleExport = async () => {
  exporting.value = true
  try {
    ElMessage.info(t('zcard.coupon.exportStarted'))
    const { filename, blob } = await exportCoupons(buildParams())
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
    ElMessage.success(t('zcard.coupon.exportDone'))
  } catch (e: any) {
    const msg = e?.message || t('zcard.coupon.exportFailed')
    ElMessage.error(msg)
  } finally {
    exporting.value = false
  }
}

// 批量生成
const genVisible = ref(false)
const genSaving = ref(false)
const genForm = ref({ count: 10, type: 'fixed', value: 1000, product_id: null as number | null, category_id: null as number | null, min_amount: 0, expires_at: '', note: '' })
const genResult = ref<string[]>([])

const openGen = () => { genForm.value = { count: 10, type: 'fixed', value: 1000, product_id: null, category_id: null, min_amount: 0, expires_at: '', note: '' }; genResult.value = []; genVisible.value = true }

const handleGen = async () => {
  if (genForm.value.value <= 0) { ElMessage.warning(t('zcard.coupon.valueRequired')); return }
  genSaving.value = true
  try {
    const data: any = {
      count: genForm.value.count,
      type: genForm.value.type,
      value: genForm.value.value,
      product_id: genForm.value.product_id || undefined,
      category_id: genForm.value.category_id || undefined,
      min_amount: genForm.value.min_amount || undefined,
      note: genForm.value.note || undefined,
    }
    if (genForm.value.expires_at) data.expires_at = genForm.value.expires_at
    const res = await createCoupons(data)
    genResult.value = res.codes || []
    ElMessage.success(t('zcard.coupon.genSuccess', { count: res.count }))
    fetchData()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || t('zcard.coupon.genFailed'))
  } finally { genSaving.value = false }
}

const handleToggle = async (row: Coupon) => {
  try { await toggleCoupon(row.id); ElMessage.success(t('zcard.coupon.toggled')); fetchData() }
  catch (e: any) { ElMessage.error(e?.response?.data?.message || 'Failed') }
}

const handleDelete = (row: Coupon) => {
  ElMessageBox.confirm(t('zcard.coupon.deleteConfirm', { code: row.code }), t('zcard.common.tips'), { type: 'warning' })
    .then(async () => {
      try { await deleteCoupon(row.id); ElMessage.success(t('zcard.common.deleteSuccess')); fetchData() }
      catch (e: any) { ElMessage.error(e?.response?.data?.message || 'Failed') }
    }).catch(() => {})
}

onMounted(fetchData)
</script>

<template>
  <div class="coupon-page art-full-height">
    <div class="stats-grid">
      <div v-for="card in statCards" :key="card.label" class="stat-card" :style="{ '--accent': card.color }">
        <div class="stat-icon">{{ card.icon }}</div>
        <div class="stat-body">
          <div class="stat-label">{{ card.label }}</div>
          <div class="stat-value">{{ card.value }}</div>
        </div>
      </div>
    </div>

    <ElCard class="art-table-card" shadow="never">
      <div class="toolbar">
        <div class="toolbar-left">
          <ElInput v-model="keyword" :placeholder="t('zcard.coupon.searchPlaceholder')" clearable style="width: 200px" @keyup.enter="handleSearch" @clear="resetSearch" />
          <ElSelect v-model="statusFilter" :placeholder="t('zcard.coupon.status')" style="width: 120px" @change="handleSearch">
            <ElOption :label="t('zcard.order.allStatus')" value="" />
            <ElOption :label="t('zcard.coupon.statusActive')" value="active" />
            <ElOption :label="t('zcard.coupon.statusUsed')" value="used" />
            <ElOption :label="t('zcard.coupon.statusDisabled')" value="disabled" />
          </ElSelect>
          <ElSelect v-model="typeFilter" :placeholder="t('zcard.coupon.type')" style="width: 120px" @change="handleSearch">
            <ElOption :label="t('zcard.order.allStatus')" value="" />
            <ElOption :label="t('zcard.coupon.typeFixed')" value="fixed" />
            <ElOption :label="t('zcard.coupon.typePercent')" value="percent" />
          </ElSelect>
          <ElButton type="primary" @click="handleSearch">{{ t('zcard.common.search') }}</ElButton>
          <ElButton @click="resetSearch">{{ t('zcard.common.reset') }}</ElButton>
        </div>
        <div class="toolbar-right">
          <ElButton :loading="exporting" @click="handleExport">📥 {{ t('zcard.coupon.exportFiltered') }}</ElButton>
          <ElButton type="primary" @click="openGen">🎫 {{ t('zcard.coupon.generate') }}</ElButton>
        </div>
      </div>

      <ElTable :data="list" v-loading="loading" border stripe>
        <ElTableColumn :label="t('zcard.coupon.code')" min-width="140">
          <template #default="{ row }">
            <div class="code-cell">
              <span class="code-text">{{ row.code }}</span>
              <ElButton text size="small" @click="copyText(row.code)">📋</ElButton>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.coupon.type')" width="100" align="center">
          <template #default="{ row }">
            <ElTag :type="row.type === 'fixed' ? 'warning' : 'primary'" size="small">{{ typeLabel(row.type, row.value) }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.coupon.scope')" min-width="130" show-overflow-tooltip>
          <template #default="{ row }">{{ scopeText(row) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.coupon.minAmount')" width="100" align="right">
          <template #default="{ row }">{{ row.min_amount > 0 ? '¥' + formatAmount(row.min_amount) : '-' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.coupon.status')" width="90" align="center">
          <template #default="{ row }"><ElTag :type="statusTagType(row.status)" size="small">{{ statusLabel(row.status) }}</ElTag></template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.coupon.expiresAt')" width="160" align="center">
          <template #default="{ row }">{{ formatTime(row.expires_at) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.coupon.usedInfo')" width="160" align="center">
          <template #default="{ row }">
            <span v-if="row.status === 'used'" class="text-xs">{{ formatTime(row.used_at) }}</span>
            <span v-else class="text-muted">-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.common.actions')" width="140" align="center" fixed="right">
          <template #default="{ row }">
            <ElButton v-if="row.status !== 'used'" text type="primary" size="small" @click="handleToggle(row)">
              {{ row.status === 'active' ? t('zcard.coupon.disable') : t('zcard.coupon.enable') }}
            </ElButton>
            <ElButton v-if="row.status !== 'used'" text type="danger" size="small" @click="handleDelete(row)">{{ t('zcard.common.delete') }}</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <div class="pagination-wrap">
        <ElPagination v-model:current-page="pagination.page" v-model:page-size="pagination.pageSize"
          :total="pagination.total" :page-sizes="[15, 30, 50, 100]"
          layout="total, sizes, prev, pager, next" @size-change="fetchData" @current-change="fetchData" />
      </div>
    </ElCard>

    <!-- 批量生成弹窗 -->
    <ElDialog v-model="genVisible" :title="t('zcard.coupon.generate')" width="560px" destroy-on-close>
      <ElForm :model="genForm" label-width="100px">
        <ElFormItem :label="t('zcard.coupon.genCount')" required>
          <ElInputNumber v-model="genForm.count" :min="1" :max="1000" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.coupon.type')" required>
          <ElRadioGroup v-model="genForm.type">
            <ElRadio value="fixed">{{ t('zcard.coupon.typeFixed') }}</ElRadio>
            <ElRadio value="percent">{{ t('zcard.coupon.typePercent') }}</ElRadio>
          </ElRadioGroup>
        </ElFormItem>
        <ElFormItem :label="t('zcard.coupon.valueLabel')" required>
          <ElInputNumber v-model="genForm.value" :min="1" />
          <span class="form-tip">{{ genForm.type === 'fixed' ? t('zcard.coupon.valueFixedTip') : t('zcard.coupon.valuePercentTip') }}</span>
        </ElFormItem>
        <ElFormItem :label="t('zcard.coupon.minAmountLabel')">
          <ElInputNumber v-model="genForm.min_amount" :min="0" :precision="2" />
          <span class="form-tip">{{ t('zcard.coupon.minAmountTip') }}</span>
        </ElFormItem>
        <ElFormItem :label="t('zcard.coupon.expiresAt')">
          <ElDatePicker v-model="genForm.expires_at" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" style="width: 200px" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.coupon.note')">
          <ElInput v-model="genForm.note" :placeholder="t('zcard.coupon.notePlaceholder')" />
        </ElFormItem>
        <!-- 生成结果 -->
        <div v-if="genResult.length" class="gen-result">
          <div class="gen-result-title">{{ t('zcard.coupon.genResult', { count: genResult.length }) }}</div>
          <div class="gen-codes">
            <code v-for="c in genResult" :key="c" class="gen-code" @click="copyText(c)">{{ c }}</code>
          </div>
          <ElButton size="small" @click="copyText(genResult.join('\n'))">{{ t('zcard.coupon.copyAll') }}</ElButton>
        </div>
      </ElForm>
      <template #footer>
        <ElButton @click="genVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="genSaving" @click="handleGen">{{ t('zcard.coupon.generate') }}</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<style lang="scss" scoped>
  .coupon-page { display: flex; flex-direction: column; gap: 16px; }
  .stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
  .stat-card { display: flex; align-items: center; gap: 12px; padding: 16px; background: var(--el-bg-color); border: 1px solid var(--el-border-color-lighter); border-radius: 8px; border-left: 3px solid var(--accent); }
  .stat-icon { font-size: 28px; }
  .stat-label { font-size: 12px; color: var(--el-text-color-secondary); }
  .stat-value { margin-top: 4px; font-size: 20px; font-weight: 700; }
  .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
  .toolbar-left { display: flex; gap: 8px; flex-wrap: wrap; }
  .code-cell { display: flex; align-items: center; gap: 4px; }
  .code-text { font-family: monospace; font-size: 12px; font-weight: 600; }
  .text-muted { color: var(--el-text-color-placeholder); }
  .pagination-wrap { display: flex; justify-content: flex-end; margin-top: 16px; }
  .form-tip { margin-left: 8px; font-size: 12px; color: var(--el-text-color-secondary); }
  .gen-result { margin-top: 12px; padding: 12px; background: var(--el-fill-color-light); border-radius: 8px; }
  .gen-result-title { font-size: 13px; font-weight: 600; margin-bottom: 8px; }
  .gen-codes { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; max-height: 120px; overflow-y: auto; }
  .gen-code { font-size: 12px; padding: 2px 8px; background: var(--el-bg-color); border: 1px solid var(--el-border-color-lighter); border-radius: 4px; cursor: pointer; }
  .gen-code:hover { border-color: var(--el-color-primary); color: var(--el-color-primary); }
</style>
