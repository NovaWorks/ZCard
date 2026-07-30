<!-- 卡密列表 - 后台管理（重构版：统计 + 筛选 + 批量 + 导入/导出） -->
<template>
  <div class="card-page art-full-height">
    <!-- 统计卡片 -->
    <div class="stat-row">
      <div class="stat-card stat-total">
        <div class="stat-label">{{ t('zcard.card.statsTotal') }}</div>
        <div class="stat-value">{{ stats.total }}</div>
      </div>
      <div class="stat-card stat-unused">
        <div class="stat-label">{{ t('zcard.card.statsUnused') }}</div>
        <div class="stat-value">{{ stats.unused }}</div>
      </div>
      <div class="stat-card stat-used">
        <div class="stat-label">{{ t('zcard.card.statsUsed') }}</div>
        <div class="stat-value">{{ stats.used }}</div>
      </div>
      <div class="stat-card stat-disabled">
        <div class="stat-label">{{ t('zcard.card.statsDisabled') }}</div>
        <div class="stat-value">{{ stats.disabled }}</div>
      </div>
    </div>

    <ElCard class="art-table-card" shadow="never">
      <ElTabs v-model="activeTab" class="page-tabs">
        <ElTabPane :label="t('zcard.card.list')" name="cards">
          <!-- 搜索栏 -->
          <div class="search-bar">
            <ElForm :inline="true" :model="searchForm" @submit.prevent>
              <ElFormItem :label="t('zcard.card.product')">
                <ElSelect
                  v-model="searchForm.product_id"
                  :placeholder="t('zcard.card.allProducts')"
                  clearable
                  filterable
                  style="width: 220px"
                >
                  <ElOption
                    v-for="p in products"
                    :key="p.id"
                    :label="p.name"
                    :value="p.id"
                  />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="t('zcard.card.status')">
                <ElSelect
                  v-model="searchForm.status"
                  :placeholder="t('zcard.card.allStatus')"
                  clearable
                  style="width: 140px"
                >
                  <ElOption :label="t('zcard.card.statusUnused')" value="unused" />
                  <ElOption :label="t('zcard.card.statusLocked')" value="locked" />
                  <ElOption :label="t('zcard.card.statusUsed')" value="used" />
                  <ElOption :label="t('zcard.card.statusDisabled')" value="disabled" />
                </ElSelect>
              </ElFormItem>
              <ElFormItem :label="t('zcard.card.cardType')">
                <ElInput
                  v-model="searchForm.card_type"
                  clearable
                  style="width: 150px"
                />
              </ElFormItem>
              <ElFormItem :label="t('zcard.card.note')">
                <ElInput
                  v-model="searchForm.note"
                  clearable
                  style="width: 150px"
                />
              </ElFormItem>
              <ElFormItem :label="t('zcard.card.dateRange')">
                <ElDatePicker
                  v-model="dateRange"
                  type="daterange"
                  value-format="YYYY-MM-DD"
                  :start-placeholder="t('zcard.card.dateRange')"
                  :end-placeholder="t('zcard.card.dateRange')"
                  style="width: 260px"
                />
              </ElFormItem>
              <ElFormItem>
                <ElButton type="primary" @click="handleSearch">{{ t('zcard.common.search') }}</ElButton>
                <ElButton @click="handleReset">{{ t('zcard.common.reset') }}</ElButton>
              </ElFormItem>
            </ElForm>
          </div>

          <!-- 操作栏 -->
          <div class="table-header">
            <ElButton type="primary" @click="openImport">{{ t('zcard.card.import') }}</ElButton>
            <ElButton :loading="exporting" @click="handleExport">
              {{ t('zcard.card.exportFiltered') }}
            </ElButton>
            <ElButton
              type="warning"
              :disabled="selectedIds.length === 0"
              @click="handleBatchDisable"
            >
              {{ t('zcard.card.batchDisable') }}
              <span v-if="selectedIds.length">({{ selectedIds.length }})</span>
            </ElButton>
            <ElButton
              type="danger"
              :disabled="selectedIds.length === 0"
              @click="handleBatchDelete"
            >
              {{ t('zcard.card.batchDelete') }}
              <span v-if="selectedIds.length">({{ selectedIds.length }})</span>
            </ElButton>
          </div>

          <!-- 表格 -->
          <ElTable
            v-loading="loading"
            :data="tableData"
            border
            stripe
            style="width: 100%"
            @selection-change="handleSelectionChange"
          >
            <ElTableColumn type="selection" width="45" />
            <ElTableColumn prop="id" :label="t('zcard.common.id')" width="80" />
            <ElTableColumn :label="t('zcard.card.product')" min-width="180" show-overflow-tooltip>
              <template #default="{ row }">
                {{ row.product?.name || `#${row.product_id}` }}
              </template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.card.cardType')" width="100" align="center">
              <template #default="{ row }">
                <ElTag v-if="row.card_type" type="info" effect="plain" size="small">
                  {{ row.card_type }}
                </ElTag>
                <span v-else class="text-muted">-</span>
              </template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.card.status')" width="100" align="center">
              <template #default="{ row }">
                <ElTag :type="statusTagType(row.status)" effect="light">
                  {{ statusLabel(row.status) }}
                </ElTag>
              </template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.card.premiumCost')" width="130" align="center">
              <template #default="{ row }">
                <span v-if="(row.draft_premium ?? 0) > 0 || (row.draft_cost ?? 0) > 0" class="premium-cell">
                  ¥{{ formatMoney(row.draft_premium) }} / ¥{{ formatMoney(row.draft_cost) }}
                </span>
                <span v-else class="text-muted">-</span>
              </template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.card.relatedOrder')" width="140" align="center">
              <template #default="{ row }">
                <span v-if="row.order?.order_no" class="order-link" @click="copyText(row.order.order_no)">
                  {{ row.order.order_no }}
                </span>
                <span v-else-if="row.order_id" class="text-muted">#{{ row.order_id }}</span>
                <span v-else class="text-muted">-</span>
              </template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.card.note')" min-width="160" show-overflow-tooltip>
              <template #default="{ row }">
                <span v-if="row.note">{{ row.note }}</span>
                <span v-else class="text-muted">-</span>
              </template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.card.source')" width="120" align="center">
              <template #default="{ row }">{{ row.import?.source || row.source || '-' }}</template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.card.createTime') + ' / ' + t('zcard.card.sellTime')" width="200" align="center">
              <template #default="{ row }">
                <div class="time-cell">
                  <div>{{ formatTime(row.created_at) }}</div>
                  <div v-if="row.used_at" class="time-sell">{{ formatTime(row.used_at) }}</div>
                </div>
              </template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.common.actions')" width="200" fixed="right" align="center">
              <template #default="{ row }">
                <ElButton link type="primary" @click="openEdit(row)">
                  {{ t('zcard.card.editCard') }}
                </ElButton>
                <ElButton
                  v-if="row.status === 'unused'"
                  link
                  type="warning"
                  @click="handleDisable(row)"
                >
                  {{ t('zcard.card.disable') }}
                </ElButton>
                <ElButton
                  v-if="row.status === 'unused' || row.status === 'disabled'"
                  link
                  type="danger"
                  @click="handleDelete(row)"
                >
                  {{ t('zcard.card.delete') }}
                </ElButton>
              </template>
            </ElTableColumn>
          </ElTable>

          <!-- 分页 -->
          <div class="pagination-bar">
            <ElPagination
              v-model:current-page="pagination.page"
              v-model:page-size="pagination.pageSize"
              :total="pagination.total"
              :page-sizes="[10, 15, 20, 50]"
              layout="total, sizes, prev, pager, next, jumper"
              background
              @size-change="fetchData"
              @current-change="fetchData"
            />
          </div>
        </ElTabPane>

        <ElTabPane :label="t('zcard.card.importBatch')" name="batches">
          <ElTable
            v-loading="batchLoading"
            :data="batches"
            border
            stripe
            style="width: 100%"
            :empty-text="t('zcard.card.noBatch')"
          >
            <ElTableColumn prop="id" :label="t('zcard.card.batchId')" width="90" />
            <ElTableColumn :label="t('zcard.card.product')" min-width="180" show-overflow-tooltip>
              <template #default="{ row }">
                {{ row.product?.name || `#${row.product_id}` }}
              </template>
            </ElTableColumn>
            <ElTableColumn prop="total" :label="t('zcard.card.batchTotal')" width="90" align="center" />
            <ElTableColumn prop="success_count" :label="t('zcard.card.batchSuccess')" width="90" align="center">
              <template #default="{ row }">
                <span class="text-success">{{ row.success_count }}</span>
              </template>
            </ElTableColumn>
            <ElTableColumn prop="fail_count" :label="t('zcard.card.batchFailed')" width="90" align="center">
              <template #default="{ row }">
                <span :class="row.fail_count ? 'text-danger' : ''">{{ row.fail_count }}</span>
              </template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.card.source')" width="120" align="center">
              <template #default="{ row }">{{ row.source || '-' }}</template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.card.importTime')" width="170" align="center">
              <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
            </ElTableColumn>
          </ElTable>
        </ElTabPane>
      </ElTabs>
    </ElCard>

    <!-- 导入卡密弹窗 -->
    <ElDialog v-model="importVisible" :title="t('zcard.card.import')" width="600px" destroy-on-close>
      <ElForm ref="importFormRef" :model="importForm" :rules="importRules" label-width="90px">
        <ElFormItem :label="t('zcard.card.product')" prop="product_id">
          <ElSelect
            v-model="importForm.product_id"
            :placeholder="t('zcard.card.productRequired')"
            filterable
            style="width: 100%"
          >
            <ElOption
              v-for="p in products"
              :key="p.id"
              :label="p.name"
              :value="p.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="t('zcard.card.importCardType')" prop="card_type">
          <ElInput
            v-model="importForm.card_type"
            :placeholder="t('zcard.card.cardTypePlaceholder')"
          />
        </ElFormItem>
        <ElFormItem :label="t('zcard.card.importNote')" prop="note">
          <ElInput
            v-model="importForm.note"
            :placeholder="t('zcard.card.notePlaceholder')"
          />
        </ElFormItem>
        <ElFormItem :label="t('zcard.card.content')" prop="contents">
          <ElInput
            v-model="importForm.contents"
            type="textarea"
            :rows="10"
            :placeholder="t('zcard.card.importPlaceholder')"
          />
          <div class="form-help">{{ t('zcard.card.lineCount', { n: importLineCount }) }}</div>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="importVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="importing" @click="handleImport">
          {{ t('zcard.card.startImport') }}
        </ElButton>
      </template>
    </ElDialog>

    <!-- 编辑卡密抽屉 -->
    <ElDrawer v-model="editDrawerVisible" :title="t('zcard.card.editCard')" size="450px" direction="rtl">
      <div v-loading="editLoading" class="px-2">
        <!-- 只读信息区 -->
        <ElDescriptions :column="1" border size="small" class="mb-4">
          <ElDescriptionsItem :label="t('zcard.common.id')">{{ editData.id }}</ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.card.status')">
            <ElTag :type="statusTagType(editData.status as CardStatus)" size="small">{{ statusLabel(editData.status as CardStatus) }}</ElTag>
          </ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.product.title')">{{ editData.product_name || '-' }}</ElDescriptionsItem>
          <ElDescriptionsItem v-if="editData.order_no" :label="t('zcard.card.relatedOrder')">
            {{ editData.order_no }}
          </ElDescriptionsItem>
          <ElDescriptionsItem :label="t('zcard.card.createTime')">{{ editData.created_at }}</ElDescriptionsItem>
          <ElDescriptionsItem v-if="editData.used_at" :label="t('zcard.card.sellTime')">{{ editData.used_at }}</ElDescriptionsItem>
        </ElDescriptions>

        <!-- 卡密明文(只读+复制) -->
        <ElFormItem :label="t('zcard.card.cardContent')">
          <div class="w-full">
            <pre class="content-readonly">{{ editData.content }}</pre>
            <ElButton type="primary" link size="small" class="mt-1" @click="copyText(editData.content)">
              {{ t('zcard.common.confirm') === '确定' ? '复制卡密' : 'Copy Card' }}
            </ElButton>
          </div>
        </ElFormItem>

        <ElDivider />

        <!-- 可编辑字段 -->
        <ElForm :model="editData" label-width="100px">
          <ElFormItem :label="t('zcard.card.cardType')">
            <ElTag v-if="editData.card_type" type="info" effect="plain" size="small">{{ editData.card_type }}</ElTag>
            <span v-else class="text-gray-400 text-sm">-</span>
          </ElFormItem>
          <ElFormItem :label="t('zcard.card.note')">
            <ElInput v-model="editData.note" type="textarea" :rows="2" :placeholder="t('zcard.card.notePlaceholder')" />
          </ElFormItem>
          <ElFormItem :label="t('zcard.card.draftPremium')">
            <ElInputNumber v-model="editData.draft_premium" :min="0" :precision="2" :step="0.5" style="width: 180px" />
          </ElFormItem>
          <ElFormItem :label="t('zcard.card.draftCost')">
            <ElInputNumber v-model="editData.draft_cost" :min="0" :precision="2" :step="0.5" style="width: 180px" />
          </ElFormItem>
        </ElForm>
      </div>

      <template #footer>
        <div style="display: flex; justify-content: space-between; width: 100%">
          <ElButton type="danger" :icon="Delete" @click="handleDeleteFromEdit">
            {{ t('zcard.card.deleteCard') }}
          </ElButton>
          <div>
            <ElButton @click="editDrawerVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
            <ElButton type="primary" :loading="editSubmitting" @click="handleSaveEdit">
              {{ t('zcard.common.save') }}
            </ElButton>
          </div>
        </div>
      </template>
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import type { FormInstance, FormRules } from 'element-plus'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { Lock, Delete } from '@element-plus/icons-vue'
  import { useI18n } from 'vue-i18n'
  import {
    getCards,
    getCardStats,
    importCards,
    disableCards,
    deleteCards,
    exportCards,
    revealCard,
    updateCard,
    getImportBatches,
    type Card,
    type CardStatus,
    type CardStats,
    type ImportBatch
  } from '@/api/cards'
  import { getProducts, type Product } from '@/api/products'

  defineOptions({ name: 'CardList' })

  const { t } = useI18n()

  /** 时间格式化 */
  const formatTime = (iso: string | null | undefined): string => {
    if (!iso) return '-'
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return iso
    const pad = (n: number) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(
      d.getHours()
    )}:${pad(d.getMinutes())}`
  }

  /** 金额格式化（后端 decimal 直接是元，无需 /100） */
  const formatMoney = (v: number | null | undefined): string => {
    const n = Number(v ?? 0)
    return Number.isFinite(n) ? n.toFixed(2) : '0.00'
  }

  /** 截断 hash 前后各 8 位 */
  const hashPreview = (hash: string | undefined): string => {
    if (!hash) return '-'
    if (hash.length <= 20) return hash
    return `${hash.slice(0, 10)}…${hash.slice(-8)}`
  }

  /** 复制文本到剪贴板 */
  const copyText = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text)
      ElMessage.success(t('zcard.common.confirm') === '确定' ? '已复制' : 'Copied')
    } catch {
      ElMessage.warning(text)
    }
  }

  const statusLabel = (s: CardStatus): string => {
    const map: Record<CardStatus, string> = {
      unused: t('zcard.card.statusUnused'),
      locked: t('zcard.card.statusLocked'),
      used: t('zcard.card.statusUsed'),
      disabled: t('zcard.card.statusDisabled')
    }
    return map[s] || s
  }

  const statusTagType = (s: CardStatus): 'success' | 'warning' | 'info' | 'danger' => {
    const map: Record<CardStatus, 'success' | 'warning' | 'info' | 'danger'> = {
      unused: 'success',
      locked: 'warning',
      used: 'info',
      disabled: 'danger'
    }
    return map[s] || 'info'
  }

  /** 商品列表 */
  const products = ref<Product[]>([])
  const loadProducts = async () => {
    try {
      const res = await getProducts({ pageSize: 200 })
      products.value = res.data || []
    } catch {
      products.value = []
    }
  }

  /** 顶部统计 */
  const stats = reactive<CardStats>({ total: 0, unused: 0, locked: 0, used: 0, disabled: 0 })
  const fetchStats = async () => {
    try {
      const res = await getCardStats({ product_id: searchForm.product_id })
      Object.assign(stats, res)
    } catch {
      // 静默失败
    }
  }

  /** 当前标签页 */
  const activeTab = ref<'cards' | 'batches'>('cards')

  /** 卡密列表状态 */
  const loading = ref(false)
  const tableData = ref<Card[]>([])
  const pagination = reactive({ page: 1, pageSize: 15, total: 0 })

  const searchForm = reactive<{
    product_id?: number
    status?: CardStatus
    card_type?: string
    note?: string
  }>({ product_id: undefined, status: undefined, card_type: undefined, note: undefined })

  /** 日期范围（[from, to]） */
  const dateRange = ref<[string, string] | null>(null)

  /** 选择行 */
  const selection = ref<Card[]>([])
  const selectedIds = computed(() => selection.value.map((c) => c.id))
  const handleSelectionChange = (rows: Card[]) => {
    selection.value = rows
  }

  /** 组装列表查询参数（搜索 + 分页） */
  const buildParams = () => ({
    page: pagination.page,
    pageSize: pagination.pageSize,
    product_id: searchForm.product_id,
    status: searchForm.status,
    card_type: searchForm.card_type || undefined,
    note: searchForm.note || undefined,
    date_from: dateRange.value?.[0] || undefined,
    date_to: dateRange.value?.[1] || undefined
  })

  const fetchData = async () => {
    loading.value = true
    try {
      const res = await getCards(buildParams())
      tableData.value = res.data || []
      pagination.total = res.total || 0
    } catch {
      tableData.value = []
      pagination.total = 0
    } finally {
      loading.value = false
    }
  }

  const handleSearch = () => {
    pagination.page = 1
    fetchData()
    fetchStats()
  }

  const handleReset = () => {
    searchForm.product_id = undefined
    searchForm.status = undefined
    searchForm.card_type = undefined
    searchForm.note = undefined
    dateRange.value = null
    pagination.page = 1
    fetchData()
    fetchStats()
  }

  /** 导入批次 */
  const batchLoading = ref(false)
  const batches = ref<ImportBatch[]>([])

  const fetchBatches = async () => {
    batchLoading.value = true
    try {
      const res = await getImportBatches({ pageSize: 50 })
      // 后端返回 paginate 结构（带 data 字段）
      const data = (res as any)?.data
      batches.value = Array.isArray(data) ? data : Array.isArray(res) ? (res as ImportBatch[]) : []
    } catch {
      batches.value = []
    } finally {
      batchLoading.value = false
    }
  }

  watch(activeTab, (val) => {
    if (val === 'batches' && batches.value.length === 0) fetchBatches()
  })

  /** 禁用单条卡密 */
  const handleDisable = (row: Card) => {
    ElMessageBox.confirm(t('zcard.card.disableOneConfirm', { id: row.id }), t('zcard.card.disableTitle'), {
      confirmButtonText: t('zcard.common.ok'),
      cancelButtonText: t('zcard.common.cancel'),
      type: 'warning'
    })
      .then(async () => {
        try {
          await disableCards([row.id])
          ElMessage.success(t('zcard.card.disabled'))
          fetchData()
          fetchStats()
        } catch {
          // 拦截器处理
        }
      })
      .catch(() => {
        // 取消
      })
  }

  /** 批量禁用 */
  const handleBatchDisable = () => {
    if (selectedIds.value.length === 0) {
      ElMessage.warning(t('zcard.card.selectFirst'))
      return
    }
    ElMessageBox.confirm(t('zcard.card.disableConfirm'), t('zcard.card.disableTitle'), {
      confirmButtonText: t('zcard.common.ok'),
      cancelButtonText: t('zcard.common.cancel'),
      type: 'warning'
    })
      .then(async () => {
        try {
          const res = await disableCards(selectedIds.value)
          ElMessage.success(t('zcard.card.disabled') + ` (${res.disabled})`)
          fetchData()
          fetchStats()
        } catch {
          // 拦截器处理
        }
      })
      .catch(() => {})
  }

  /** 删除单条 */
  const handleDelete = (row: Card) => {
    ElMessageBox.confirm(t('zcard.card.deleteOneConfirm', { id: row.id }), t('zcard.card.deleteTitle'), {
      confirmButtonText: t('zcard.common.ok'),
      cancelButtonText: t('zcard.common.cancel'),
      type: 'error'
    })
      .then(async () => {
        try {
          await deleteCards([row.id])
          ElMessage.success(t('zcard.card.deleted'))
          fetchData()
          fetchStats()
        } catch {
          // 拦截器处理
        }
      })
      .catch(() => {})
  }

  /** 批量删除 */
  const handleBatchDelete = () => {
    if (selectedIds.value.length === 0) {
      ElMessage.warning(t('zcard.card.selectFirst'))
      return
    }
    ElMessageBox.confirm(t('zcard.card.deleteConfirm'), t('zcard.card.deleteTitle'), {
      confirmButtonText: t('zcard.common.ok'),
      cancelButtonText: t('zcard.common.cancel'),
      type: 'error'
    })
      .then(async () => {
        try {
          const res = await deleteCards(selectedIds.value)
          ElMessage.success(t('zcard.card.deleted') + ` (${res.deleted})`)
          fetchData()
          fetchStats()
        } catch {
          // 拦截器处理
        }
      })
      .catch(() => {})
  }

  /** 导出筛选 */
  const exporting = ref(false)
  const handleExport = async () => {
    exporting.value = true
    try {
      ElMessage.info(t('zcard.card.exportStarted'))
      const { filename, blob } = await exportCards(buildParams())
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = filename
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
      ElMessage.success(t('zcard.card.exportDone'))
    } catch {
      ElMessage.error(t('zcard.card.exportFailed'))
    } finally {
      exporting.value = false
    }
  }

  /** 导入弹窗 */
  const importVisible = ref(false)
  const importing = ref(false)
  const importFormRef = ref<FormInstance>()
  const importForm = reactive({
    product_id: undefined as number | undefined,
    card_type: '',
    note: '',
    contents: ''
  })

  const importRules = computed<FormRules>(() => ({
    product_id: [{ required: true, message: t('zcard.card.productRequired'), trigger: 'change' }],
    contents: [{ required: true, message: t('zcard.card.contentRequired'), trigger: 'blur' }]
  }))

  const importLineCount = computed(() => {
    const text = (importForm.contents || '').trim()
    if (!text) return 0
    return text.split(/\r?\n/).filter((l) => l.trim()).length
  })

  const openImport = () => {
    importForm.product_id = searchForm.product_id
    importForm.card_type = searchForm.card_type || ''
    importForm.note = ''
    importForm.contents = ''
    importVisible.value = true
  }

  const handleImport = async () => {
    if (!importFormRef.value) return
    try {
      await importFormRef.value.validate()
    } catch {
      return
    }

    importing.value = true
    try {
      const res = await importCards({
        product_id: importForm.product_id as number,
        contents: importForm.contents,
        card_type: importForm.card_type || undefined,
        note: importForm.note || undefined
      })
      ElMessage.success(
        t('zcard.card.importResult', {
          success: res.success_count ?? 0,
          failed: res.failed_count ?? res.fail_count ?? 0
        })
      )
      importVisible.value = false
      fetchData()
      fetchStats()
      if (activeTab.value === 'batches' || batches.value.length) fetchBatches()
    } catch {
      // 拦截器处理
    } finally {
      importing.value = false
    }
  }

  /** 编辑卡密抽屉 */
  const editDrawerVisible = ref(false)
  const editLoading = ref(false)
  const editSubmitting = ref(false)
  const editData = ref<{
    id: number; content: string; status: string; note: string; card_type: string
    draft_premium: number; draft_cost: number; product_name: string; order_no: string
    created_at: string; used_at: string
  }>({
    id: 0, content: '', status: '', note: '', card_type: '',
    draft_premium: 0, draft_cost: 0, product_name: '', order_no: '',
    created_at: '', used_at: ''
  })

  const openEdit = async (row: Card) => {
    editDrawerVisible.value = true
    editLoading.value = true
    try {
      const res = await revealCard(row.id)
      editData.value = {
        id: res.id,
        content: res.content,
        status: res.status,
        note: res.note || '',
        card_type: res.card_type || '',
        draft_premium: Number(res.draft_premium) || 0,
        draft_cost: Number(res.draft_cost) || 0,
        product_name: res.product_name || '',
        order_no: res.order_no || '',
        created_at: res.created_at || '',
        used_at: res.used_at || ''
      }
    } catch {
      ElMessage.error(t('zcard.card.revealFailed'))
      editDrawerVisible.value = false
    } finally {
      editLoading.value = false
    }
  }

  const handleSaveEdit = async () => {
    editSubmitting.value = true
    try {
      await updateCard(editData.value.id, {
        note: editData.value.note,
        card_type: editData.value.card_type,
        draft_premium: editData.value.draft_premium,
        draft_cost: editData.value.draft_cost,
        status: editData.value.status
      })
      ElMessage.success(t('zcard.common.saveSuccess'))
      editDrawerVisible.value = false
      fetchData()
    } catch {
      ElMessage.error(t('zcard.common.operationFailed'))
    } finally {
      editSubmitting.value = false
    }
  }

  /** 从编辑抽屉删除当前卡密 */
  const handleDeleteFromEdit = () => {
    ElMessageBox.confirm(
      t('zcard.card.deleteConfirmMsg'),
      t('zcard.card.deleteConfirmTitle'),
      {
        confirmButtonText: t('zcard.card.deleteConfirmBtn'),
        cancelButtonText: t('zcard.common.cancel'),
        type: 'warning',
        confirmButtonClass: 'el-button--danger'
      }
    ).then(async () => {
      try {
        await deleteCards([editData.value.id])
        ElMessage.success(t('zcard.common.deleteSuccess'))
        editDrawerVisible.value = false
        fetchData()
        fetchStats()
      } catch {
        ElMessage.error(t('zcard.common.operationFailed'))
      }
    }).catch(() => {})
  }

  onMounted(() => {
    loadProducts()
    fetchData()
    fetchStats()
  })
</script>

<style lang="scss" scoped>
  .card-page {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  /* 统计卡片 */
  .stat-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
  }

  .stat-card {
    padding: 16px 18px;
    border-radius: 8px;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
    position: relative;
    overflow: hidden;

    &::before {
      content: '';
      position: absolute;
      left: 0;
      top: 0;
      bottom: 0;
      width: 4px;
    }

    .stat-label {
      font-size: 13px;
      color: var(--el-text-color-secondary);
    }

    .stat-value {
      font-size: 26px;
      font-weight: 600;
      margin-top: 6px;
      line-height: 1.2;
    }
  }

  .stat-total::before {
    background: var(--el-color-primary);
  }
  .stat-total .stat-value {
    color: var(--el-color-primary);
  }
  .stat-unused::before {
    background: var(--el-color-success);
  }
  .stat-unused .stat-value {
    color: var(--el-color-success);
  }
  .stat-used::before {
    background: var(--el-color-info);
  }
  .stat-used .stat-value {
    color: var(--el-color-info);
  }
  .stat-disabled::before {
    background: var(--el-color-danger);
  }
  .stat-disabled .stat-value {
    color: var(--el-color-danger);
  }

  @media (max-width: 992px) {
    .stat-row {
      grid-template-columns: repeat(2, 1fr);
    }
  }

  .page-tabs {
    margin-bottom: 8px;
  }

  .search-bar {
    margin-bottom: 16px;
  }

  .table-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 16px;
  }

  .pagination-bar {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }

  .premium-cell {
    font-family: 'JetBrains Mono', Menlo, Consolas, monospace;
    font-size: 12px;
  }

  .order-link {
    color: var(--el-color-primary);
    cursor: pointer;
    font-family: 'JetBrains Mono', Menlo, Consolas, monospace;
    font-size: 12px;

    &:hover {
      text-decoration: underline;
    }
  }

  .time-cell {
    line-height: 1.4;
    font-size: 12px;

    .time-sell {
      color: var(--el-color-success);
    }
  }

  .text-muted {
    color: var(--el-text-color-placeholder);
  }

  .text-success {
    color: var(--el-color-success);
  }

  .text-danger {
    color: var(--el-color-danger);
  }

  .form-help {
    margin-top: 4px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  /* 明文弹窗 */
  .plaintext-box {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .plaintext-row {
    display: flex;
    align-items: center;
    gap: 10px;

    .plaintext-label {
      width: 120px;
      color: var(--el-text-color-secondary);
      font-size: 13px;
      flex-shrink: 0;
    }
  }

  .plaintext-content {
    background: var(--el-fill-color-light);
    border-radius: 8px;
    padding: 12px;
    min-height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;

    .content-text {
      font-family: 'JetBrains Mono', Menlo, Consolas, monospace;
      font-size: 14px;
      color: var(--el-color-primary);
      word-break: break-all;
      white-space: pre-wrap;
      margin: 0;
      width: 100%;
    }
  }

  .content-readonly {
    font-family: 'JetBrains Mono', Menlo, Consolas, monospace;
    font-size: 13px;
    color: var(--el-color-primary);
    background: var(--el-fill-color-light);
    padding: 10px 12px;
    border-radius: 6px;
    word-break: break-all;
    white-space: pre-wrap;
    margin: 0;
    max-height: 200px;
    overflow-y: auto;
  }
</style>
