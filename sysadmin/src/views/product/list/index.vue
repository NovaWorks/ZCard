<!-- 商品列表 - 后台管理 -->
<template>
  <div class="product-page art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <!-- 搜索栏 -->
      <div class="search-bar">
        <ElForm :inline="true" :model="searchForm" @submit.prevent>
          <ElFormItem :label="t('zcard.product.name')">
            <ElInput
              v-model="searchForm.keyword"
              :placeholder="t('zcard.product.searchPlaceholder')"
              clearable
              style="width: 220px"
              @keyup.enter="handleSearch"
            />
          </ElFormItem>
          <ElFormItem :label="t('zcard.product.status')">
            <ElSelect v-model="searchForm.status" :placeholder="t('zcard.product.all')" clearable style="width: 140px">
              <ElOption :label="t('zcard.product.statusOn')" :value="1" />
              <ElOption :label="t('zcard.product.statusOff')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <ElButton type="primary" @click="handleSearch">{{ t('zcard.common.search') }}</ElButton>
            <ElButton @click="handleReset">{{ t('zcard.common.reset') }}</ElButton>
          </ElFormItem>
        </ElForm>
      </div>

      <!-- 表格头部 -->
      <div class="table-header">
        <ElButton type="primary" @click="openCreate">{{ t('zcard.product.add') }}</ElButton>
      </div>

      <!-- 表格 -->
      <ElTable v-loading="loading" :data="tableData" border stripe style="width: 100%">
        <ElTableColumn prop="id" :label="t('zcard.common.id')" width="80" />
        <ElTableColumn :label="t('zcard.product.cover')" width="90" align="center">
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
        <ElTableColumn prop="name" :label="t('zcard.product.name')" min-width="200" show-overflow-tooltip />
        <ElTableColumn :label="t('zcard.product.category')" width="140">
          <template #default="{ row }">
            {{ row.category?.name || '-' }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.product.priceShort')" width="120" align="right">
          <template #default="{ row }">
            ¥{{ formatPrice(row.price) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.product.stock')" width="100" align="center">
          <template #default="{ row }">
            {{ row.stock ?? 0 }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.product.status')" width="100" align="center">
          <template #default="{ row }">
            <ElTag :type="row.status ? 'success' : 'info'" effect="light">
              {{ row.status ? t('zcard.product.statusOn') : t('zcard.product.statusOff') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.product.isFeatured')" width="100" align="center">
          <template #default="{ row }">
            <ElTag :type="row.is_featured ? 'warning' : 'info'" effect="plain">
              {{ row.is_featured ? t('zcard.product.featured') : '-' }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.product.sort')" width="90" align="center" prop="sort" />
        <ElTableColumn :label="t('zcard.common.actions')" width="160" fixed="right" align="center">
          <template #default="{ row }">
            <ElButton type="primary" link @click="openEdit(row)">{{ t('zcard.common.edit') }}</ElButton>
            <ElButton type="danger" link @click="handleDelete(row)">{{ t('zcard.common.delete') }}</ElButton>
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
        <div class="section-title">{{ t('zcard.product.basicInfo') }}</div>

        <ElFormItem :label="t('zcard.product.name')" prop="name">
          <ElInput v-model="formData.name" :placeholder="t('zcard.product.searchPlaceholder')" maxlength="150" />
        </ElFormItem>

        <ElFormItem :label="t('zcard.product.slug')">
          <ElInput
            v-model="formData.slug"
            :placeholder="t('zcard.product.slugPlaceholder')"
            maxlength="150"
          />
        </ElFormItem>

        <ElFormItem :label="t('zcard.product.category')">
          <ElSelect
            v-model="formData.category_id"
            :placeholder="t('zcard.product.category')"
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

        <ElFormItem :label="t('zcard.product.description')">
          <ElInput
            v-model="formData.description"
            type="textarea"
            :rows="5"
            :placeholder="t('zcard.product.description')"
            maxlength="2000"
            show-word-limit
          />
        </ElFormItem>

        <!-- 价格与库存 -->
        <div class="section-title">{{ t('zcard.product.pricing') }}</div>

        <ElFormItem :label="t('zcard.product.price')" prop="priceYuan">
          <ElInputNumber
            v-model="formData.priceYuan"
            :min="0"
            :precision="2"
            :step="1"
            controls-position="right"
            style="width: 220px"
          />
          <span class="form-hint">{{ t('zcard.product.priceUnit') }}</span>
        </ElFormItem>

        <ElFormItem :label="t('zcard.product.memberPrice')">
          <ElInput
            v-model="memberPriceText"
            :placeholder="t('zcard.product.memberPricePlaceholder')"
            style="width: 100%"
          />
          <span class="form-hint">{{ t('zcard.product.memberPriceOptional') }}</span>
        </ElFormItem>

        <ElFormItem :label="t('zcard.product.stockType')">
          <ElSelect v-model="formData.stock_type" style="width: 100%">
            <ElOption :label="t('zcard.product.stockCard')" value="card" />
            <ElOption :label="t('zcard.product.stockUrl')" value="url" />
            <ElOption :label="t('zcard.product.stockCode')" value="code" />
          </ElSelect>
        </ElFormItem>

        <ElFormItem :label="t('zcard.product.stockVisible')">
          <ElSwitch v-model="formData.stock_visible" />
          <span class="ml-2">{{ formData.stock_visible ? t('zcard.product.show') : t('zcard.product.hide') }}</span>
        </ElFormItem>

        <!-- 图片 -->
        <div class="section-title">{{ t('zcard.product.imagesSection') }}</div>

        <ElFormItem :label="t('zcard.product.cover')">
          <ElUpload
            :show-file-list="false"
            :http-request="handleCoverUpload"
            accept="image/*"
            :before-upload="beforeUpload"
          >
            <div v-if="formData.cover" class="cover-preview">
              <ElImage :src="formData.cover" fit="cover" class="cover-img" />
              <div class="cover-mask">
                <span>{{ t('zcard.product.coverReplace') }}</span>
              </div>
            </div>
            <div v-else class="cover-placeholder">
              <ElIcon class="upload-icon"><Plus /></ElIcon>
              <span>{{ t('zcard.product.coverUpload') }}</span>
            </div>
          </ElUpload>
          <ElButton v-if="formData.cover" link type="danger" class="ml-2" @click="formData.cover = ''">
            {{ t('zcard.product.coverRemove') }}
          </ElButton>
        </ElFormItem>

        <ElFormItem :label="t('zcard.product.images')">
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
          <span class="form-hint">{{ t('zcard.product.galleryHint') }}</span>
        </ElFormItem>

        <!-- SKU 管理 -->
        <div v-if="dialogType === 'edit' && editId !== null" class="section-title">
          {{ t('zcard.product.skuManage') }}
          <ElButton type="primary" size="small" class="ml-2" @click="addSkuRow">
            {{ t('zcard.product.skuAdd') }}
          </ElButton>
        </div>

        <ElFormItem
          v-if="dialogType === 'edit' && editId !== null"
          label-width="0"
          class="sku-form-item"
        >
          <ElTable :data="skuList" border size="small" style="width: 100%">
            <ElTableColumn :label="t('zcard.product.name')" min-width="140">
              <template #default="{ row }">
                <ElInput v-if="row._editing" v-model="row.name" :placeholder="t('zcard.product.skuNamePlaceholder')" size="small" />
                <span v-else>{{ row.name }}</span>
              </template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.product.price')" width="130">
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
            <ElTableColumn :label="t('zcard.product.stockType')" width="140">
              <template #default="{ row }">
                <ElSelect v-if="row._editing" v-model="row.stock_type" size="small" style="width: 110px">
                  <ElOption :label="t('zcard.product.stockCard')" value="card" />
                  <ElOption :label="t('zcard.product.stockUrl')" value="url" />
                  <ElOption :label="t('zcard.product.stockCode')" value="code" />
                </ElSelect>
                <span v-else>{{ stockTypeLabel(row.stock_type) }}</span>
              </template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.product.sort')" width="110">
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
            <ElTableColumn :label="t('zcard.product.status')" width="90" align="center">
              <template #default="{ row }">
                <ElSwitch v-if="row._editing" v-model="row.status" size="small" />
                <ElTag v-else :type="row.status ? 'success' : 'info'" effect="plain" size="small">
                  {{ row.status ? t('zcard.category.statusOn') : t('zcard.category.statusOff') }}
                </ElTag>
              </template>
            </ElTableColumn>
            <ElTableColumn :label="t('zcard.common.actions')" width="170" align="center" fixed="right">
              <template #default="{ row, $index }">
                <template v-if="row._editing">
                  <ElButton type="primary" link size="small" @click="saveSkuRow(row)">
                    {{ t('zcard.common.save') }}
                  </ElButton>
                  <ElButton link size="small" @click="cancelSkuRow(row, $index)">
                    {{ t('zcard.common.cancel') }}
                  </ElButton>
                </template>
                <template v-else>
                  <ElButton type="primary" link size="small" @click="editSkuRow(row)">
                    {{ t('zcard.common.edit') }}
                  </ElButton>
                  <ElButton type="danger" link size="small" @click="deleteSkuRow(row, $index)">
                    {{ t('zcard.common.delete') }}
                  </ElButton>
                </template>
              </template>
            </ElTableColumn>
          </ElTable>
          <div v-if="!skuList.length" class="sku-empty">
            {{ t('zcard.product.skuEmpty') }}
          </div>
        </ElFormItem>

        <ElFormItem
          v-else
          :label="t('zcard.product.skuSection')"
        >
          <ElAlert
            type="info"
            :closable="false"
            :title="t('zcard.product.skuSaveFirst')"
          />
        </ElFormItem>

        <!-- 设置 -->
        <div class="section-title">{{ t('zcard.product.settings') }}</div>

        <ElFormItem :label="t('zcard.product.deliveryMode')">
          <ElSelect v-model="formData.delivery_mode" style="width: 100%">
            <ElOption :label="t('zcard.product.deliveryStatus')" value="status" />
            <ElOption :label="t('zcard.product.deliveryDelete')" value="delete" />
          </ElSelect>
        </ElFormItem>

        <ElFormItem :label="t('zcard.product.isFeatured')">
          <ElSwitch v-model="formData.is_featured" />
          <span class="ml-2">{{ formData.is_featured ? t('zcard.product.featured') : t('zcard.product.featuredPlain') }}</span>
        </ElFormItem>

        <ElFormItem :label="t('zcard.product.virtualSales')">
          <ElInputNumber
            v-model="formData.virtual_sales"
            :min="0"
            :step="1"
            controls-position="right"
            style="width: 220px"
          />
        </ElFormItem>

        <ElFormItem :label="t('zcard.product.minOrder')">
          <ElInputNumber
            v-model="formData.min_order"
            :min="1"
            :step="1"
            controls-position="right"
            style="width: 220px"
          />
        </ElFormItem>

        <ElFormItem :label="t('zcard.product.maxOrder')">
          <ElInputNumber
            v-model="formData.max_order"
            :min="0"
            :step="1"
            controls-position="right"
            style="width: 220px"
          />
          <span class="form-hint">{{ t('zcard.product.maxOrderHint') }}</span>
        </ElFormItem>

        <ElFormItem :label="t('zcard.product.sort')">
          <ElInputNumber
            v-model="formData.sort"
            :step="1"
            controls-position="right"
            style="width: 220px"
          />
        </ElFormItem>

        <ElFormItem :label="t('zcard.product.status')">
          <ElSwitch v-model="formData.status" :active-value="1" :inactive-value="0" />
          <span class="ml-2">{{ formData.status ? t('zcard.product.statusOn') : t('zcard.product.statusOff') }}</span>
        </ElFormItem>
      </ElForm>

      <template #footer>
        <ElButton @click="dialogVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="submitting" @click="handleSubmit">
          {{ t('zcard.common.ok') }}
        </ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import type { FormInstance, FormRules, UploadFile, UploadRequestOptions } from 'element-plus'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { Plus } from '@element-plus/icons-vue'
  import { useI18n } from 'vue-i18n'
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

  const { t } = useI18n()

  interface Category {
    id: number
    name: string
    children?: Category[]
  }

  /** 金额分 -> 元(两位小数) */
  const formatPrice = (fen: number): string => ((Number(fen) || 0) / 100).toFixed(2)

  /** 库存类型标签 */
  const stockTypeLabel = (type?: string): string => {
    switch (type) {
      case 'card':
        return t('zcard.product.stockCard')
      case 'url':
        return t('zcard.product.stockUrl')
      case 'code':
        return t('zcard.product.stockCode')
      default:
        return type || '-'
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
    dialogType.value === 'create' ? t('zcard.product.add') : t('zcard.product.edit')
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

  const formRules = computed<FormRules>(() => ({
    name: [{ required: true, message: t('zcard.product.nameRequired'), trigger: 'blur' }],
    priceYuan: [{ required: true, message: t('zcard.product.priceRequired'), trigger: 'blur' }]
  }))

  /** 上传前校验 */
  const beforeUpload = (file: File): boolean => {
    const isImage = file.type.startsWith('image/')
    const underLimit = file.size / 1024 / 1024 < 5
    if (!isImage) {
      ElMessage.error(t('zcard.product.uploadImageOnly'))
      return false
    }
    if (!underLimit) {
      ElMessage.error(t('zcard.product.uploadMaxSize'))
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
      ElMessage.success(t('zcard.product.coverUploaded'))
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
      ElMessage.warning(t('zcard.product.skuNameRequired'))
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
        ElMessage.success(t('zcard.product.skuAdded'))
      } else {
        const updated = await updateSku(row.id, payload)
        Object.assign(row, updated, {
          priceYuan: Number(((Number(updated.price) || 0) / 100).toFixed(2)),
          status: !!updated.status,
          _editing: false
        })
        ElMessage.success(t('zcard.product.skuUpdated'))
      }
    } catch {
      // 错误消息由 http 拦截器统一提示
    }
  }

  /** 删除 SKU 行 */
  const deleteSkuRow = (row: SkuRow, index: number) => {
    ElMessageBox.confirm(t('zcard.product.deleteSkuConfirm', { name: row.name }), t('zcard.product.deleteSkuTitle'), {
      confirmButtonText: t('zcard.common.ok'),
      cancelButtonText: t('zcard.common.cancel'),
      type: 'warning'
    })
      .then(async () => {
        if (row.id) {
          try {
            await deleteSku(row.id)
            skuList.value.splice(index, 1)
            ElMessage.success(t('zcard.product.skuDeleted'))
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
          ElMessage.error(t('zcard.product.memberPriceInvalid'))
          return
        }
      } catch {
        ElMessage.error(t('zcard.product.memberPriceInvalid'))
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
        ElMessage.success(t('zcard.product.created'))
      } else if (editId.value !== null) {
        await updateProduct(editId.value, payload)
        ElMessage.success(t('zcard.product.updated'))
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
    ElMessageBox.confirm(t('zcard.product.deleteConfirm', { name: row.name }), t('zcard.product.deleteTitle'), {
      confirmButtonText: t('zcard.common.ok'),
      cancelButtonText: t('zcard.common.cancel'),
      type: 'warning'
    })
      .then(async () => {
        try {
          await deleteProduct(row.id)
          ElMessage.success(t('zcard.common.deleteSuccess'))
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
