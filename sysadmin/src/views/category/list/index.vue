<script setup lang="ts">
import { ref, computed, onActivated } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import {
  getCategories,
  createCategory,
  updateCategory,
  deleteCategory,
  updateCategorySort,
  batchUpdateCategory,
} from '@/api/categories'
import type { Category, SortItem } from '@/api/categories'
import IconPicker from '@/components/business/icon-picker/index.vue'
import ImagePicker from '@/components/business/image-picker/index.vue'

defineOptions({ name: 'CategoryList' })

const { t } = useI18n()
const loading = ref(false)
const list = ref<Category[]>([])
const keyword = ref('')
const statusFilter = ref<number | ''>('')
const hideFilter = ref<number | ''>('')

// 选中项
const selectedIds = ref<number[]>([])

// 弹窗
const dialogVisible = ref(false)
const saving = ref(false)
const isEdit = ref(false)
const formData = ref({
  id: 0,
  name: '',
  slug: '',
  icon: '',
  description: '',
  parent_id: null as number | null,
  sort: 0,
  status: true,
  hide: false,
})

// 父分类选项
const flatCategories = computed(() => {
  const result: { label: string; value: number }[] = []
  const walk = (cats: Category[], depth = 0) => {
    cats.forEach((c) => {
      if (isEdit.value && c.id === formData.value.id) return
      result.push({ label: '— '.repeat(depth) + c.name, value: c.id })
      if (c.children?.length) walk(c.children, depth + 1)
    })
  }
  walk(list.value)
  return result
})

const loadData = async () => {
  loading.value = true
  try {
    const params: any = {}
    if (keyword.value) params.keyword = keyword.value
    if (statusFilter.value !== '') params.status = statusFilter.value
    if (hideFilter.value !== '') params.hide = hideFilter.value
    const data = await getCategories(params)
    list.value = data || []
  } catch {
    list.value = []
  } finally {
    loading.value = false
  }
}

const handleSearch = () => loadData()

const resetSearch = () => {
  keyword.value = ''
  statusFilter.value = ''
  hideFilter.value = ''
  loadData()
}

// 新增
const handleAdd = (parent?: Category) => {
  isEdit.value = false
  formData.value = {
    id: 0, name: '', slug: '', icon: '', description: '',
    parent_id: parent ? parent.id : null, sort: 0, status: true, hide: false,
  }
  dialogVisible.value = true
}

const handleEdit = (row: Category) => {
  isEdit.value = true
  formData.value = {
    id: row.id, name: row.name, slug: row.slug,
    icon: row.icon || '', description: row.description || '',
    parent_id: row.parent_id, sort: row.sort,
    status: row.status === 1, hide: row.hide === 1,
  }
  dialogVisible.value = true
}

const handleDelete = (row: Category) => {
  ElMessageBox.confirm(t('zcard.category.deleteConfirm', { name: row.name }), t('zcard.common.tips'), { type: 'warning' })
    .then(async () => {
      try {
        await deleteCategory(row.id)
        ElMessage.success(t('zcard.common.deleteSuccess'))
        loadData()
      } catch (e: any) {
        ElMessage.error(e?.response?.data?.message || t('zcard.common.operationFailed'))
      }
    })
    .catch(() => {})
}

const handleSubmit = async () => {
  if (!formData.value.name.trim()) {
    ElMessage.warning(t('zcard.category.nameRequired'))
    return
  }
  saving.value = true
  try {
    const payload: any = { ...formData.value }
    if (isEdit.value) {
      await updateCategory(formData.value.id, payload)
      ElMessage.success(t('zcard.category.modified'))
    } else {
      await createCategory(payload)
      ElMessage.success(t('zcard.category.created'))
    }
    dialogVisible.value = false
    loadData()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || t('zcard.common.operationFailed'))
  } finally {
    saving.value = false
  }
}

// 内联状态/隐藏切换
const handleToggle = async (row: Category, field: 'status' | 'hide') => {
  try {
    const newVal = field === 'status' ? (row.status === 1 ? 0 : 1) : (row.hide === 1 ? 0 : 1)
    await updateCategory(row.id, { [field]: newVal })
    ElMessage.success(t('zcard.category.modified'))
    loadData()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || t('zcard.common.operationFailed'))
  }
}

// 内联排序
const handleSortBlur = async (row: Category) => {
  try {
    await updateCategory(row.id, { sort: row.sort })
    loadData()
  } catch { /* ignore */ }
}

