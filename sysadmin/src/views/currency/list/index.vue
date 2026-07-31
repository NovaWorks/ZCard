<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import {
  getCurrencies,
  createCurrency,
  updateCurrency,
  deleteCurrency,
} from '@/api/currency'
import type { Currency } from '@/api/currency'

defineOptions({ name: 'CurrencyList' })

const { t } = useI18n()
const loading = ref(false)
const list = ref<Currency[]>([])
const keyword = ref('')

// 弹窗
const dialogVisible = ref(false)
const saving = ref(false)
const isEdit = ref(false)
const formData = ref({
  code: '',
  name: '',
  symbol: '',
  symbol_position: 'before' as 'before' | 'after',
  decimal_places: 2,
  exchange_rate: '1',
  is_base: false,
  is_enabled: true,
  sort: 0,
})

const loadData = async () => {
  loading.value = true
  try {
    const data = await getCurrencies()
    list.value = data || []
  } catch {
    list.value = []
  } finally {
    loading.value = false
  }
}

const filteredList = computed(() => {
  if (!keyword.value) return list.value
  const kw = keyword.value.toLowerCase()
  return list.value.filter(
    (c) =>
      c.code.toLowerCase().includes(kw) ||
      c.name.toLowerCase().includes(kw),
  )
})

// 新增
const handleAdd = () => {
  isEdit.value = false
  formData.value = {
    code: '',
    name: '',
    symbol: '',
    symbol_position: 'before',
    decimal_places: 2,
    exchange_rate: '1',
    is_base: false,
    is_enabled: true,
    sort: 0,
  }
  dialogVisible.value = true
}

const handleEdit = (row: Currency) => {
  isEdit.value = true
  formData.value = {
    code: row.code,
    name: row.name,
    symbol: row.symbol,
    symbol_position: row.symbol_position,
    decimal_places: row.decimal_places,
    exchange_rate: row.exchange_rate,
    is_base: row.is_base,
    is_enabled: row.is_enabled,
    sort: row.sort,
  }
  dialogVisible.value = true
}

const handleDelete = (row: Currency) => {
  ElMessageBox.confirm(
    t('zcard.currency.deleteConfirm', { name: row.name }),
    t('zcard.common.tips'),
    { type: 'warning' },
  )
    .then(async () => {
      try {
        await deleteCurrency(row.code)
        ElMessage.success(t('zcard.common.deleteSuccess'))
        loadData()
      } catch (e: any) {
        ElMessage.error(e?.response?.data?.message || t('zcard.common.operationFailed'))
      }
    })
    .catch(() => {})
}

const handleSubmit = async () => {
  if (!isEdit.value && !/^[A-Za-z]{3}$/.test(formData.value.code.trim())) {
    ElMessage.warning(t('zcard.currency.codeRequired'))
    return
  }
  if (!formData.value.name.trim()) {
    ElMessage.warning(t('zcard.currency.name'))
    return
  }
  saving.value = true
  try {
    const payload: Partial<Currency> = {
      name: formData.value.name,
      symbol: formData.value.symbol,
      symbol_position: formData.value.symbol_position,
      decimal_places: formData.value.decimal_places,
      exchange_rate: formData.value.is_base ? '1' : formData.value.exchange_rate,
      is_base: formData.value.is_base,
      is_enabled: formData.value.is_enabled,
      sort: formData.value.sort,
    }
    if (isEdit.value) {
      await updateCurrency(formData.value.code, payload)
      ElMessage.success(t('zcard.currency.modified'))
    } else {
      payload.code = formData.value.code.trim().toUpperCase()
      await createCurrency(payload)
      ElMessage.success(t('zcard.currency.created'))
    }
    dialogVisible.value = false
    loadData()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || t('zcard.common.operationFailed'))
  } finally {
    saving.value = false
  }
}

// 内联启用切换
const handleToggleEnabled = async (row: Currency) => {
  try {
    await updateCurrency(row.code, { is_enabled: !row.is_enabled })
    ElMessage.success(t('zcard.currency.modified'))
    loadData()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || t('zcard.common.operationFailed'))
  }
}

