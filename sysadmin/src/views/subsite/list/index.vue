<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import {
  getSubsites,
  createSubsite,
  updateDomain,
  getSubsiteProductSettings,
  upsertSubsiteProductSetting,
} from '@/api/subsite'
import type { SubsiteMerchant, SubsiteProductSetting } from '@/api/subsite'

defineOptions({ name: 'SubsiteList' })

const { t } = useI18n()
const loading = ref(false)
const list = ref<SubsiteMerchant[]>([])
const keyword = ref('')

// 创建弹窗
const dialogVisible = ref(false)
const saving = ref(false)
const formData = ref({
  user_id: undefined as number | undefined,
  name: '',
  slug: '',
  default_markup_percent: 0,
  max_markup_percent: 0,
})

// 商品配置抽屉
const productDrawerVisible = ref(false)
const productLoading = ref(false)
const productSaving = ref(false)
const currentMerchant = ref<SubsiteMerchant | null>(null)
const productSettings = ref<SubsiteProductSetting[]>([])

const loadData = async () => {
  loading.value = true
  try {
    const data = await getSubsites({ keyword: keyword.value || undefined })
    // 兼容分页返回或裸数组
    if (Array.isArray(data)) {
      list.value = data
    } else {
      list.value = (data as any)?.data || []
    }
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
    (s) =>
      s.name?.toLowerCase().includes(kw) ||
      s.slug?.toLowerCase().includes(kw) ||
      s.owner?.username?.toLowerCase().includes(kw),
  )
})

// 统计
const stats = computed(() => {
  const total = list.value.length
  const active = list.value.filter((s) => s.status === 1).length
  const inactive = total - active
  return { total, active, inactive }
})

// 新增
const handleAdd = () => {
  formData.value = {
    user_id: undefined,
    name: '',
    slug: '',
    default_markup_percent: 0,
    max_markup_percent: 0,
  }
  dialogVisible.value = true
}

const handleSubmit = async () => {
  if (!formData.value.user_id) {
    ElMessage.warning(t('zcard.subsite.userIdRequired'))
    return
  }
  if (!formData.value.name.trim()) {
    ElMessage.warning(t('zcard.subsite.nameRequired'))
    return
  }
  if (!formData.value.slug.trim()) {
    ElMessage.warning(t('zcard.subsite.slugRequired'))
    return
  }
  saving.value = true
  try {
    await createSubsite({
      user_id: formData.value.user_id,
      name: formData.value.name.trim(),
      slug: formData.value.slug.trim(),
      default_markup_percent: formData.value.default_markup_percent,
      max_markup_percent: formData.value.max_markup_percent,
    })
    ElMessage.success(t('zcard.subsite.created'))
    dialogVisible.value = false
    loadData()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || t('zcard.common.operationFailed'))
  } finally {
    saving.value = false
  }
}

// 域名审批
const handleApproveDomain = (domainId: number) => {
  ElMessageBox.confirm(t('zcard.subsite.approveConfirm'), t('zcard.common.tips'), {
    type: 'warning',
  })
    .then(async () => {
      try {
        await updateDomain(domainId, { status: 'active', verification_status: 'verified' })
        ElMessage.success(t('zcard.subsite.approved'))
        loadData()
      } catch (e: any) {
        ElMessage.error(e?.response?.data?.message || t('zcard.common.operationFailed'))
      }
    })
    .catch(() => {})
}

const handleDisableDomain = (domainId: number) => {
  ElMessageBox.confirm(t('zcard.subsite.disableConfirm'), t('zcard.common.tips'), {
    type: 'warning',
  })
    .then(async () => {
      try {
        await updateDomain(domainId, { status: 'disabled' })
        ElMessage.success(t('zcard.subsite.disabled'))
        loadData()
      } catch (e: any) {
        ElMessage.error(e?.response?.data?.message || t('zcard.common.operationFailed'))
      }
    })
    .catch(() => {})
}

const domainTagType = (d: { status: string; verification_status: string }) => {
  if (d.status === 'active' && d.verification_status === 'verified') return 'success'
  if (d.status === 'disabled') return 'info'
  return 'warning'
}

// 商品配置
const handleViewProducts = async (row: SubsiteMerchant) => {
  currentMerchant.value = row
  productDrawerVisible.value = true
  productLoading.value = true
  productSettings.value = []
  try {
    const data = await getSubsiteProductSettings(row.id)
    if (Array.isArray(data)) {
      productSettings.value = data
    } else {
      productSettings.value = (data as any)?.data || []
    }
  } catch {
    productSettings.value = []
  } finally {
    productLoading.value = false
  }
}