// 批量操作
const handleBatch = (field: 'status' | 'hide', value: number) => {
  if (selectedIds.value.length === 0) {
    ElMessage.warning(t('zcard.category.selectFirst'))
    return
  }
  const label = field === 'status'
    ? (value === 1 ? t('zcard.category.batchEnable') : t('zcard.category.batchDisable'))
    : (value === 1 ? t('zcard.category.batchHide') : t('zcard.category.batchShow'))
  ElMessageBox.confirm(`${label} ${selectedIds.value.length} ${t('zcard.category.categoryUnit')}?`, t('zcard.common.tips'), { type: 'warning' })
    .then(async () => {
      try {
        await batchUpdateCategory(selectedIds.value, field, value)
        ElMessage.success(t('zcard.category.modified'))
        selectedIds.value = []
        loadData()
      } catch (e: any) {
        ElMessage.error(e?.response?.data?.message || t('zcard.common.operationFailed'))
      }
    })
    .catch(() => {})
}

// 表格选择
const handleSelectionChange = (rows: Category[]) => {
  selectedIds.value = rows.map(r => r.id)
}

// 统计
const stats = computed(() => {
  let total = 0, active = 0
  const count = (cats: Category[]) => {
    cats.forEach((c) => {
      total++
      if (c.status === 1) active++
      if (c.children?.length) count(c.children)
    })
  }
  count(list.value)
  return { total, active, inactive: total - active }
})

onActivated(loadData)
</script>

