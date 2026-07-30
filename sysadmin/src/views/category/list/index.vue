<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import { getCategories, createCategory, updateCategory, deleteCategory } from '@/api/categories'
import type { Category } from '@/api/categories'

const { t } = useI18n()

const loading = ref(false)
const list = ref<Category[]>([])
const dialogVisible = ref(false)
const isEdit = ref(false)
const formData = ref({
  id: 0,
  name: '',
  slug: '',
  parent_id: null as number | null,
  sort: 0,
  status: true
})

// 扁平化分类供父分类选择
const flatCategories = ref<{ label: string; value: number }[]>([])
const flatten = (cats: Category[], depth = 0) => {
  const result: { label: string; value: number }[] = []
  cats.forEach(c => {
    result.push({ label: '— '.repeat(depth) + c.name, value: c.id })
    if (c.children?.length) result.push(...flatten(c.children, depth + 1))
  })
  return result
}

const loadData = async () => {
  loading.value = true
  try {
    const data = await getCategories()
    list.value = data
    flatCategories.value = flatten(data)
  } finally {
    loading.value = false
  }
}

const handleAdd = () => {
  isEdit.value = false
  formData.value = { id: 0, name: '', slug: '', parent_id: null, sort: 0, status: true }
  dialogVisible.value = true
}

const handleEdit = (row: Category) => {
  isEdit.value = true
  formData.value = {
    id: row.id,
    name: row.name,
    slug: row.slug,
    parent_id: row.parent_id,
    sort: row.sort,
    status: row.status === 1
  }
  dialogVisible.value = true
}

const handleDelete = (row: Category) => {
  ElMessageBox.confirm(t('zcard.category.deleteConfirm', { name: row.name }), t('zcard.common.tips'), { type: 'warning' })
    .then(async () => {
      await deleteCategory(row.id)
      ElMessage.success(t('zcard.common.deleteSuccess'))
      loadData()
    })
    .catch(() => {})
}

const handleSubmit = async () => {
  if (!formData.value.name.trim()) {
    ElMessage.warning(t('zcard.category.nameRequired'))
    return
  }
  try {
    if (isEdit.value) {
      await updateCategory(formData.value.id, formData.value)
      ElMessage.success(t('zcard.category.modified'))
    } else {
      await createCategory(formData.value)
      ElMessage.success(t('zcard.category.created'))
    }
    dialogVisible.value = false
    loadData()
  } catch {
    ElMessage.error(t('zcard.common.operationFailed'))
  }
}

onMounted(loadData)
</script>

<template>
  <div class="app-container">
    <!-- 操作栏 -->
    <div class="mb-4 flex items-center justify-between">
      <el-input :placeholder="t('zcard.category.searchPlaceholder')" style="width: 240px" clearable />
      <el-button type="primary" @click="handleAdd">
        <el-icon><Plus /></el-icon>
        {{ t('zcard.category.add') }}
      </el-button>
    </div>

    <!-- 树形表格 -->
    <el-table
      v-loading="loading"
      :data="list"
      row-key="id"
      border
      default-expand-all
      :tree-props="{ children: 'children' }"
    >
      <el-table-column prop="name" :label="t('zcard.category.name')" min-width="200" />
      <el-table-column prop="slug" :label="t('zcard.category.slug')" min-width="150" />
      <el-table-column prop="sort" :label="t('zcard.category.sort')" width="80" align="center" />
      <el-table-column :label="t('zcard.category.status')" width="100" align="center">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
            {{ row.status === 1 ? t('zcard.category.statusOn') : t('zcard.category.statusOff') }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column :label="t('zcard.common.actions')" width="160" align="center" fixed="right">
        <template #default="{ row }">
          <el-button type="primary" link size="small" @click="handleEdit(row)">{{ t('zcard.common.edit') }}</el-button>
          <el-button type="danger" link size="small" @click="handleDelete(row)">{{ t('zcard.common.delete') }}</el-button>
        </template>
      </el-table-column>
    </el-table>

    <!-- 新增/编辑对话框 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? t('zcard.category.edit') : t('zcard.category.add')"
      width="480px"
    >
      <el-form :model="formData" label-width="80px">
        <el-form-item :label="t('zcard.category.nameLabel')" required>
          <el-input v-model="formData.name" :placeholder="t('zcard.category.searchPlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('zcard.category.slug')">
          <el-input v-model="formData.slug" :placeholder="t('zcard.category.slugPlaceholder')" />
        </el-form-item>
        <el-form-item :label="t('zcard.category.parent')">
          <el-select v-model="formData.parent_id" :placeholder="t('zcard.category.parentPlaceholder')" clearable style="width: 100%">
            <el-option
              v-for="c in flatCategories"
              :key="c.value"
              :label="c.label"
              :value="c.value"
            />
          </el-select>
        </el-form-item>
        <el-form-item :label="t('zcard.category.sort')">
          <el-input-number v-model="formData.sort" :min="0" />
        </el-form-item>
        <el-form-item :label="t('zcard.category.status')">
          <el-switch v-model="formData.status" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">{{ t('zcard.common.cancel') }}</el-button>
        <el-button type="primary" @click="handleSubmit">{{ t('zcard.common.ok') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>
