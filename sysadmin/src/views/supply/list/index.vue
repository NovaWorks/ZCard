<!-- 货源管理 - 对接上游供货系统(dujiao-next / acg-faka / ZCard) -->
<template>
  <div class="supply-page art-full-height">
    <ElCard ref="cardRef" class="art-table-card" shadow="never">
      <div class="toolbar">
        <div class="toolbar-left">
          <ElSelect v-model="filterStatus" :placeholder="t('zcard.supply.filterStatus')" clearable style="width: 160px" @change="fetchData">
            <ElOption :label="t('zcard.supply.statusActive')" value="active" />
            <ElOption :label="t('zcard.supply.statusDisabled')" value="disabled" />
          </ElSelect>
          <ElButton @click="fetchData">{{ t('zcard.common.reset') }}</ElButton>
        </div>
        <div class="toolbar-right">
          <ElButton type="primary" :icon="Plus" @click="openAdd">{{ t('zcard.supply.add') }}</ElButton>
        </div>
      </div>

      <ElTable ref="tableRef" v-loading="loading" :data="tableData" :height="tableHeight" row-key="id" border stripe>
        <ElTableColumn :label="t('zcard.common.id')" prop="id" width="60" />
        <ElTableColumn :label="t('zcard.supply.name')" prop="name" min-width="120" show-overflow-tooltip />
        <ElTableColumn :label="t('zcard.supply.platform')" width="160">
          <template #default="{ row }">
            <ElTag :type="driverTagType(row.driver)">{{ driverLabel(row.driver) }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.supply.baseUrl')" prop="base_url" min-width="200" show-overflow-tooltip />
        <ElTableColumn :label="t('zcard.supply.balance')" width="120">
          <template #default="{ row }">
            <span v-if="row.balance_cache !== null">{{ formatFen(row.balance_cache) }}</span>
            <span v-else class="text-muted">—</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.supply.syncedAt')" width="170">
          <template #default="{ row }">
            <span v-if="row.last_synced_at">{{ formatTime(row.last_synced_at) }}</span>
            <span v-else class="text-muted">—</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.supply.status')" width="100">
          <template #default="{ row }">
            <ElTag :type="row.status === 'active' ? 'success' : 'info'">
              {{ row.status === 'active' ? t('zcard.supply.statusActive') : t('zcard.supply.statusDisabled') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.common.actions')" width="280" fixed="right">
          <template #default="{ row }">
            <ElButton text type="primary" :loading="testingId === row.id" @click="handleTest(row)">
              {{ t('zcard.supply.test') }}
            </ElButton>
            <ElButton text type="primary" :loading="syncingId === row.id" @click="handleSync(row)">
              {{ t('zcard.supply.sync') }}
            </ElButton>
            <ElButton text type="primary" :loading="previewingId === row.id" @click="openPreview(row)">
              {{ t('zcard.supply.pullProducts') }}
            </ElButton>
            <ElButton text type="primary" @click="openEdit(row)">{{ t('zcard.common.edit') }}</ElButton>
            <ElButton text type="danger" @click="handleDelete(row)">{{ t('zcard.common.delete') }}</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <div ref="paginationRef" class="pagination-wrap">
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

      <!-- 同步错误提示(若有) -->
      <ElAlert
        v-for="row in errorRows"
        :key="row.id"
        :title="`${row.name}: ${row.last_error}`"
        type="error"
        :closable="false"
        style="margin-top: 10px"
      />
    </ElCard>

    <!-- 新建/编辑货源弹窗 -->
    <ElDialog v-model="dialogVisible" :title="isEdit ? t('zcard.supply.editTitle') : t('zcard.supply.addTitle')" width="560px" destroy-on-close>
      <ElForm ref="formRef" :model="formData" :rules="formRules" label-width="110px">
        <ElFormItem :label="t('zcard.supply.name')" prop="name">
          <ElInput v-model="formData.name" :placeholder="t('zcard.supply.namePlaceholder')" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.supply.platform')" prop="driver">
          <ElSelect v-model="formData.driver" :placeholder="t('zcard.supply.platformPlaceholder')" style="width: 100%" :disabled="isEdit" @change="onDriverChange">
            <ElOption v-for="d in drivers" :key="d.driver" :label="`${d.icon || ''} ${d.name}`" :value="d.driver" />
          </ElSelect>
        </ElFormItem>

        <!-- 动态凭证字段(按所选驱动 config_schema 渲染) -->
        <template v-if="currentSchemaFields.length">
          <ElFormItem
            v-for="field in currentSchemaFields"
            :key="field.key"
            :label="field.label"
            :required="field.required && !(isSensitive(field.key) && isEdit && maskedSet.has(field.key))"
            :prop="`credentials.${field.key}`"
          >
            <ElInputNumber
              v-if="field.type === 'number'"
              v-model="formData.credentials[field.key]"
              controls-position="right"
              style="width: 100%"
            />
            <ElInput
              v-else
              v-model="formData.credentials[field.key]"
              :type="isSensitive(field.key) ? 'password' : 'text'"
              show-password
              :placeholder="credentialPlaceholder(field)"
            />
            <div v-if="field.help" class="field-help">{{ field.help }}</div>
            <div v-else-if="isSensitive(field.key) && isEdit && maskedSet.has(field.key)" class="field-help">
              {{ t('zcard.supply.sensitiveKeepTip') }}
            </div>
            <div v-else-if="isSensitive(field.key)" class="field-help">{{ t('zcard.supply.sensitiveTip') }}</div>
          </ElFormItem>
        </template>

        <ElFormItem :label="t('zcard.supply.status')">
          <ElSelect v-model="formData.status" style="width: 100%">
            <ElOption :label="t('zcard.supply.statusActive')" value="active" />
            <ElOption :label="t('zcard.supply.statusDisabled')" value="disabled" />
          </ElSelect>
        </ElFormItem>

        <!-- 货源设置(库存模式/发卡/失败处理/定价) -->
        <ElDivider content-position="left">{{ t('zcard.supply.settingsSection') }}</ElDivider>
        <ElFormItem :label="t('zcard.supply.stockMode')">
          <ElSelect v-model="formData.settings.stock_mode" style="width: 100%">
            <ElOption :label="t('zcard.supply.stockModeSynced')" value="synced" />
            <ElOption :label="t('zcard.supply.stockModeRealtime')" value="realtime" />
          </ElSelect>
          <div class="field-help">{{ formData.settings.stock_mode === 'realtime' ? t('zcard.supply.stockModeRealtimeTip') : t('zcard.supply.stockModeSyncedTip') }}</div>
        </ElFormItem>
        <ElFormItem :label="t('zcard.supply.fulfillmentMode')">
          <ElSelect v-model="formData.settings.fulfillment_mode" style="width: 100%">
            <ElOption :label="t('zcard.supply.fulfillmentSync')" value="sync" />
            <ElOption :label="t('zcard.supply.fulfillmentAsync')" value="async" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="t('zcard.supply.failureAction')">
          <ElSelect v-model="formData.settings.failure_action" style="width: 100%">
            <ElOption :label="t('zcard.supply.failureManual')" value="manual" />
            <ElOption :label="t('zcard.supply.failureAutoRefund')" value="auto_refund" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="t('zcard.supply.pricingMode')">
          <ElSelect v-model="formData.settings.default_pricing_mode" style="width: 100%">
            <ElOption :label="t('zcard.supply.pricingPercent')" value="percent" />
            <ElOption :label="t('zcard.supply.pricingFixed')" value="fixed" />
            <ElOption :label="t('zcard.supply.pricingEqual')" value="equal" />
            <ElOption :label="t('zcard.supply.pricingPending')" value="pending" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem v-if="formData.settings.default_pricing_mode === 'percent'" :label="t('zcard.supply.markupPercent')">
          <div class="input-with-unit">
            <ElInputNumber v-model="formData.settings.default_markup_percent" :min="0" :max="500" :precision="0" controls-position="right" />
            <span class="unit">%</span>
          </div>
        </ElFormItem>
        <ElFormItem v-if="formData.settings.default_pricing_mode === 'fixed'" :label="t('zcard.supply.markupAmount')">
          <div class="input-with-unit">
            <ElInputNumber v-model="formData.settings.default_markup_amount" :min="0" :precision="2" controls-position="right" />
            <span class="unit">{{ t('zcard.supplierAccount.yuan') }}</span>
          </div>
        </ElFormItem>
        <ElFormItem :label="t('zcard.supply.autoList')">
          <ElSwitch v-model="formData.settings.auto_list" />
          <div class="field-help">{{ t('zcard.supply.autoListTip') }}</div>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="dialogVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="saving" @click="handleSubmit">{{ t('zcard.common.ok') }}</ElButton>
      </template>
    </ElDialog>

    <!-- 拉取/勾选导入商品弹窗 -->
    <ElDialog
      v-model="previewVisible"
      :title="t('zcard.supply.previewTitle')"
      width="780px"
      top="5vh"
      destroy-on-close
    >
      <div v-loading="previewLoading" class="preview-wrap">
        <div v-if="previewError" class="preview-error">{{ previewError }}</div>
        <template v-else>
          <div class="preview-toolbar">
            <span class="preview-summary">
              {{ t('zcard.supply.previewSummary', { total: previewTotal, selected: selectedCodes.size }) }}
            </span>
            <ElCheckbox v-model="checkAll" :indeterminate="isIndeterminate" @change="handleCheckAll">
              {{ t('zcard.supply.selectAll') }}
            </ElCheckbox>
          </div>
          <div class="preview-list">
            <div v-for="cat in previewCategories" :key="cat.category_code ?? '_'" class="preview-cat">
              <div class="preview-cat-head">{{ cat.category_name }} ({{ cat.products.length }})</div>
              <ElCheckboxGroup v-model="previewChecked" class="preview-cat-body">
                <div v-for="p in cat.products" :key="p.code" class="preview-product">
                  <ElCheckbox :value="p.code">
                    <div class="pp-content">
                      <span class="pp-name">{{ p.name }}</span>
                      <span class="pp-meta">
                        <span class="pp-price">¥{{ (p.factory_price / 100).toFixed(2) }}</span>
                        <ElTag v-if="p.already_imported" size="small" type="success" effect="plain">{{ t('zcard.supply.imported') }}</ElTag>
                      </span>
                    </div>
                  </ElCheckbox>
                </div>
              </ElCheckboxGroup>
            </div>
            <div v-if="previewCategories.length === 0" class="preview-empty">{{ t('zcard.supply.noProducts') }}</div>
          </div>
        </template>
      </div>
      <template #footer>
        <ElButton @click="previewVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="importing" :disabled="previewChecked.length === 0" @click="handleImport">
          {{ t('zcard.supply.importSelected', { n: previewChecked.length }) }}
        </ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { Plus } from '@element-plus/icons-vue'
  import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import { useListTableHeight } from '@/hooks'
  import {
    getSupplyDrivers,
    getSupplySources,
    createSupplySource,
    updateSupplySource,
    deleteSupplySource,
    testSupplySource,
    syncSupplySource,
    previewSupplyProducts,
    importSupplyProducts,
    type SupplySource,
    type SupplyDriver,
    type UpstreamCategory
  } from '@/api/supply'

  defineOptions({ name: 'SupplySourceList' })

  const { t } = useI18n()

  /** 列表 + 分页 */
  const loading = ref(false)
  const tableData = ref<SupplySource[]>([])
  const filterStatus = ref('')
  const pagination = reactive({ page: 1, pageSize: 15, total: 0 })
  // 表格高度自适应:数据满页时表格内容撑高会被卡片裁掉分页栏,固定表格高度使其内部滚动
  const { cardRef, tableRef, paginationRef, tableHeight } = useListTableHeight()
  /** 列表里有 last_error 的行(展示告警) */
  const errorRows = computed(() => tableData.value.filter((r) => r.last_error))

  const fetchData = async () => {
    loading.value = true
    try {
      const res = await getSupplySources({
        page: pagination.page,
        per_page: pagination.pageSize,
        status: filterStatus.value || undefined,
      })
      tableData.value = res.data || []
      pagination.total = res.total || 0
    } catch (e) {
      tableData.value = []
    } finally {
      loading.value = false
    }
  }

  /** 驱动元数据(选平台 + 动态凭证字段) */
  const drivers = ref<SupplyDriver[]>([])

  const fetchDrivers = async () => {
    try {
      const res = await getSupplyDrivers()
      drivers.value = res.drivers || []
    } catch (e) {
      drivers.value = []
    }
  }

  const driverLabel = (key: string) => {
    const d = drivers.value.find((x) => x.driver === key)
    return d ? `${d.icon || ''} ${d.name}`.trim() : key
  }
  const driverTagType = (key: string): 'primary' | 'success' | 'warning' => {
    if (key === 'dujiao_next') return 'primary'
    if (key === 'acg_faka') return 'warning'
    return 'success'
  }

  /** 弹窗 + 表单 */
  const dialogVisible = ref(false)
  const saving = ref(false)
  const isEdit = ref(false)
  const editingId = ref<number | null>(null)
  const formRef = ref<FormInstance>()
  /** 编辑时哪些敏感字段已被设过(脱敏值,留空=不改) */
  const maskedSet = ref<Set<string>>(new Set())

  const defaultSettings = () => ({
    stock_mode: 'synced',
    fulfillment_mode: 'sync',
    failure_action: 'manual',
    default_pricing_mode: 'percent',
    default_markup_percent: 10,
    default_markup_amount: 0,
    auto_list: true,
  })
  const defaultForm = () => ({
    name: '',
    driver: '' as string,
    status: 'active' as 'active' | 'disabled',
    credentials: {} as Record<string, any>,
    settings: defaultSettings(),
  })
  const formData = reactive(defaultForm())

  const formRules = computed<FormRules>(() => ({
    name: [{ required: true, message: t('zcard.supply.nameRequired'), trigger: 'blur' }],
    driver: [{ required: true, message: t('zcard.supply.platformRequired'), trigger: 'change' }],
  }))

  /** 当前所选驱动的凭证字段(从 config_schema 规范化成数组) */
  const currentSchemaFields = computed(() => {
    const d = drivers.value.find((x) => x.driver === formData.driver)
    if (!d) return []
    return Object.entries(d.config_schema || {}).map(([key, def]) => ({
      key,
      label: def.label || key,
      type: (def.type || 'text') as 'text' | 'number' | 'url' | 'secret',
      required: def.required ?? false,
      help: def.help,
    }))
  })

  /** 敏感字段(不回显,留空=保持原值) */
  const isSensitive = (key: string) => /(secret|app_key|key|token|password)/i.test(key)

  const credentialPlaceholder = (field: { key: string; type: string }) => {
    if (isSensitive(field.key)) return t('zcard.supply.sensitivePlaceholder')
    return ''
  }

  /** 选平台切换时,重置凭证字段 */
  const onDriverChange = () => {
    const obj: Record<string, any> = {}
    currentSchemaFields.value.forEach((f) => {
      obj[f.key] = f.type === 'number' ? null : ''
    })
    formData.credentials = obj
    maskedSet.value = new Set()
  }

  /** 新建 */
  const openAdd = () => {
    isEdit.value = false
    editingId.value = null
    Object.assign(formData, defaultForm())
    maskedSet.value = new Set()
    dialogVisible.value = true
    nextTick(() => formRef.value?.clearValidate())
  }

  /** 编辑:回填(凭证脱敏值不回填,标记 maskedSet) */
  const openEdit = (row: SupplySource) => {
    isEdit.value = true
    editingId.value = row.id
    formData.name = row.name
    formData.driver = row.driver
    formData.status = row.status
    const obj: Record<string, any> = {}
    const masked = new Set<string>()
    currentSchemaFields.value.forEach((f) => {
      const v = row.credentials?.[f.key]
      // 脱敏值(以 •• 开头)视为已设过,留空不改
      if (typeof v === 'string' && v.startsWith('••••')) {
        obj[f.key] = ''
        if (isSensitive(f.key)) masked.add(f.key)
      } else {
        obj[f.key] = v ?? (f.type === 'number' ? null : '')
      }
    })
    formData.credentials = obj
    maskedSet.value = masked
    // 回填货源设置(合并默认值)
    const rs = row.settings || {}
    formData.settings = {
      stock_mode: rs.stock_mode || 'synced',
      fulfillment_mode: rs.fulfillment_mode || 'sync',
      failure_action: rs.failure_action || 'manual',
      default_pricing_mode: rs.default_pricing_mode || 'percent',
      default_markup_percent: rs.default_markup_percent ?? 10,
      default_markup_amount: rs.default_markup_amount ?? 0,
      auto_list: rs.auto_list ?? true,
    }
    dialogVisible.value = true
    nextTick(() => formRef.value?.clearValidate())
  }

  /** 提交(新建/编辑) */
  const handleSubmit = async () => {
    if (!formRef.value) return
    try {
      await formRef.value.validate()
    } catch {
      return
    }
    // 构造 credentials:敏感字段留空的不传(编辑时保持原值)
    const creds: Record<string, any> = {}
    currentSchemaFields.value.forEach((f) => {
      const val = formData.credentials[f.key]
      if (isSensitive(f.key) && (val === '' || val === null || val === undefined)) return
      creds[f.key] = val
    })

    saving.value = true
    try {
      const payload = {
        name: formData.name,
        driver: formData.driver as SupplySource['driver'],
        base_url: creds.base_url || '',
        credentials: creds,
        status: formData.status,
        settings: formData.settings,
      }
      if (isEdit.value && editingId.value !== null) {
        await updateSupplySource(editingId.value, payload)
        ElMessage.success(t('zcard.supply.modified'))
      } else {
        await createSupplySource(payload)
        ElMessage.success(t('zcard.supply.created'))
      }
      dialogVisible.value = false
      fetchData()
    } catch (e: any) {
      // 拦截器已提示
    } finally {
      saving.value = false
    }
  }

  /** 删除 */
  const handleDelete = (row: SupplySource) => {
    ElMessageBox.confirm(t('zcard.supply.deleteConfirm', { name: row.name }), t('zcard.common.tips'), { type: 'warning' })
      .then(async () => {
        try {
          await deleteSupplySource(row.id)
          ElMessage.success(t('zcard.common.deleteSuccess'))
          fetchData()
        } catch (e: any) {
          // 拦截器已提示
        }
      })
      .catch(() => {})
  }

  /** 测试连通 */
  const testingId = ref<number | null>(null)
  const handleTest = async (row: SupplySource) => {
    testingId.value = row.id
    try {
      const res = await testSupplySource(row.id)
      if (res.connected) {
        ElMessage.success(
          t('zcard.supply.testSuccess', {
            balance: res.balance !== null && res.balance !== undefined ? formatFen(res.balance) : '—',
          }),
        )
      } else {
        ElMessage.error(t('zcard.supply.testFailed', { error: res.error || '' }))
      }
      fetchData()
    } catch (e: any) {
      // 拦截器已提示
    } finally {
      testingId.value = null
    }
  }

  /** 触发同步 */
  const syncingId = ref<number | null>(null)
  const handleSync = async (row: SupplySource) => {
    syncingId.value = row.id
    try {
      await syncSupplySource(row.id, 'incremental')
      ElMessage.success(t('zcard.supply.syncDispatched'))
      fetchData()
    } catch (e: any) {
      // 拦截器已提示
    } finally {
      syncingId.value = null
    }
  }

  /** ===== 拉取商品 + 勾选导入 ===== */
  const previewingId = ref<number | null>(null)
  const previewVisible = ref(false)
  const previewLoading = ref(false)
  const previewError = ref('')
  const previewCategories = ref<UpstreamCategory[]>([])
  const previewTotal = ref(0)
  /** 当前勾选的商品 code 列表(ElCheckboxGroup v-model) */
  const previewChecked = ref<string[]>([])
  const importing = ref(false)
  /** 当前操作的货源 id(导入时用) */
  const previewSourceId = ref<number | null>(null)

  /** 全部商品 code 集合(用于全选) */
  const allProductCodes = computed(() =>
    previewCategories.value.flatMap((c) => c.products.map((p) => p.code))
  )
  /** 已勾选 + 全部 → 计算全选/半选态 */
  const checkAll = computed({
    get: () => allProductCodes.value.length > 0 && previewChecked.value.length === allProductCodes.value.length,
    set: () => {} // 由 handleCheckAll 处理
  })
  const isIndeterminate = computed(() =>
    previewChecked.value.length > 0 && previewChecked.value.length < allProductCodes.value.length
  )
  /** selectedCodes 别名(模板用) */
  const selectedCodes = computed(() => new Set(previewChecked.value))

  const handleCheckAll = (val: any) => {
    previewChecked.value = val ? [...allProductCodes.value] : []
  }

  /** 打开预览弹窗:实时拉取上游商品 */
  const openPreview = async (row: SupplySource) => {
    previewingId.value = row.id
    previewSourceId.value = row.id
    previewVisible.value = true
    previewLoading.value = true
    previewError.value = ''
    previewCategories.value = []
    previewChecked.value = []
    previewTotal.value = 0
    try {
      const res = await previewSupplyProducts(row.id)
      if (res.ok) {
        previewCategories.value = res.categories || []
        previewTotal.value = res.total || 0
      } else {
        previewError.value = res.error || t('zcard.supply.previewFailed')
      }
    } catch (e: any) {
      previewError.value = e?.message || t('zcard.supply.previewFailed')
    } finally {
      previewLoading.value = false
      previewingId.value = null
    }
  }

  /** 勾选导入 */
  const handleImport = async () => {
    if (!previewSourceId.value || previewChecked.value.length === 0) return
    importing.value = true
    try {
      const res = await importSupplyProducts(previewSourceId.value, [...previewChecked.value])
      if (res.ok) {
        ElMessage.success(res.message || t('zcard.supply.importSuccess'))
        previewVisible.value = false
        fetchData()
      } else {
        ElMessage.error(res.error || t('zcard.supply.importFailed'))
      }
    } catch {
      // 拦截器处理
    } finally {
      importing.value = false
    }
  }


  /** 工具:分转元展示 */
  const formatFen = (fen: number | null | undefined) => {
    if (fen === null || fen === undefined) return '—'
    return (fen / 100).toFixed(2)
  }
  const formatTime = (iso: string | null) => {
    if (!iso) return '—'
    const d = new Date(iso.replace(' ', 'T'))
    if (isNaN(d.getTime())) return iso
    return d.toLocaleString()
  }

  onMounted(() => {
    fetchDrivers()
    fetchData()
  })
</script>

<style scoped>
  /* input + 单位(元/%)同行排列 */
  .input-with-unit {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .unit {
    color: var(--el-text-color-secondary);
    white-space: nowrap;
    flex-shrink: 0;
  }
  .supply-page {
    padding: 0;
  }
  .toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
  }
  .toolbar-left {
    display: flex;
    gap: 8px;
    align-items: center;
  }
  .pagination-wrap {
    margin-top: 16px;
    display: flex;
    justify-content: flex-end;
  }
  .text-muted {
    color: var(--el-text-color-placeholder);
  }
  .field-help {
    font-size: 12px;
    color: var(--el-text-color-placeholder);
    line-height: 1.5;
    margin-top: 4px;
    display: block;
  }

  /* 拉取商品弹窗 */
  .preview-wrap {
    min-height: 200px;
  }
  .preview-error {
    color: var(--el-color-danger);
    font-size: 13px;
    padding: 16px;
    text-align: center;
  }
  .preview-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }
  .preview-summary {
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }
  .preview-list {
    max-height: 55vh;
    overflow-y: auto;
  }
  .preview-cat {
    margin-bottom: 16px;
  }
  .preview-cat-head {
    font-size: 13px;
    font-weight: 600;
    color: var(--el-text-color-primary);
    margin-bottom: 8px;
    padding-left: 4px;
  }
  .preview-cat-body {
    display: flex;
    flex-direction: column;
    width: 100%;
  }
  .preview-product {
    padding: 6px 8px;
    border-bottom: 1px solid var(--el-border-color-extra-light);
  }
  .preview-product :deep(.el-checkbox__label) {
    width: 100%;
  }
  .pp-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
  }
  .pp-name {
    font-size: 13px;
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .pp-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
  }
  .pp-price {
    font-size: 13px;
    color: var(--el-color-danger);
    font-weight: 600;
  }
  .preview-empty {
    text-align: center;
    color: var(--el-text-color-placeholder);
    padding: 40px;
    font-size: 13px;
  }
</style>