const handleToggleListed = async (row: SubsiteProductSetting) => {
  if (!currentMerchant.value) return
  productSaving.value = true
  try {
    await upsertSubsiteProductSetting({
      merchant_id: currentMerchant.value.id,
      product_id: row.product_id,
      sku_id: row.sku_id,
      is_listed: !row.is_listed,
      pricing_mode: row.pricing_mode,
      markup_percent: Number(row.markup_percent),
      fixed_markup_amount: row.fixed_markup_amount,
      fixed_price_amount: row.fixed_price_amount,
    })
    row.is_listed = !row.is_listed
    ElMessage.success(t('zcard.subsite.productSaved'))
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || t('zcard.common.operationFailed'))
  } finally {
    productSaving.value = false
  }
}

const handleSaveProduct = async (row: SubsiteProductSetting) => {
  if (!currentMerchant.value) return
  productSaving.value = true
  try {
    await upsertSubsiteProductSetting({
      merchant_id: currentMerchant.value.id,
      product_id: row.product_id,
      sku_id: row.sku_id,
      is_listed: row.is_listed,
      pricing_mode: row.pricing_mode,
      markup_percent: Number(row.markup_percent),
      fixed_markup_amount: row.fixed_markup_amount,
      fixed_price_amount: row.fixed_price_amount,
    })
    ElMessage.success(t('zcard.subsite.productSaved'))
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || t('zcard.common.operationFailed'))
  } finally {
    productSaving.value = false
  }
}

const pricingModeLabel = (mode: string) => {
  if (mode === 'percent') return t('zcard.subsite.modePercent')
  if (mode === 'fixed_markup') return t('zcard.subsite.modeFixedMarkup')
  if (mode === 'fixed_price') return t('zcard.subsite.modeFixedPrice')
  return mode || '-'
}

onMounted(loadData)
</script>