// 内联汇率保存
const handleRateBlur = async (row: Currency) => {
  if (row.is_base) return
  try {
    await updateCurrency(row.code, { exchange_rate: row.exchange_rate })
    loadData()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || t('zcard.common.operationFailed'))
  }
}

// 内联排序保存
const handleSortBlur = async (row: Currency) => {
  try {
    await updateCurrency(row.code, { sort: row.sort })
    loadData()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || t('zcard.common.operationFailed'))
  }
}

// 切换基础货币
const handleToggleBase = async (row: Currency) => {
  if (row.is_base) return // 已是基础货币不重复触发
  ElMessageBox.confirm(
    t('zcard.currency.setBaseConfirm', { name: row.name }),
    t('zcard.common.tips'),
    { type: 'warning' },
  )
    .then(async () => {
      try {
        await updateCurrency(row.code, { is_base: true })
        ElMessage.success(t('zcard.currency.modified'))
        loadData()
      } catch (e: any) {
        ElMessage.error(e?.response?.data?.message || t('zcard.common.operationFailed'))
      }
    })
    .catch(() => {})
}

// 统计
const stats = computed(() => {
  const total = list.value.length
  const active = list.value.filter((c) => c.is_enabled).length
  return { total, active, inactive: total - active }
})

onMounted(loadData)
</script>

