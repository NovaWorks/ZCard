<!-- 商品列表 - 后台管理 (商品名/Product Name) -->
<template>
  <div class="product-page art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <!-- 搜索栏 -->
      <div class="search-bar">
        <ElForm :inline="true" :model="searchForm" @submit.prevent>
          <ElFormItem label="商品名 (Name)">
            <ElInput
              v-model="searchForm.keyword"
              placeholder="请输入商品名"
              clearable
              style="width: 220px"
              @keyup.enter="handleSearch"
            />
          </ElFormItem>
          <ElFormItem label="状态 (Status)">
            <ElSelect v-model="searchForm.status" placeholder="全部" clearable style="width: 140px">
              <ElOption label="上架 (On)" :value="1" />
              <ElOption label="下架 (Off)" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <ElButton type="primary" @click="handleSearch">搜索 (Search)</ElButton>
            <ElButton @click="handleReset">重置 (Reset)</ElButton>
          </ElFormItem>
        </ElForm>
      </div>

      <!-- 表格头部 -->
      <div class="table-header">
        <ElButton type="primary" @click="openCreate">新增商品 (New Product)</ElButton>
      </div>

      <!-- 表格 -->
      <ElTable v-loading="loading" :data="tableData" border stripe style="width: 100%">
        <ElTableColumn prop="id" label="ID" width="80" />
        <ElTableColumn label="封面 (Cover)" width="90" align="center">
          <template #default="{ row }">
            <ElImage
              v-if="row.cover"
              :src="row.cover"
              :preview-src-list="[row.cover]"
              preview-teleported
              fit="cover"
              style="width: 50px; height: 50px; border-radius: 4px"
            />
            <span v-else style="color: #c0c4cc">-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="name" label="商品名 (Name)" min-width="200" show-overflow-tooltip />
        <ElTableColumn label="分类 (Category)" width="140">
          <template #default="{ row }">
            {{ row.category?.name || '-' }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="价格 (Price)" width="120" align="right">
          <template #default="{ row }">
            ¥{{ formatPrice(row.price) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="库存 (Stock)" width="100" align="center">
          <template #default="{ row }">
            {{ row.stock ?? 0 }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="状态 (Status)" width="100" align="center">
          <template #default="{ row }">
            <ElTag :type="row.status ? 'success' : 'info'" effect="light">
              {{ row.status ? '上架' : '下架' }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="推荐 (Featured)" width="100" align="center">
          <template #default="{ row }">
            <ElTag :type="row.is_featured ? 'warning' : 'info'" effect="plain">
              {{ row.is_featured ? '推荐' : '-' }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="排序 (Sort)" width="90" align="center" prop="sort" />
        <ElTableColumn label="操作 (Actions)" width="160" fixed="right" align="center">
          <template #default="{ row }">
            <ElButton type="primary" link @click="openEdit(row)">编辑 (Edit)</ElButton>
            <ElButton type="danger" link @click="handleDelete(row)">删除 (Delete)</ElButton>
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
      width="820px"
      top="5vh"
      destroy-on-close
      @closed="resetForm"
    >
      <ElForm
        ref="formRef"
        :model="formData"
        :rules="formRules"
        label-width="140px"
        class="product-form"
      >
        <!-- 基本信息 -->
        <div class="section-title">基本信息 (Basic Info)</div>

        <ElFormItem label="商品名 (Name)" prop="name">
          <ElInput v-model="formData.name" placeholder="请输入商品名" maxlength="150" />
        </ElFormItem>

        <ElFormItem label="Slug">
          <ElInput
            v-model="formData.slug"
            placeholder="留空则自动生成 (Leave blank to auto-generate)"
            maxlength="150"
          />
        </ElFormItem>

        <ElFormItem label="分类 (Category)">
          <ElSelect
            v-model="formData.category_id"
            placeholder="请选择分类"
            clearable
            filterable
            style="width: 100%"
          >
            <ElOption
              v-for="cat in categories"
              :key="cat.id"
              :label="cat.name"
              :value="cat.id"
            />
          </ElSelect>
        </ElFormItem>

        <ElFormItem label="商品描述 (Description)">
          <ElInput
            v-model="formData.description"
            type="textarea"
            :rows="5"
            placeholder="请输入商品描述"
            maxlength="2000"
            show-word-limit
          />
        </ElFormItem>

        <!-- 价格与库存 -->
        <div class="section-title">价格与库存 (Pricing & Stock)</div>

        <ElFormItem label="价格(元) (Price)" prop="priceYuan">
          <ElInputNumber
            v-model="formData.priceYuan"
            :min="0"
            :precision="2"
            :step="1"
            controls-position="right"
            style="width: 220px"
          />
          <span class="form-hint">单位:元 (Unit: Yuan)</span>
        </ElFormItem>

        <ElFormItem label="会员价 (Member Price)">
          <ElInput
            v-model="memberPriceText"
            placeholder='JSON,如 {"level1":800} (Phase 3)'
            style="width: 100%"
          />
          <span class="form-hint">可选,第三阶段功能 (Optional, Phase 3)</span>
        </ElFormItem>

        <ElFormItem label="库存类型 (Stock Type)">
          <ElSelect v-model="formData.stock_type" style="width: 100%">
            <ElOption label="卡密 (Card)" value="card" />
            <ElOption label="链接 (URL)" value="url" />
            <ElOption label="兑换码 (Code)" value="code" />
          </ElSelect>
        </ElFormItem>

        <ElFormItem label="显示库存 (Show Stock)">
          <ElSwitch v-model="formData.stock_visible" />
          <span class="ml-2">{{ formData.stock_visible ? '显示' : '隐藏' }}</span>
        </ElFormItem>

        <!-- 图片 -->
        <div class="section-title">图片 (Images)</div>

        <ElFormItem label="封面图 (Cover)">
          <ElUpload
            :show-file-list="false"
            :http-request="handleCoverUpload"
            accept="image/*"
            :before-upload="beforeUpload"
          >
            <div v-if="formData.cover" class="cover-preview">
              <ElImage :src="formData.cover" fit="cover" class="cover-img" />
              <div class="cover-mask">
                <span>点击替换 (Replace)</span>
              </div>
            </div>
            <div v-else class="cover-placeholder">
              <ElIcon class="upload-icon"><Plus /></ElIcon>
              <span>点击上传 (Click to upload)</span>
            </div>
          </ElUpload>
          <ElButton v-if="formData.cover" link type="danger" class="ml-2" @click="formData.cover = ''">
            移除 (Remove)
          </ElButton>
        </ElFormItem>

        <ElFormItem label="详情图 (Gallery)">
          <ElUpload
            :file-list="galleryFileList"
            list-type="picture-card"
            :http-request="handleGalleryUpload"
            :on-remove="handleGalleryRemove"
            accept="image/*"
            :before-upload="beforeUpload"
            multiple
          >
            <ElIcon class="upload-icon"><Plus /></ElIcon>
          </ElUpload>
          <span class="form-hint">可上传多张 (Multiple allowed)</span>
        </ElFormItem>

        <!-- SKU 管理 -->
        <div v-if="dialogType === 'edit' && editId !== null" class="section-title">
          SKU 管理 (SKU Management)
          <ElButton type="primary" size="small" class="ml-2" @click="addSkuRow">
            新增 SKU (Add)
          </ElButton>
        </div>

        <ElFormItem
          v-if="dialogType === 'edit' && editId !== null"
          label-width="0"
          class="sku-form-item"
        >
          <ElTable :data="skuList" border size="small" style="width: 100%">
            <ElTableColumn label="名称 (Name)" min-width="140">
              <template #default="{ row }">
                <ElInput v-if="row._editing" v-model="row.name" placeholder="SKU 名称" size="small" />
                <span v-else>{{ row.name }}</span>
              </template>
            </ElTableColumn>
            <ElTableColumn label="价格(元) (Price)" width="130">
              <template #default="{ row }">
                <ElInputNumber
                  v-if="row._editing"
                  v-model="row.priceYuan"
                  :min="0"
                  :precision="2"
                  :step="1"
                  size="small"
                  controls-position="right"
                  style="width: 110px"
                />
                <span v-else>¥{{ formatPrice(row.price) }}</span>
              </template>
            </ElTableColumn>
            <ElTableColumn label="库存类型 (Stock Type)" width="140">
              <template #default="{ row }">
                <ElSelect v-if="row._editing" v-model="row.stock_type" size="small" style="width: 110px">
                  <ElOption label="卡密 (Card)" value="card" />
                  <ElOption label="链接 (URL)" value="url" />
                  <ElOption label="兑换码 (Code)" value="code" />
                </ElSelect>
                <span v-else>{{ stockTypeLabel(row.stock_type) }}</span>
              </template>
            </ElTableColumn>
            <ElTableColumn label="排序 (Sort)" width="110">
              <template #default="{ row }">
                <ElInputNumber
                  v-if="row._editing"
                  v-model="row.sort"
                  :min="0"
                  :step="1"
                  size="small"
                  controls-position="right"
                  style="width: 90px"
                />
                <span v-else>{{ row.sort ?? 0 }}</span>
              </template>
            </ElTableColumn>
            <ElTableColumn label="状态 (Status)" width="90" align="center">
              <template #default="{ row }">
                <ElSwitch v-if="row._editing" v-model="row.status" size="small" />
                <ElTag v-else :type="row.status ? 'success' : 'info'" effect="plain" size="small">
                  {{ row.status ? '启用' : '禁用' }}
                </ElTag>
              </template>
            </ElTableColumn>
            <ElTableColumn label="操作 (Actions)" width="170" align="center" fixed="right">
              <template #default="{ row, $index }">
                <template v-if="row._editing">
                  <ElButton type="primary" link size="small" @click="saveSkuRow(row)">
                    保存 (Save)
                  </ElButton>
                  <ElButton link size="small" @click="cancelSkuRow(row, $index)">
                    取消 (Cancel)
                  </ElButton>
                </template>
                <template v-else>
                  <ElButton type="primary" link size="small" @click="editSkuRow(row)">
                    编辑 (Edit)
                  </ElButton>
                  <ElButton type="danger" link size="small" @click="deleteSkuRow(row, $index)">
                    删除 (Delete)
                  </ElButton>
                </template>
              </template>
            </ElTableColumn>
          </ElTable>
          <div v-if="!skuList.length" class="sku-empty">
            暂无 SKU,点击右上角「新增 SKU」(No SKU yet, click "Add" above)
          </div>
        </ElFormItem>

        <ElFormItem
          v-else
          label="SKU 管理 (SKU)"
        >
          <ElAlert
            type="info"
            :closable="false"
            title="保存商品后即可管理 SKU (Save product first to manage SKUs)"
          />
        </ElFormItem>

        <!-- 设置 -->
        <div class="section-title">设置 (Settings)</div>

        <ElFormItem label="发放模式 (Delivery Mode)">
          <ElSelect v-model="formData.delivery_mode" style="width: 100%">
            <ElOption label="标记已发 (status=保留)" value="status" />
            <ElOption label="发货即删 (delete=物理删除)" value="delete" />
          </ElSelect>
        </ElFormItem>

        <ElFormItem label="推荐商品 (Featured)">
          <ElSwitch v-model="formData.is_featured" />
          <span class="ml-2">{{ formData.is_featured ? '推荐' : '普通' }}</span>
        </ElFormItem>

        <ElFormItem label="虚拟销量 (Virtual Sales)">
          <ElInputNumber
            v-model="formData.virtual_sales"
            :min="0"
            :step="1"
            controls-position="right"
            style="width: 220px"
          />
        </ElFormItem>

        <ElFormItem label="最小购买 (Min Order)">
          <ElInputNumber
            v-model="formData.min_order"
            :min="1"
            :step="1"
            controls-position="right"
            style="width: 220px"
          />
        </ElFormItem>

        <ElFormItem label="最大购买 (Max Order)">
          <ElInputNumber
            v-model="formData.max_order"
            :min="0"
            :step="1"
            controls-position="right"
            style="width: 220px"
          />
          <span class="form-hint">0 = 不限 (0 = unlimited)</span>
        </ElFormItem>

        <ElFormItem label="排序 (Sort)">
          <ElInputNumber
            v-model="formData.sort"
            :step="1"
            controls-position="right"
            style="width: 220px"
          />
        </ElFormItem>

        <ElFormItem label="状态 (Status)">
          <ElSwitch v-model="formData.status" :active-value="1" :inactive-value="0" />
          <span class="ml-2">{{ formData.status ? '上架' : '下架' }}</span>
        </ElFormItem>
      </ElForm>

      <template #footer>
        <ElButton @click="dialogVisible = false">取消 (Cancel)</ElButton>
        <ElButton type="primary" :loading="submitting" @click="handleSubmit">
          确定 (OK)
        </ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import type { FormInstance, FormRules, UploadFile, UploadRequestOptions } from 'element-plus'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { Plus } from '@element-plus/icons-vue'
  import {
    getProducts,
    createProduct,
    updateProduct,
    deleteProduct,
    type Product
  } from '@/api/products'
  import { uploadImage } from '@/api/upload'
  import {
    getSkus,
    createSku,
    updateSku,
    deleteSku,
    type Sku
  } from '@/api/sku'
  import request from '@/utils/http'

  defineOptions({ name: 'ProductList' })

  interface Category {
    id: number
    name: string
    children?: Category[]
  }

  /** 金额分 -> 元(两位小数) */
  const formatPrice = (fen: number): string => ((Number(fen) || 0) / 100).toFixed(2)

  /** 库存类型中文标签 */
  const stockTypeLabel = (t?: string): string => {
    switch (t) {
      case 'card':
        return '卡密 (Card)'
      case 'url':
        return '链接 (URL)'
      case 'code':
        return '兑换码 (Code)'
      default:
        return t || '-'
    }
  }

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
    } catch {
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
    } catch {
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

  const dialogTitle = computed(() =>
    dialogType.value === 'create' ? '新增商品 (New Product)' : '编辑商品 (Edit Product)'
  )

  interface ProductForm {
    name: string
    slug: string
    category_id: number | null
    description: string
    cover: string
    priceYuan: number
    stock_type: string
    stock_visible: boolean
    delivery_mode: string
    is_featured: boolean
    virtual_sales: number
    min_order: number
    max_order: number
    sort: number
    status: number
  }

  const createEmptyForm = (): ProductForm => ({
    name: '',
    slug: '',
    category_id: null,
    description: '',
    cover: '',
    priceYuan: 0,
    stock_type: 'card',
    stock_visible: true,
    delivery_mode: 'status',
    is_featured: false,
    virtual_sales: 0,
    min_order: 1,
    max_order: 0,
    sort: 0,
    status: 1
  })

  const formData = reactive<ProductForm>(createEmptyForm())
  const memberPriceText = ref('')

  const formRules: FormRules = {
    name: [{ required: true, message: '请输入商品名 (Name required)', trigger: 'blur' }],
    priceYuan: [{ required: true, message: '请输入价格 (Price required)', trigger: 'blur' }]
  }

  /** 上传前校验 */
  const beforeUpload = (file: File): boolean => {
    const isImage = file.type.startsWith('image/')
    const underLimit = file.size / 1024 / 1024 < 5
    if (!isImage) {
      ElMessage.error('只能上传图片 (Images only)')
      return false
    }
    if (!underLimit) {
      ElMessage.error('图片大小不能超过 5MB (Max 5MB)')
      return false
    }
    return true
  }

  /** 封面上传 */
  const handleCoverUpload = async (options: UploadRequestOptions) => {
    const file = options.file as File
    if (!beforeUpload(file)) return
    try {
      const res = await uploadImage(file)
      formData.cover = res.url
      ElMessage.success('封面上传成功 (Cover uploaded)')
    } catch {
      // 错误消息由 http 拦截器统一提示
    }
  }

  /** 详情图列表(Element Upload 用 file-list) */
  interface GalleryFile {
    name: string
    url: string
  }
  const galleryFileList = ref<GalleryFile[]>([])
  /** 详情图实际 URL 数组(提交时使用) */
  const galleryUrls = ref<string[]>([])

  /** 详情图上传 */
  const handleGalleryUpload = async (options: UploadRequestOptions) => {
    const file = options.file as File
    if (!beforeUpload(file)) return
    try {
      const res = await uploadImage(file)
      galleryUrls.value.push(res.url)
      galleryFileList.value.push({ name: res.path, url: res.url })
    } catch {
      // 错误消息由 http 拦截器统一提示
    }
  }

  /** 详情图移除 */
  const handleGalleryRemove = (file: UploadFile) => {
    const url = file.url || ''
    galleryUrls.value = galleryUrls.value.filter((u) => u !== url)
    galleryFileList.value = galleryFileList.value.filter((f) => f.url !== url)
  }

  /** SKU 列表编辑行 */
  interface SkuRow extends Sku {
    _editing: boolean
    _isNew: boolean
    priceYuan: number
  }

  const skuList = ref<SkuRow[]>([])

  /** 加载 SKU 列表 */
  const loadSkus = async (productId: number) => {
    try {
      const list = await getSkus(productId)
      skuList.value = (list || []).map((s) => ({
        ...s,
        status: !!s.status,
        priceYuan: Number(((Number(s.price) || 0) / 100).toFixed(2)),
        _editing: false,
        _isNew: false
      }))
    } catch {
      skuList.value = []
    }
  }

  /** 新增 SKU 行(内联编辑) */
  const addSkuRow = () => {
    skuList.value.push({
      product_id: editId.value ?? undefined,
      name: '',
      price: 0,
      priceYuan: 0,
      stock_type: formData.stock_type || 'card',
      sort: skuList.value.length,
      status: true,
      _editing: true,
      _isNew: true
    })
  }

  /** 编辑 SKU 行 */
  const editSkuRow = (row: SkuRow) => {
    row.priceYuan = Number(((Number(row.price) || 0) / 100).toFixed(2))
    row._editing = true
  }

  /** 取消 SKU 行编辑 */
  const cancelSkuRow = (row: SkuRow, index: number) => {
    if (row._isNew) {
      skuList.value.splice(index, 1)
    } else {
      row._editing = false
      row.priceYuan = Number(((Number(row.price) || 0) / 100).toFixed(2))
    }
  }

  /** 保存 SKU 行(新增/更新) */
  const saveSkuRow = async (row: SkuRow) => {
    if (!row.name?.trim()) {
      ElMessage.warning('请输入 SKU 名称 (Name required)')
      return
    }
    const payload: Sku = {
      product_id: row.product_id,
      name: row.name.trim(),
      price: Math.round((Number(row.priceYuan) || 0) * 100),
      stock_type: row.stock_type,
      sort: row.sort ?? 0,
      status: !!row.status
    }
    try {
      if (row._isNew || !row.id) {
        const created = await createSku(payload)
        Object.assign(row, created, {
          priceYuan: Number(((Number(created.price) || 0) / 100).toFixed(2)),
          status: !!created.status,
          _isNew: false,
          _editing: false
        })
        ElMessage.success('SKU 已新增 (SKU added)')
      } else {
        const updated = await updateSku(row.id, payload)
        Object.assign(row, updated, {
          priceYuan: Number(((Number(updated.price) || 0) / 100).toFixed(2)),
          status: !!updated.status,
          _editing: false
        })
        ElMessage.success('SKU 已更新 (SKU updated)')
      }
    } catch {
      // 错误消息由 http 拦截器统一提示
    }
  }

  /** 删除 SKU 行 */
  const deleteSkuRow = (row: SkuRow, index: number) => {
    ElMessageBox.confirm(`确定删除 SKU「${row.name}」吗? (Delete this SKU?)`, '删除 SKU', {
      confirmButtonText: '确定 (OK)',
      cancelButtonText: '取消 (Cancel)',
      type: 'warning'
    })
      .then(async () => {
        if (row.id) {
          try {
            await deleteSku(row.id)
            skuList.value.splice(index, 1)
            ElMessage.success('SKU 已删除 (SKU deleted)')
          } catch {
            // 错误消息由 http 拦截器统一提示
          }
        } else {
          skuList.value.splice(index, 1)
        }
      })
      .catch(() => {
        // 用户取消
      })
  }

  /** 打开新增弹窗 */
  const openCreate = () => {
    dialogType.value = 'create'
    editId.value = null
    Object.assign(formData, createEmptyForm())
    memberPriceText.value = ''
    galleryUrls.value = []
    galleryFileList.value = []
    skuList.value = []
    dialogVisible.value = true
  }

  /** 打开编辑弹窗 */
  const openEdit = async (row: Product) => {
    dialogType.value = 'edit'
    editId.value = row.id
    Object.assign(formData, createEmptyForm(), {
      name: row.name,
      slug: row.slug || '',
      category_id: row.category_id,
      description: row.description || '',
      cover: row.cover || '',
      priceYuan: Number(((Number(row.price) || 0) / 100).toFixed(2)),
      stock_type: row.stock_type || 'card',
      stock_visible: row.stock_visible !== false,
      delivery_mode: row.delivery_mode || 'status',
      is_featured: !!row.is_featured,
      virtual_sales: Number(row.virtual_sales) || 0,
      min_order: Number(row.min_order) || 1,
      max_order: Number(row.max_order) || 0,
      sort: Number(row.sort) || 0,
      status: row.status
    })
    // 会员价 JSON 文本回填
    if (row.member_price && typeof row.member_price === 'object') {
      memberPriceText.value = JSON.stringify(row.member_price)
    } else {
      memberPriceText.value = ''
    }
    // 详情图回填
    const imgs = Array.isArray(row.images) ? row.images : []
    galleryUrls.value = [...imgs]
    galleryFileList.value = imgs.map((url, i) => ({ name: `image-${i}`, url }))
    // SKU 列表
    dialogVisible.value = true
    await loadSkus(row.id)
  }

  /** 关闭弹窗后重置表单 */
  const resetForm = () => {
    formRef.value?.resetFields()
    Object.assign(formData, createEmptyForm())
    memberPriceText.value = ''
    galleryUrls.value = []
    galleryFileList.value = []
    skuList.value = []
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

    // 解析会员价 JSON(可选)
    let memberPrice: Record<string, number> | undefined
    const mpText = memberPriceText.value.trim()
    if (mpText) {
      try {
        const parsed = JSON.parse(mpText)
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
          memberPrice = parsed as Record<string, number>
        } else {
          ElMessage.error('会员价 JSON 格式不正确 (Invalid Member Price JSON)')
          return
        }
      } catch {
        ElMessage.error('会员价 JSON 格式不正确 (Invalid Member Price JSON)')
        return
      }
    }

    const payload: Record<string, unknown> = {
      name: formData.name,
      slug: formData.slug || undefined,
      category_id: formData.category_id,
      description: formData.description,
      cover: formData.cover || undefined,
      images: galleryUrls.value.length ? galleryUrls.value : undefined,
      price: Math.round(formData.priceYuan * 100),
      stock_type: formData.stock_type,
      stock_visible: formData.stock_visible,
      delivery_mode: formData.delivery_mode,
      is_featured: formData.is_featured,
      virtual_sales: formData.virtual_sales,
      min_order: formData.min_order,
      max_order: formData.max_order,
      sort: formData.sort,
      status: formData.status
    }
    if (memberPrice) payload.member_price = memberPrice

    submitting.value = true
    try {
      if (dialogType.value === 'create') {
        await createProduct(payload)
        ElMessage.success('新增成功 (Created)')
      } else if (editId.value !== null) {
        await updateProduct(editId.value, payload)
        ElMessage.success('更新成功 (Updated)')
      }
      dialogVisible.value = false
      fetchData()
    } catch {
      // 错误消息由 http 拦截器统一提示
    } finally {
      submitting.value = false
    }
  }

  /** 删除商品 */
  const handleDelete = (row: Product) => {
    ElMessageBox.confirm(`确定要删除商品「${row.name}」吗? (Delete product?)`, '删除商品', {
      confirmButtonText: '确定 (OK)',
      cancelButtonText: '取消 (Cancel)',
      type: 'warning'
    })
      .then(async () => {
        try {
          await deleteProduct(row.id)
          ElMessage.success('删除成功 (Deleted)')
          fetchData()
        } catch {
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

  .product-form {
    max-height: 70vh;
    overflow-y: auto;
    padding-right: 8px;
  }

  .section-title {
    font-weight: 600;
    font-size: 14px;
    color: var(--el-color-primary);
    margin: 8px 0 16px;
    padding-left: 8px;
    border-left: 3px solid var(--el-color-primary);
    display: flex;
    align-items: center;
  }

  .form-hint {
    margin-left: 8px;
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }

  .cover-placeholder,
  .cover-preview {
    width: 120px;
    height: 120px;
    border: 1px dashed var(--el-border-color);
    border-radius: 6px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--el-text-color-secondary);
    font-size: 12px;
    overflow: hidden;
    position: relative;

    .upload-icon {
      font-size: 24px;
      margin-bottom: 6px;
    }
  }

  .cover-preview {
    border-style: solid;
  }

  .cover-img {
    width: 100%;
    height: 100%;
  }

  .cover-mask {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.2s;
  }

  .cover-preview:hover .cover-mask {
    opacity: 1;
  }

  .sku-form-item {
    :deep(.el-form-item__content) {
      display: block;
    }
  }

  .sku-empty {
    text-align: center;
    color: var(--el-text-color-secondary);
    font-size: 13px;
    padding: 16px 0;
  }

  .ml-2 {
    margin-left: 8px;
  }
</style>