<template>
  <div class="subsite-page art-full-height">
    <!-- 统计卡片 -->
    <div class="stats-row">
      <div class="stat-mini">
        <span class="stat-num">{{ stats.total }}</span>
        <span class="stat-label">{{ t('zcard.subsite.statTotal') }}</span>
      </div>
      <div class="stat-mini">
        <span class="stat-num" style="color: var(--el-color-success)">{{ stats.active }}</span>
        <span class="stat-label">{{ t('zcard.subsite.statActive') }}</span>
      </div>
      <div class="stat-mini">
        <span class="stat-num" style="color: var(--el-color-info)">{{ stats.inactive }}</span>
        <span class="stat-label">{{ t('zcard.subsite.statInactive') }}</span>
      </div>
    </div>

    <ElCard class="art-table-card" shadow="never">
      <!-- 工具栏 -->
      <div class="toolbar">
        <div class="toolbar-left">
          <ElInput
            v-model="keyword"
            :placeholder="t('zcard.subsite.searchPlaceholder')"
            clearable
            style="width: 220px"
            @keyup.enter="loadData"
          />
          <ElButton @click="loadData">{{ t('zcard.common.search') }}</ElButton>
        </div>
        <div class="toolbar-right">
          <ElButton type="primary" @click="handleAdd()">➕ {{ t('zcard.subsite.addSubsite') }}</ElButton>
        </div>
      </div>

      <!-- 表格 -->
      <ElTable v-loading="loading" :data="filteredList" row-key="id" border stripe>
        <ElTableColumn :label="t('zcard.subsite.name')" min-width="140">
          <template #default="{ row }">
            <div class="name-cell">
              <span class="name-text">{{ row.name }}</span>
              <ElTag v-if="row.status === 1" size="small" type="success">{{ t('zcard.subsite.active') }}</ElTag>
              <ElTag v-else size="small" type="info">{{ t('zcard.subsite.inactive') }}</ElTag>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.subsite.slug')" min-width="120" prop="slug" />
        <ElTableColumn :label="t('zcard.subsite.owner')" min-width="120">
          <template #default="{ row }">
            {{ row.owner?.username || '-' }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.subsite.domains')" min-width="280">
          <template #default="{ row }">
            <div v-if="!row.domains?.length" class="text-muted">-</div>
            <div v-else class="domain-list">
              <div v-for="d in row.domains" :key="d.id" class="domain-item">
                <ElTag :type="domainTagType(d)" size="small" class="domain-tag">
                  {{ d.domain }}
                </ElTag>
                <span class="domain-actions">
                  <ElButton
                    v-if="!(d.status === 'active' && d.verification_status === 'verified')"
                    text type="success" size="small"
                    @click="handleApproveDomain(d.id)"
                  >{{ t('zcard.subsite.approveDomain') }}</ElButton>
                  <ElButton
                    v-if="d.status !== 'disabled'"
                    text type="danger" size="small"
                    @click="handleDisableDomain(d.id)"
                  >{{ t('zcard.subsite.disableDomain') }}</ElButton>
                </span>
              </div>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.subsite.defaultMarkup')" width="120" align="center">
          <template #default="{ row }">
            {{ row.settings?.default_markup_percent ?? row.commission_rate ?? 0 }}%
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.common.actions')" width="160" align="center" fixed="right">
          <template #default="{ row }">
            <ElButton text type="primary" size="small" @click="handleViewProducts(row)">
              {{ t('zcard.subsite.productSettings') }}
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>

    <!-- 创建弹窗 -->
    <ElDialog
      v-model="dialogVisible"
      :title="t('zcard.subsite.createSubsite')"
      width="520px"
      destroy-on-close
    >
      <ElForm :model="formData" label-width="120px">
        <ElFormItem :label="t('zcard.subsite.userId')" required>
          <ElInputNumber v-model="formData.user_id" :min="1" :controls="false" style="width: 100%" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.subsite.name')" required>
          <ElInput v-model="formData.name" :placeholder="t('zcard.subsite.namePlaceholder')" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.subsite.slug')" required>
          <ElInput v-model="formData.slug" :placeholder="t('zcard.subsite.slugPlaceholder')" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.subsite.defaultMarkup')">
          <ElInputNumber v-model="formData.default_markup_percent" :min="0" :precision="2" />
          <span class="form-suffix">%</span>
        </ElFormItem>
        <ElFormItem :label="t('zcard.subsite.maxMarkup')">
          <ElInputNumber v-model="formData.max_markup_percent" :min="0" :precision="2" />
          <span class="form-suffix">%</span>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="dialogVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="saving" @click="handleSubmit">{{ t('zcard.common.ok') }}</ElButton>
      </template>
    </ElDialog>

    <!-- 商品配置抽屉 -->
    <ElDrawer
      v-model="productDrawerVisible"
      :title="`${t('zcard.subsite.productSettings')} - ${currentMerchant?.name || ''}`"
      size="60%"
      destroy-on-close
    >
      <ElTable v-loading="productLoading" :data="productSettings" row-key="product_id" border stripe size="small">
        <ElTableColumn :label="t('zcard.subsite.productName')" min-width="160">
          <template #default="{ row }">
            {{ row.product?.name || '#' + row.product_id }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.subsite.pricingMode')" width="150">
          <template #default="{ row }">
            <ElSelect v-model="row.pricing_mode" size="small" style="width: 120px">
              <ElOption :label="t('zcard.subsite.modePercent')" value="percent" />
              <ElOption :label="t('zcard.subsite.modeFixedMarkup')" value="fixed_markup" />
              <ElOption :label="t('zcard.subsite.modeFixedPrice')" value="fixed_price" />
            </ElSelect>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.subsite.markup')" width="160">
          <template #default="{ row }">
            <ElInputNumber
              v-model="row.markup_percent"
              :min="0"
              :precision="2"
              :controls="false"
              size="small"
              style="width: 120px"
            />
            <span class="form-suffix">%</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.subsite.listed')" width="100" align="center">
          <template #default="{ row }">
            <ElSwitch :model-value="row.is_listed" size="small" @change="handleToggleListed(row)" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.common.actions')" width="100" align="center" fixed="right">
          <template #default="{ row }">
            <ElButton text type="primary" size="small" :loading="productSaving" @click="handleSaveProduct(row)">
              {{ t('zcard.common.save') }}
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElDrawer>
  </div>
</template>

<style lang="scss" scoped>
  .subsite-page {
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
  .name-cell {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .name-text {
    font-weight: 600;
    color: var(--el-color-primary);
  }
  .text-muted {
    color: var(--el-text-color-secondary);
  }
  .domain-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .domain-item {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }
  .domain-tag {
    font-family: monospace;
  }
  .domain-actions {
    display: inline-flex;
    gap: 4px;
  }
  .form-suffix {
    margin-left: 6px;
    color: var(--el-text-color-secondary);
  }
</style>
