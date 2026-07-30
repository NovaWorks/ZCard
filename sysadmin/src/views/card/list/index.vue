<!-- 卡密列表 - 后台管理 -->
<template>
  <div class="card-page art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <!-- 标签页：卡密 / 导入批次 -->
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
              <ElFormItem>
                <ElButton type="primary" @click="handleSearch">{{ t('zcard.common.search') }}</ElButton>
                <ElButton @click="handleReset">{{ t('zcard.common.reset') }}</ElButton>
              </ElFormItem>
            </ElForm>
          </div>

          <!-- 操作栏 -->
          <div class="table-header">
            <ElButton type="primary" @click="openImport">{{ t('zcard.card.import') }}</ElButton>
          </div>

          <!-- 表格 -->
          <ElTable v-loading="loading" :data="tableData" border stripe style="width: 100%">
            <ElTableColumn prop="id" :label="t('zcard.common.id')" width="80" />
            <ElTableColumn :label="t('zcard.card.product')" min-width="180" show-overflow-tooltip>
              <template #default="{ row }">
                {{ row.product?.name || `#${row.product_id}` }}
              </template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.card.content')" min-width="240" show-overflow-tooltip>
              <template #default="{ row }">
                <span class="card-content">{{ maskContent(row.content) }}</span>
              </template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.card.status')" width="100" align="center">
              <template #default="{ row }">
                <ElTag :type="statusTagType(row.status)" effect="light">
                  {{ statusLabel(row.status) }}
                </ElTag>
              </template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.card.source')" width="120" align="center">
              <template #default="{ row }">{{ row.source || '-' }}</template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.card.importTime')" width="170" align="center">
              <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.common.actions')" width="120" fixed="right" align="center">
              <template #default="{ row }">
                <ElButton
                  v-if="row.status === 'unused'"
                  type="danger"
                  link
                  @click="handleDisable(row)"
                >
                  {{ t('zcard.card.disable') }}
                </ElButton>
                <span v-else class="text-muted">-</span>
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
        <ElButton type="primary" :loading="importing" @click="handleImport">{{ t('zcard.card.startImport') }}</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import type { FormInstance, FormRules } from 'element-plus'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import {
    getCards,
    importCards,
    disableCards,
    getImportBatches,
    type Card,
    type CardStatus,
    type ImportBatch
  } from '@/api/cards'
  import { getProducts, type Product } from '@/api/products'

  defineOptions({ name: 'CardList' })

  const { t } = useI18n()

  /** 时间格式化 */
  const formatTime = (iso: string | null): string => {
    if (!iso) return '-'
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return iso
    const pad = (n: number) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(
      d.getHours()
    )}:${pad(d.getMinutes())}`
  }

  /** 卡密内容脱敏（列表只展示前 4 / 后 2） */
  const maskContent = (content: string): string => {
    if (!content) return '-'
    if (content.length <= 8) return content
    return `${content.slice(0, 4)}****${content.slice(-2)}`
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

  const statusTagType = (
    s: CardStatus
  ): 'success' | 'warning' | 'info' | 'danger' => {
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
    } catch (e) {
      products.value = []
    }
  }

  /** 当前标签页 */
  const activeTab = ref<'cards' | 'batches'>('cards')

  /** 卡密列表状态 */
  const loading = ref(false)
  const tableData = ref<Card[]>([])
  const pagination = reactive({
    page: 1,
    pageSize: 15,
    total: 0
  })

  const searchForm = reactive<{ product_id?: number; status?: CardStatus }>({
    product_id: undefined,
    status: undefined
  })

  const fetchData = async () => {
    loading.value = true
    try {
      const res = await getCards({
        page: pagination.page,
        pageSize: pagination.pageSize,
        product_id: searchForm.product_id,
        status: searchForm.status
      })
      tableData.value = res.data || []
      pagination.total = res.total || 0
    } catch (e) {
      tableData.value = []
      pagination.total = 0
    } finally {
      loading.value = false
    }
  }

  const handleSearch = () => {
    pagination.page = 1
    fetchData()
  }

  const handleReset = () => {
    searchForm.product_id = undefined
    searchForm.status = undefined
    pagination.page = 1
    fetchData()
  }

  /** 导入批次 */
  const batchLoading = ref(false)
  const batches = ref<ImportBatch[]>([])

  const fetchBatches = async () => {
    batchLoading.value = true
    try {
      const res = await getImportBatches()
      batches.value = Array.isArray(res) ? res : []
    } catch (e) {
      batches.value = []
    } finally {
      batchLoading.value = false
    }
  }

  watch(activeTab, (val) => {
    if (val === 'batches' && batches.value.length === 0) fetchBatches()
  })

  /** 禁用卡密 */
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
        } catch (e) {
          // 拦截器处理
        }
      })
      .catch(() => {
        // 取消
      })
  }

  /** 导入弹窗 */
  const importVisible = ref(false)
  const importing = ref(false)
  const importFormRef = ref<FormInstance>()
  const importForm = reactive({
    product_id: undefined as number | undefined,
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
        contents: importForm.contents
      })
      ElMessage.success(
        t('zcard.card.importResult', { success: res.success_count ?? 0, failed: res.fail_count ?? 0 })
      )
      importVisible.value = false
      fetchData()
      if (activeTab.value === 'batches' || batches.value.length) fetchBatches()
    } catch (e) {
      // 拦截器处理
    } finally {
      importing.value = false
    }
  }

  onMounted(() => {
    loadProducts()
    fetchData()
  })
</script>

<style lang="scss" scoped>
  .card-page {
    display: flex;
    flex-direction: column;
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
    margin-bottom: 16px;
  }

  .pagination-bar {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }

  .card-content {
    font-family: 'JetBrains Mono', Menlo, Consolas, monospace;
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
</style>