<template>
  <div class="category-page art-full-height">
    <!-- 统计卡片 -->
    <div class="stats-row">
      <div class="stat-mini">
        <span class="stat-num">{{ stats.total }}</span>
        <span class="stat-label">{{ t('zcard.category.statTotal') }}</span>
      </div>
      <div class="stat-mini">
        <span class="stat-num" style="color: var(--el-color-success)">{{ stats.active }}</span>
        <span class="stat-label">{{ t('zcard.category.statActive') }}</span>
      </div>
      <div class="stat-mini">
        <span class="stat-num" style="color: var(--el-color-info)">{{ stats.inactive }}</span>
        <span class="stat-label">{{ t('zcard.category.statInactive') }}</span>
      </div>
    </div>

    <ElCard class="art-table-card" shadow="never">
      <!-- 工具栏 -->
      <div class="toolbar">
        <div class="toolbar-left">
          <ElInput v-model="keyword" :placeholder="t('zcard.category.searchPlaceholder')" clearable style="width: 200px" @keyup.enter="handleSearch" @clear="resetSearch" />
          <ElSelect v-model="statusFilter" :placeholder="t('zcard.category.status')" style="width: 120px" @change="handleSearch">
            <ElOption :label="t('zcard.order.allStatus')" value="" />
            <ElOption :label="t('zcard.category.statusOn')" :value="1" />
            <ElOption :label="t('zcard.category.statusOff')" :value="0" />
          </ElSelect>
          <ElSelect v-model="hideFilter" :placeholder="t('zcard.category.hideLabel')" style="width: 120px" @change="handleSearch">
            <ElOption :label="t('zcard.order.allStatus')" value="" />
            <ElOption :label="t('zcard.category.hideVisible')" :value="0" />
            <ElOption :label="t('zcard.category.hideHidden')" :value="1" />
          </ElSelect>
          <ElButton type="primary" @click="handleSearch">{{ t('zcard.common.search') }}</ElButton>
          <ElButton @click="resetSearch">{{ t('zcard.common.reset') }}</ElButton>
        </div>
        <div class="toolbar-right">
          <ElButton v-if="selectedIds.length" type="success" plain size="small" @click="handleBatch('status', 1)">{{ t('zcard.category.batchEnable') }}</ElButton>
          <ElButton v-if="selectedIds.length" type="warning" plain size="small" @click="handleBatch('status', 0)">{{ t('zcard.category.batchDisable') }}</ElButton>
          <ElButton v-if="selectedIds.length" type="info" plain size="small" @click="handleBatch('hide', 1)">{{ t('zcard.category.batchHide') }}</ElButton>
          <ElButton v-if="selectedIds.length" size="small" @click="handleBatch('hide', 0)">{{ t('zcard.category.batchShow') }}</ElButton>
          <ElButton type="primary" @click="handleAdd()">➕ {{ t('zcard.category.add') }}</ElButton>
        </div>
      </div>

      <!-- 树形表格 -->
      <ElTable v-loading="loading" :data="list" row-key="id" border stripe
        :tree-props="{ children: 'children' }" :default-expand-all="false"
        @selection-change="handleSelectionChange"
      >
        <ElTableColumn type="selection" width="45" />
        <ElTableColumn :label="t('zcard.category.name')" min-width="220">
          <template #default="{ row }">
            <div class="cat-name-cell">
              <template v-if="row.icon">
                <img v-if="/^https?:\/\/|^\/storage\//.test(row.icon)" :src="row.icon" class="cat-icon-img" />
                <span v-else class="cat-icon">{{ row.icon }}</span>
              </template>
              <span v-else class="cat-icon-placeholder">📁</span>
              <span class="cat-name">{{ row.name }}</span>
              <ElTag v-if="row.description" size="small" type="info" class="cat-desc-tag">{{ row.description }}</ElTag>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.category.slug')" min-width="120" show-overflow-tooltip>
          <template #default="{ row }"><span class="cat-slug">{{ row.slug }}</span></template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.category.sort')" width="100" align="center">
          <template #default="{ row }">
            <ElInputNumber v-model="row.sort" :min="0" :max="65535" size="small" controls-position="right" style="width: 80px" @change="handleSortBlur(row)" />
          </template>
        </ElTableColumn>
        <!-- 隐藏开关 -->
        <ElTableColumn :label="t('zcard.category.hideLabel')" width="80" align="center">
          <template #default="{ row }">
            <ElSwitch :model-value="row.hide === 1" size="small" @change="handleToggle(row, 'hide')" />
          </template>
        </ElTableColumn>
        <!-- 状态开关 -->
        <ElTableColumn :label="t('zcard.category.status')" width="80" align="center">
          <template #default="{ row }">
            <ElSwitch :model-value="row.status === 1" size="small" @change="handleToggle(row, 'status')" />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.common.actions')" width="200" align="center" fixed="right">
          <template #default="{ row }">
            <ElButton text type="primary" size="small" @click="handleAdd(row)">{{ t('zcard.category.addChild') }}</ElButton>
            <ElButton text type="primary" size="small" @click="handleEdit(row)">{{ t('zcard.common.edit') }}</ElButton>
            <ElButton text type="danger" size="small" @click="handleDelete(row)">{{ t('zcard.common.delete') }}</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>

    <!-- 新增/编辑弹窗 -->
    <ElDialog v-model="dialogVisible" :title="isEdit ? t('zcard.category.edit') : t('zcard.category.add')" width="520px" destroy-on-close>
      <ElForm :model="formData" label-width="90px">
        <ElFormItem :label="t('zcard.category.nameLabel')" required>
          <ElInput v-model="formData.name" :placeholder="t('zcard.category.searchPlaceholder')" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.category.icon')">
          <div class="icon-input-row">
            <IconPicker v-model="formData.icon" />
            <ImagePicker v-model="formData.icon" />
          </div>
        </ElFormItem>
        <ElFormItem :label="t('zcard.category.descriptionLabel')">
          <ElInput v-model="formData.description" :placeholder="t('zcard.category.descriptionPlaceholder')" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.category.slug')">
          <ElInput v-model="formData.slug" :placeholder="t('zcard.category.slugPlaceholder')" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.category.parent')">
          <ElSelect v-model="formData.parent_id" clearable :placeholder="t('zcard.category.parentPlaceholder')" style="width: 100%">
            <ElOption v-for="c in flatCategories" :key="c.value" :label="c.label" :value="c.value" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="t('zcard.category.sort')">
          <ElInputNumber v-model="formData.sort" :min="0" :max="65535" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.category.hideLabel')">
          <ElSwitch v-model="formData.hide" />
          <span class="form-tip">{{ t('zcard.category.hideTip') }}</span>
        </ElFormItem>
        <ElFormItem :label="t('zcard.category.status')">
          <ElSwitch v-model="formData.status" />
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
  .category-page { display: flex; flex-direction: column; gap: 16px; }
  .stats-row { display: flex; gap: 16px; }
  .stat-mini {
    display: flex; flex-direction: column; align-items: center; gap: 4px;
    padding: 12px 24px; background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter); border-radius: 8px; min-width: 120px;
  }
  .stat-num { font-size: 24px; font-weight: 700; color: var(--el-color-primary); }
  .stat-label { font-size: 12px; color: var(--el-text-color-secondary); }
  .toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
  .toolbar-left { display: flex; gap: 8px; flex-wrap: wrap; }
  .toolbar-right { display: flex; gap: 8px; align-items: center; }
  .cat-name-cell { display: flex; align-items: center; gap: 8px; }
  .cat-icon { font-size: 18px; }
  .cat-icon-img { width: 24px; height: 24px; object-fit: cover; border-radius: 4px; }
  .cat-icon-placeholder { font-size: 16px; opacity: 0.5; }
  /* 图标输入行:图标选择器 + 图片选择器并排 */
  .icon-input-row {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
  }
  .cat-name { font-weight: 500; }
  .cat-desc-tag { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cat-slug { font-family: monospace; font-size: 12px; color: var(--el-text-color-secondary); }
  .form-tip { margin-left: 8px; font-size: 12px; color: var(--el-text-color-secondary); }
</style>
