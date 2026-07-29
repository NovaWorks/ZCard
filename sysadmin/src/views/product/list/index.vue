<!-- 商品列表 - 后台管理 -->
<template>
  <div class="product-page art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <!-- 搜索栏 -->
      <div class="search-bar">
        <ElForm :inline="true" :model="searchForm" @submit.prevent>
          <ElFormItem label="商品名">
            <ElInput
              v-model="searchForm.keyword"
              placeholder="请输入商品名"
              clearable
              style="width: 220px"
              @keyup.enter="handleSearch"
            />
          </ElFormItem>
          <ElFormItem label="状态">
            <ElSelect v-model="searchForm.status" placeholder="全部" clearable style="width: 140px">
              <ElOption label="上架" :value="1" />
              <ElOption label="下架" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <ElButton type="primary" @click="handleSearch">搜索</ElButton>
            <ElButton @click="handleReset">重置</ElButton>
          </ElFormItem>
        </ElForm>
      </div>

      <!-- 表格头部 -->
      <div class="table-header">
        <ElButton type="primary" @click="openCreate">新增商品</ElButton>
      </div>

      <!-- 表格 -->
      <ElTable v-loading="loading" :data="tableData" border stripe style="width: 100%">
        <ElTableColumn prop="id" label="ID" width="80" />
        <ElTableColumn prop="name" label="商品名" min-width="200" show-overflow-tooltip />
        <ElTableColumn label="分类" width="140">
          <template #default="{ row }">
            {{ row.category?.name || '-' }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="价格" width="120" align="right">
          <template #default="{ row }">
            ¥{{ formatPrice(row.price) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="库存" width="100" align="center">
          <template #default="{ row }">
            {{ row.stock ?? 0 }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="状态" width="90" align="center">
          <template #default="{ row }">
            <ElTag :type="row.status ? 'success' : 'info'" effect="light">
              {{ row.status ? '上架' : '下架' }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="推荐" width="90" align="center">
          <template #default="{ row }">
            <ElTag :type="row.is_featured ? 'warning' : 'info'" effect="plain">
              {{ row.is_featured ? '推荐' : '-' }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" width="160" fixed="right" align="center">
          <template #default="{ row }">
            <ElButton type="primary" link @click="openEdit(row)">编辑</ElButton>
            <ElButton type="danger" link @click="handleDelete(row)">删除</ElButton>
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
    </ElCard>

    <!-- 新增/编辑弹窗 -->
    <ElDialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="560px"
      destroy-on-close
      @closed="resetForm"
    >
      <ElForm ref="formRef" :model="formData" :rules="formRules" label-width="100px">
        <ElFormItem label="商品名" prop="name">
          <ElInput v-model="formData.name" placeholder="请输入商品名" maxlength="150" />
        </ElFormItem>
        <ElFormItem label="价格(元)" prop="priceYuan">
          <ElInputNumber
            v-model="formData.priceYuan"
            :min="0"
            :precision="2"
            :step="1"
            controls-position="right"
            style="width: 200px"
          />
        </ElFormItem>
        <ElFormItem label="分类">
          <ElSelect v-model="formData.category_id" placeholder="请选择分类" clearable style="width: 100%">
            <ElOption
              v-for="cat in categories"
              :key="cat.id"
              :label="cat.name"
              :value="cat.id"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="库存类型">
          <ElSelect v-model="formData.stock_type" style="width: 100%">
            <ElOption label="卡密" value="card" />
            <ElOption label="链接" value="url" />
            <ElOption label="兑换码" value="code" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="发货方式">
          <ElSelect v-model="formData.delivery_mode" style="width: 100%">
            <ElOption label="标记已发" value="status" />
            <ElOption label="发货即删" value="delete" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="状态">
          <ElSwitch v-model="formData.status" :active-value="1" :inactive-value="0" />
          <span class="ml-2">{{ formData.status ? '上架' : '下架' }}</span>
        </ElFormItem>
        <ElFormItem label="推荐">
          <ElSwitch v-model="formData.is_featured" />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="dialogVisible = false">取消</ElButton>
        <ElButton type="primary" :loading="submitting" @click="handleSubmit">确定</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import type { FormInstance, FormRules } from 'element-plus'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import {
    getProducts,
    createProduct,
    updateProduct,
    deleteProduct,
    type Product
  } from '@/api/products'
  import request from '@/utils/http'

  defineOptions({ name: 'ProductList' })

  interface Category {
    id: number
    name: string
    children?: Category[]
  }

  /** 金额分 -> 元(两位小数) */
  const formatPrice = (fen: number): string => ((Number(fen) || 0) / 100).toFixed(2)

  /** 列表/分页状态 */
  const loading = ref(false)
  const tableData = ref<Product[]>([])
  const pagination = reactive({
    page: 1,
    pageSize: 15,
    total: 0
  })

  /** 搜索表单 */
  const searchForm = reactive<{ keyword?: string; status?: number }>({
    keyword: undefined,
    status: undefined
  })

  /** 分类列表(扁平化后供下拉使用) */
  const categories = ref<Category[]>([])

  /** 扁平化分类树 */
  const flattenCategories = (tree: Category[]): Category[] => {
    const result: Category[] = []
    const walk = (nodes: Category[]) => {
      nodes.forEach((node) => {
        result.push({ id: node.id, name: node.name })
        if (node.children?.length) walk(node.children)
      })
    }
    walk(tree)
    return result
  }

  /** 加载分类列表 */
  const loadCategories = async () => {
    try {
      const tree = await request.get<Category[]>({ url: '/categories' })
      categories.value = flattenCategories(tree || [])
    } catch (e) {
      // 分类加载失败不阻塞商品管理
      categories.value = []
    }
  }

  /** 拉取商品列表 */
  const fetchData = async () => {
    loading.value = true
    try {
      const res = await getProducts({
        page: pagination.page,
        pageSize: pagination.pageSize,
        keyword: searchForm.keyword,
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

  /** 搜索 */
  const handleSearch = () => {
    pagination.page = 1
    fetchData()
  }

  /** 重置搜索 */
  const handleReset = () => {
    searchForm.keyword = undefined
    searchForm.status = undefined
    pagination.page = 1
    fetchData()
  }

  /** 弹窗相关 */
  const dialogVisible = ref(false)
  const dialogType = ref<'create' | 'edit'>('create')
  const submitting = ref(false)
  const editId = ref<number | null>(null)
  const formRef = ref<FormInstance>()

  const dialogTitle = computed(() => (dialogType.value === 'create' ? '新增商品' : '编辑商品'))

  interface ProductForm {
    name: string
    priceYuan: number
    category_id: number | null
    stock_type: string
    delivery_mode: string
    status: number
    is_featured: boolean
  }

  const createEmptyForm = (): ProductForm => ({
    name: '',
    priceYuan: 0,
    category_id: null,
    stock_type: 'card',
    delivery_mode: 'status',
    status: 1,
    is_featured: false
  })

  const formData = reactive<ProductForm>(createEmptyForm())

  const formRules: FormRules = {
    name: [{ required: true, message: '请输入商品名', trigger: 'blur' }],
    priceYuan: [{ required: true, message: '请输入价格', trigger: 'blur' }]
  }

  /** 打开新增弹窗 */
  const openCreate = () => {
    dialogType.value = 'create'
    editId.value = null
    Object.assign(formData, createEmptyForm())
    dialogVisible.value = true
  }

  /** 打开编辑弹窗 */
  const openEdit = (row: Product) => {
    dialogType.value = 'edit'
    editId.value = row.id
    Object.assign(formData, createEmptyForm(), {
      name: row.name,
      priceYuan: Number(((Number(row.price) || 0) / 100).toFixed(2)),
      category_id: row.category_id,
      stock_type: row.stock_type || 'card',
      delivery_mode: row.delivery_mode || 'status',
      status: row.status,
      is_featured: !!row.is_featured
    })
    dialogVisible.value = true
  }

  /** 关闭弹窗后重置表单 */
  const resetForm = () => {
    formRef.value?.resetFields()
    Object.assign(formData, createEmptyForm())
    editId.value = null
  }

  /** 提交表单(新增/编辑) */
  const handleSubmit = async () => {
    if (!formRef.value) return
    try {
      await formRef.value.validate()
    } catch {
      return
    }

    const payload = {
      name: formData.name,
      price: Math.round(formData.priceYuan * 100),
      category_id: formData.category_id,
      stock_type: formData.stock_type,
      delivery_mode: formData.delivery_mode,
      status: formData.status,
      is_featured: formData.is_featured
    }

    submitting.value = true
    try {
      if (dialogType.value === 'create') {
        await createProduct(payload)
        ElMessage.success('新增成功')
      } else if (editId.value !== null) {
        await updateProduct(editId.value, payload)
        ElMessage.success('更新成功')
      }
      dialogVisible.value = false
      fetchData()
    } catch (e) {
      // 错误消息由 http 拦截器统一提示
    } finally {
      submitting.value = false
    }
  }

  /** 删除商品 */
  const handleDelete = (row: Product) => {
    ElMessageBox.confirm(`确定要删除商品「${row.name}」吗？`, '删除商品', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning'
    })
      .then(async () => {
        try {
          await deleteProduct(row.id)
          ElMessage.success('删除成功')
          fetchData()
        } catch (e) {
          // 错误消息由 http 拦截器统一提示
        }
      })
      .catch(() => {
        // 用户取消
      })
  }

  onMounted(() => {
    loadCategories()
    fetchData()
  })
</script>

<style lang="scss" scoped>
  .product-page {
    display: flex;
    flex-direction: column;
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
</style>