<template>
  <div class="currency-page art-full-height">
    <!-- 统计卡片 -->
    <div class="stats-row">
      <div class="stat-mini">
        <span class="stat-num">{{ stats.total }}</span>
        <span class="stat-label">{{ t('zcard.currency.statTotal') }}</span>
      </div>
      <div class="stat-mini">
        <span class="stat-num" style="color: var(--el-color-success)">{{ stats.active }}</span>
        <span class="stat-label">{{ t('zcard.currency.statActive') }}</span>
      </div>
      <div class="stat-mini">
        <span class="stat-num" style="color: var(--el-color-info)">{{ stats.inactive }}</span>
        <span class="stat-label">{{ t('zcard.currency.statInactive') }}</span>
      </div>
    </div>

    <ElCard class="art-table-card" shadow="never">
      <!-- 工具栏 -->
      <div class="toolbar">
        <div class="toolbar-left">
          <ElInput
            v-model="keyword"
            :placeholder="t('zcard.currency.searchPlaceholder')"
            clearable
            style="width: 220px"
          />
        </div>
        <div class="toolbar-right">
          <ElButton type="primary" @click="handleAdd()">➕ {{ t('zcard.currency.add') }}</ElButton>
        </div>
      </div>

      <!-- 表格 -->
      <ElTable v-loading="loading" :data="filteredList" row-key="code" border stripe>
        <ElTableColumn :label="t('zcard.currency.code')" min-width="120">
          <template #default="{ row }">
            <div class="code-cell">
              <span class="code-text">{{ row.code }}</span>
              <ElTag v-if="row.is_base" size="small" type="warning">{{ t('zcard.currency.baseTag') }}</ElTag>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.currency.name')" min-width="120" prop="name" />
        <ElTableColumn :label="t('zcard.currency.symbol')" width="120" align="center">
          <template #default="{ row }">
            <span class="symbol-preview">
              {{ row.symbol_position === 'before' ? row.symbol + '1' : '1' + row.symbol }}
            </span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.currency.position')" width="100" align="center">
          <template #default="{ row }">
            {{ row.symbol_position === 'before' ? t('zcard.currency.positionBefore') : t('zcard.currency.positionAfter') }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.currency.decimalPlaces')" width="90" align="center" prop="decimal_places" />
        <ElTableColumn :label="t('zcard.currency.exchangeRate')" width="160" align="center">
          <template #default="{ row }">
            <ElInputNumber
              v-if="!row.is_base"
              v-model="row.exchange_rate"
              :min="0"
              :precision="6"
              :step="0.01"
              :controls="false"
              size="small"
              style="width: 120px"
              @blur="handleRateBlur(row)"
            />
            <span v-else class="base-rate">1</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.currency.base')" width="90" align="center">
          <template #default="{ row }">
            <ElSwitch :model-value="row.is_base" size="small" @change="handleToggleBase(row)" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.currency.sort')" width="110" align="center">
          <template #default="{ row }">
            <ElInputNumber
              v-model="row.sort"
              :min="0"
              :max="65535"
              size="small"
              controls-position="right"
              style="width: 90px"
              @change="handleSortBlur(row)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.currency.enabled')" width="90" align="center">
          <template #default="{ row }">
            <ElSwitch :model-value="row.is_enabled" size="small" @change="handleToggleEnabled(row)" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.common.actions')" width="160" align="center" fixed="right">
          <template #default="{ row }">
            <ElButton text type="primary" size="small" @click="handleEdit(row)">{{ t('zcard.common.edit') }}</ElButton>
            <ElButton
              text
              type="danger"
              size="small"
              :disabled="row.is_base"
              @click="handleDelete(row)"
            >{{ t('zcard.common.delete') }}</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>

    <!-- 新增/编辑弹窗 -->
    <ElDialog
      v-model="dialogVisible"
      :title="isEdit ? t('zcard.currency.edit') : t('zcard.currency.add')"
      width="520px"
      destroy-on-close
    >
      <ElForm :model="formData" label-width="100px">
        <ElFormItem :label="t('zcard.currency.code')" :required="!isEdit">
          <ElInput
            v-if="!isEdit"
            v-model="formData.code"
            :placeholder="t('zcard.currency.codePlaceholder')"
            maxlength="3"
            show-word-limit
          />
          <span v-else class="code-text">{{ formData.code }}</span>
        </ElFormItem>
        <ElFormItem :label="t('zcard.currency.name')" required>
          <ElInput v-model="formData.name" :placeholder="t('zcard.currency.namePlaceholder')" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.currency.symbol')">
          <ElInput v-model="formData.symbol" :placeholder="t('zcard.currency.symbolPlaceholder')" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.currency.position')">
          <ElSelect v-model="formData.symbol_position" style="width: 100%">
            <ElOption :label="t('zcard.currency.positionBefore')" value="before" />
            <ElOption :label="t('zcard.currency.positionAfter')" value="after" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="t('zcard.currency.decimalPlaces')">
          <ElInputNumber v-model="formData.decimal_places" :min="0" :max="8" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.currency.exchangeRate')">
          <ElInput
            v-model="formData.exchange_rate"
            :placeholder="t('zcard.currency.exchangeRatePlaceholder')"
            :disabled="formData.is_base"
          />
          <span v-if="formData.is_base" class="form-tip">{{ t('zcard.currency.baseRateTip') }}</span>
        </ElFormItem>
        <ElFormItem :label="t('zcard.currency.sort')">
          <ElInputNumber v-model="formData.sort" :min="0" :max="65535" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.currency.enabled')">
          <ElSwitch v-model="formData.is_enabled" />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="dialogVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="saving" @click="handleSubmit">{{ t('zcard.common.ok') }}</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<style lang="scss" scoped>
  .currency-page {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }
  .stats-row {
    display: flex;
    gap: 16px;
  }
  .stat-mini {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    padding: 12px 24px;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 8px;
    min-width: 120px;
  }
  .stat-num {
    font-size: 24px;
    font-weight: 700;
    color: var(--el-color-primary);
  }
  .stat-label {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }
  .toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 8px;
  }
  .toolbar-left {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  .toolbar-right {
    display: flex;
    gap: 8px;
    align-items: center;
  }
  .code-cell {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .code-text {
    font-family: monospace;
    font-weight: 600;
    color: var(--el-color-primary);
  }
  .symbol-preview {
    font-family: monospace;
    color: var(--el-text-color-secondary);
  }
  .base-rate {
    font-weight: 600;
    color: var(--el-color-warning);
  }
  .form-tip {
    margin-left: 8px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }
</style>
