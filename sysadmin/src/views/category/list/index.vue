<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getCategories, createCategory, updateCategory, deleteCategory } from '@/api/categories'
import type { Category } from '@/api/categories'

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
  ElMessageBox.confirm(`确认删除分类"${row.name}"？`, '提示', { type: 'warning' })
    .then(async () => {
      await deleteCategory(row.id)
      ElMessage.success('删除成功')
      loadData()
    })
    .catch(() => {})
}

const handleSubmit = async () => {
  if (!formData.value.name.trim()) {
    ElMessage.warning('请输入分类名称')
    return
  }
  try {
    if (isEdit.value) {
      await updateCategory(formData.value.id, formData.value)
      ElMessage.success('修改成功')
    } else {
      await createCategory(formData.value)
      ElMessage.success('新增成功')
    }
    dialogVisible.value = false
    loadData()
  } catch {
    ElMessage.error('操作失败')
  }
}

onMounted(loadData)
</script>

<template>
  <div class="app-container">
    <!-- 操作栏 -->
    <div class="mb-4 flex items-center justify-between">
      <el-input placeholder="搜索分类名称" style="width: 240px" clearable />
      <el-button type="primary" @click="handleAdd">
        <el-icon><Plus /></el-icon>
        新增分类
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
      <el-table-column prop="name" label="分类名称" min-width="200" />
      <el-table-column prop="slug" label="Slug" min-width="150" />
      <el-table-column prop="sort" label="排序" width="80" align="center" />
      <el-table-column label="状态" width="100" align="center">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
            {{ row.status === 1 ? '启用' : '禁用' }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="160" align="center" fixed="right">
        <template #default="{ row }">
          <el-button type="primary" link size="small" @click="handleEdit(row)">编辑</el-button>
          <el-button type="danger" link size="small" @click="handleDelete(row)">删除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <!-- 新增/编辑对话框 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑分类' : '新增分类'"
      width="480px"
    >
      <el-form :model="formData" label-width="80px">
        <el-form-item label="名称" required>
          <el-input v-model="formData.name" placeholder="请输入分类名称" />
        </el-form-item>
        <el-form-item label="Slug">
          <el-input v-model="formData.slug" placeholder="留空自动生成" />
        </el-form-item>
        <el-form-item label="父分类">
          <el-select v-model="formData.parent_id" placeholder="顶级分类" clearable style="width: 100%">
            <el-option
              v-for="c in flatCategories"
              :key="c.value"
              :label="c.label"
              :value="c.value"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="formData.sort" :min="0" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="formData.status" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>
