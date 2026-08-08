<script setup lang="ts">
  /**
   * 素材管理器 - 素材库核心视图(不含外层 Dialog)。
   * 供两种场景复用:
   *   1. 素材管理页面(selectionMode=false): 完整管理能力
   *   2. 素材选择弹窗(selectionMode='single'|'multiple'): 选择后返回 url
   * 数据源: /api/admin/media* 与 /api/admin/media-categories*
   */
  import { computed, ref, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import {
    getMediaCategories,
    createMediaCategory,
    renameMediaCategory,
    deleteMediaCategory,
    moveMediaCategory,
    getMediaList,
    uploadMediaFiles,
    deleteMediaFile,
    batchDeleteMedia,
    batchMoveMedia,
    type MediaItem,
    type MediaCategorySummary
  } from '@/api/media'

  const props = withDefaults(
    defineProps<{
      /** 选择模式: false=管理, 'single'=单选, 'multiple'=多选 */
      selectionMode?: boolean | 'single' | 'multiple'
    }>(),
    {
      selectionMode: false
    }
  )

  const emit = defineEmits<{
    (e: 'confirm', value: { url: string; id: number }[]): void
    (e: 'cancel'): void
  }>()

  const { t } = useI18n()

  // ===== 分类 =====
  const categorySummary = ref<MediaCategorySummary>({ categories: [], uncategorized: 0, total: 0 })
  const activeCategory = ref<number | 'all' | 'uncategorized'>('all')

  // 分类新增/改名
  const categoryDialogVisible = ref(false)
  const categoryDialogMode = ref<'create' | 'rename'>('create')
  const categoryDialogId = ref<number>(0)
  const categoryName = ref('')
  const categorySaving = ref(false)

  // 分类删除迁移
  const moveDialogVisible = ref(false)
  const moveSourceId = ref<number>(0)
  const moveTargetId = ref<number | ''>('')

  // ===== 素材列表 =====
  const loading = ref(false)
  const mediaList = ref<MediaItem[]>([])
  const total = ref(0)
  const page = ref(1)
  const perPage = ref(24)
  const keyword = ref('')
  const sortField = ref<'created_at' | 'filename' | 'size'>('created_at')
  const sortOrder = ref<'desc' | 'asc'>('desc')

  // 选择
  const selectedIds = ref<number[]>([])
  const selection = ref<{ url: string; id: number }[]>([])
  const selecting = computed(
    () => props.selectionMode === 'single' || props.selectionMode === 'multiple'
  )
  const multiple = computed(() => props.selectionMode === 'multiple')

  // 上传
  const uploadDialogVisible = ref(false)
  const uploadFiles = ref<File[]>([])
  const uploadProgress = ref(0)
  const uploading = ref(false)

  // 预览
  const previewVisible = ref(false)
  const previewIndex = ref(0)
  const previewSrcList = computed(() => mediaList.value.map((m) => m.url))

  // 移动分类(单张/批量)
  const moveTargetDialogVisible = ref(false)
  const moveTargetIds = ref<number[]>([])
  const moveTargetCategoryId = ref<number | ''>('')

  // 文件选择器引用
  const fileInput = ref<HTMLInputElement | null>(null)

  // ===== 分类加载 =====
  const loadCategories = async () => {
    try {
      categorySummary.value = await getMediaCategories()
    } catch {
      categorySummary.value = { categories: [], uncategorized: 0, total: 0 }
    }
  }

  // ===== 素材列表加载 =====
  const loadMedia = async () => {
    loading.value = true
    try {
      const params: any = {
        page: page.value,
        per_page: perPage.value,
        sort: sortField.value,
        order: sortOrder.value
      }
      if (keyword.value) params.keyword = keyword.value
      if (activeCategory.value === 'uncategorized') params.uncategorized = true
      else if (activeCategory.value && activeCategory.value !== 'all')
        params.category_id = activeCategory.value

      const data = await getMediaList(params)
      mediaList.value = data?.data || []
      total.value = data?.total || 0
      if (!selecting.value) {
        selectedIds.value = []
        selection.value = []
      }
    } catch {
      mediaList.value = []
    } finally {
      loading.value = false
    }
  }

  const refresh = () => {
    loadCategories()
    loadMedia()
  }

  const handleSearch = () => {
    page.value = 1
    loadMedia()
  }

  // 防抖搜索
  let searchTimer: ReturnType<typeof setTimeout> | null = null
  watch(keyword, () => {
    if (searchTimer) clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
      page.value = 1
      loadMedia()
    }, 300)
  })

  const switchCategory = (cat: number | 'all' | 'uncategorized') => {
    activeCategory.value = cat
    page.value = 1
    selectedIds.value = []
    selection.value = []
    loadMedia()
  }

  // 排序切换
  const handleSortChange = () => {
    page.value = 1
    loadMedia()
  }

  // ===== 分类操作 =====
  const openCreateCategory = () => {
    categoryDialogMode.value = 'create'
    categoryDialogId.value = 0
    categoryName.value = ''
    categoryDialogVisible.value = true
  }

  const openRenameCategory = (id: number, name: string) => {
    categoryDialogMode.value = 'rename'
    categoryDialogId.value = id
    categoryName.value = name
    categoryDialogVisible.value = true
  }

  const submitCategory = async () => {
    const name = categoryName.value.trim()
    if (!name) {
      ElMessage.warning(t('zcard.media.categoryNameRequired'))
      return
    }
    if (name.length > 30) {
      ElMessage.warning(t('zcard.media.categoryNameMax'))
      return
    }
    categorySaving.value = true
    try {
      if (categoryDialogMode.value === 'create') {
        await createMediaCategory(name)
        ElMessage.success(t('zcard.media.categoryCreated'))
      } else {
        await renameMediaCategory(categoryDialogId.value, name)
        ElMessage.success(t('zcard.media.categoryRenamed'))
      }
      categoryDialogVisible.value = false
      await loadCategories()
      loadMedia()
    } catch {
      // 错误提示由 HTTP 封装统一处理
    } finally {
      categorySaving.value = false
    }
  }

  const handleDeleteCategory = async (id: number) => {
    try {
      await deleteMediaCategory(id)
      ElMessage.success(t('zcard.media.categoryDeleted'))
      if (activeCategory.value === id) activeCategory.value = 'all'
      await loadCategories()
      loadMedia()
    } catch (err: any) {
      // 分类下有图片 → 后端 422,弹出迁移对话框
      if (err?.code === 422 || err?.response?.status === 422) {
        moveSourceId.value = id
        moveTargetId.value = ''
        moveDialogVisible.value = true
      }
    }
  }

  const confirmMoveCategory = async () => {
    try {
      await moveMediaCategory(
        moveSourceId.value,
        moveTargetId.value === '' ? null : moveTargetId.value
      )
      ElMessage.success(t('zcard.media.categoryMoved'))
      moveDialogVisible.value = false
      await loadCategories()
      loadMedia()
    } catch {
      // 统一错误提示
    }
  }

  // ===== 选择 =====
  const toggleSelect = (item: MediaItem) => {
    // 选择模式(单选/多选)与管理模式统一:点击切换选中,点底部「确定」返回。
    // 单选模式点击即 emit confirm 会在事件处理中同步关闭弹窗并销毁本组件,
    // 某些场景(组件卸载与事件冒泡竞态)会导致页面闪退,故改为点选后手动确定。
    const idx = selectedIds.value.indexOf(item.id)
    if (idx >= 0) {
      selectedIds.value.splice(idx, 1)
      selection.value = selection.value.filter((s) => s.id !== item.id)
    } else {
      // 单选模式:切换时只保留当前选中
      if (selecting.value && !multiple.value) {
        selectedIds.value = [item.id]
        selection.value = [{ url: item.url, id: item.id }]
      } else {
        selectedIds.value.push(item.id)
        selection.value.push({ url: item.url, id: item.id })
      }
    }
  }

  const isSelected = (id: number) => selectedIds.value.includes(id)

  const handleConfirm = () => {
    emit('confirm', selection.value)
  }

  // ===== 上传 =====
  const openFilePicker = () => {
    fileInput.value?.click()
  }

  const onFileInputChange = (event: Event) => {
    const input = event.target as HTMLInputElement
    if (input.files?.length) {
      uploadFiles.value = Array.from(input.files)
      uploadDialogVisible.value = true
    }
    // 允许重复选择同一文件
    input.value = ''
  }

  /** el-upload 文件变化(选中/删除)时维护 uploadFiles */
  const onUploadChange = (file: any) => {
    if (file.raw && !uploadFiles.value.includes(file.raw)) {
      uploadFiles.value.push(file.raw)
    }
  }

  const onUploadRemove = (file: any) => {
    uploadFiles.value = uploadFiles.value.filter((f) => f !== file.raw)
  }

  const doUpload = async () => {
    if (!uploadFiles.value.length) return
    uploading.value = true
    uploadProgress.value = 0
    try {
      const categoryId =
        activeCategory.value !== 'all' && activeCategory.value !== 'uncategorized'
          ? (activeCategory.value as number)
          : null
      const saved = await uploadMediaFiles(
        uploadFiles.value,
        categoryId,
        (p) => (uploadProgress.value = p)
      )
      ElMessage.success(t('zcard.media.uploadSuccess', { count: saved.length }))
      uploadDialogVisible.value = false
      uploadFiles.value = []
      page.value = 1
      await loadCategories()
      loadMedia()
    } catch {
      // 统一错误提示
    } finally {
      uploading.value = false
    }
  }

  // ===== 预览 =====
  const openPreview = (index: number) => {
    previewIndex.value = index
    previewVisible.value = true
  }

  // ===== 复制链接 =====
  const copyLink = async (url: string) => {
    try {
      await navigator.clipboard.writeText(window.location.origin + url)
      ElMessage.success(t('zcard.media.linkCopied'))
    } catch {
      ElMessage.error(t('zcard.media.linkCopyFailed'))
    }
  }

  // ===== 下载 =====
  const downloadFile = (item: MediaItem) => {
    const a = document.createElement('a')
    a.href = item.url
    a.download = item.original_name
    a.target = '_blank'
    a.click()
  }

  // ===== 删除 =====
  const handleDelete = async (item: MediaItem) => {
    try {
      await ElMessageBox.confirm(t('zcard.media.deleteConfirm'), t('zcard.common.warning'), {
        confirmButtonText: t('zcard.media.delete'),
        cancelButtonText: t('zcard.common.cancel'),
        type: 'warning'
      })
      await deleteMediaFile(item.id)
      ElMessage.success(t('zcard.common.deleted'))
      await loadCategories()
      loadMedia()
    } catch {
      // 取消或失败统一静默
    }
  }

  const handleBatchDelete = async () => {
    if (!selectedIds.value.length) return
    try {
      await ElMessageBox.confirm(
        t('zcard.media.batchDeleteConfirm', { count: selectedIds.value.length }),
        t('zcard.common.warning'),
        {
          confirmButtonText: t('zcard.media.delete'),
          cancelButtonText: t('zcard.common.cancel'),
          type: 'warning'
        }
      )
      await batchDeleteMedia(selectedIds.value)
      ElMessage.success(t('zcard.common.deleted'))
      selectedIds.value = []
      selection.value = []
      await loadCategories()
      loadMedia()
    } catch {
      // 取消或失败统一静默
    }
  }

  // ===== 移动分类(批量) =====
  const openMoveTarget = (ids: number[]) => {
    if (!ids.length) return
    moveTargetIds.value = ids
    moveTargetCategoryId.value = ''
    moveTargetDialogVisible.value = true
  }

  const confirmMoveTarget = async () => {
    try {
      await batchMoveMedia(
        moveTargetIds.value,
        moveTargetCategoryId.value === '' ? null : moveTargetCategoryId.value
      )
      ElMessage.success(t('zcard.media.moved'))
      moveTargetDialogVisible.value = false
      selectedIds.value = []
      selection.value = []
      await loadCategories()
      loadMedia()
    } catch {
      // 统一错误提示
    }
  }

  // 文件大小格式化
  const formatSize = (bytes: number) => {
    if (!bytes) return '0 B'
    if (bytes < 1024) return bytes + ' B'
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
    return (bytes / 1024 / 1024).toFixed(1) + ' MB'
  }

  // 日期格式化
  const formatDate = (dateStr: string) => {
    if (!dateStr) return ''
    return dateStr.replace('T', ' ').slice(0, 16)
  }

  // 初始加载
  refresh()
</script>

<template>
  <div class="media-manager">
    <input
      ref="fileInput"
      type="file"
      accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml"
      multiple
      class="hidden-file-input"
      @change="onFileInputChange"
    />

    <div class="media-layout">
      <!-- 左侧分类 -->
      <aside class="media-sidebar">
        <div class="sidebar-header">
          <span class="sidebar-title">{{ t('zcard.media.categories') }}</span>
          <ElButton
            text
            type="primary"
            size="small"
            :title="t('zcard.media.addCategory')"
            @click="openCreateCategory"
            >＋</ElButton
          >
        </div>
        <ul class="category-list">
          <li
            class="category-item"
            :class="{ active: activeCategory === 'all' }"
            @click="switchCategory('all')"
          >
            <span class="cat-name">{{ t('zcard.media.all') }}</span>
            <span class="cat-count">{{ categorySummary.total }}</span>
          </li>
          <li
            class="category-item"
            :class="{ active: activeCategory === 'uncategorized' }"
            @click="switchCategory('uncategorized')"
          >
            <span class="cat-name">{{ t('zcard.media.uncategorized') }}</span>
            <span class="cat-count">{{ categorySummary.uncategorized }}</span>
          </li>
          <li
            v-for="cat in categorySummary.categories"
            :key="cat.id"
            class="category-item"
            :class="{ active: activeCategory === cat.id }"
            @click="switchCategory(cat.id)"
          >
            <span class="cat-name" :title="cat.name">{{ cat.name }}</span>
            <span class="cat-count">{{ cat.media_count }}</span>
            <span class="cat-actions" @click.stop>
              <ElButton
                text
                size="small"
                :title="t('zcard.media.rename')"
                @click="openRenameCategory(cat.id, cat.name)"
                ><ArtSvgIcon icon="ri:edit-line" /></ElButton
              >
              <ElButton
                text
                type="danger"
                size="small"
                :title="t('zcard.common.delete')"
                @click="handleDeleteCategory(cat.id)"
                ><ArtSvgIcon icon="ri:delete-bin-5-line" /></ElButton
              >
            </span>
          </li>
        </ul>
      </aside>

      <!-- 右侧素材 -->
      <section class="media-main">
        <!-- 工具栏 -->
        <div class="media-toolbar">
          <ElInput
            v-model="keyword"
            :placeholder="t('zcard.media.searchPlaceholder')"
            clearable
            class="search-input"
            @clear="handleSearch"
            @keyup.enter="handleSearch"
          >
            <template #prefix><ArtSvgIcon icon="ri:search-line" /></template>
          </ElInput>
          <ElButton type="primary" @click="openFilePicker"
            ><ArtSvgIcon icon="ri:export-line" /> {{ t('zcard.media.upload') }}</ElButton
          >
          <ElButton @click="refresh"><ArtSvgIcon icon="ri:refresh-line" /> {{ t('zcard.media.refresh') }}</ElButton>
          <ElSelect v-model="sortField" class="sort-select" @change="handleSortChange">
            <ElOption :label="t('zcard.media.sortCreatedAt')" value="created_at" />
            <ElOption :label="t('zcard.media.sortFilename')" value="filename" />
            <ElOption :label="t('zcard.media.sortSize')" value="size" />
          </ElSelect>
          <ElSelect v-model="sortOrder" class="sort-select" @change="handleSortChange">
            <ElOption :label="t('zcard.media.orderDesc')" value="desc" />
            <ElOption :label="t('zcard.media.orderAsc')" value="asc" />
          </ElSelect>
        </div>

        <!-- 批量操作栏(管理模式) -->
        <div v-if="selectedIds.length > 0 && !selecting" class="batch-bar">
          <span class="batch-count">{{
            t('zcard.media.selected', { count: selectedIds.length })
          }}</span>
          <ElButton size="small" @click="openMoveTarget(selectedIds)">{{
            t('zcard.media.moveTo')
          }}</ElButton>
          <ElButton size="small" type="danger" @click="handleBatchDelete">{{
            t('zcard.media.batchDelete')
          }}</ElButton>
          <ElButton size="small" text @click="selectedIds = []">{{
            t('zcard.media.cancelSelect')
          }}</ElButton>
        </div>

        <!-- 网格 -->
        <div v-loading="loading" class="media-grid-wrap">
          <ul v-if="mediaList.length" class="media-grid">
            <li
              v-for="(item, index) in mediaList"
              :key="item.id"
              class="media-card"
              :class="{ selected: isSelected(item.id), selectable: selecting }"
              @click="toggleSelect(item)"
            >
              <div class="card-thumb">
                <img :src="item.url" :alt="item.original_name" loading="lazy" />
                <!-- 管理模式:显示多选勾选标记;选择模式多选:同 -->
                <span
                  v-if="!selecting || multiple"
                  class="card-checkbox"
                  :class="{ checked: isSelected(item.id) }"
                  >✓</span
                >
                <!-- 选择模式单选:显示单选标记 -->
                <span
                  v-if="selecting && !multiple"
                  class="card-radio"
                  :class="{ checked: isSelected(item.id) }"
                  >✓</span
                >
                <!-- Hover 操作条(预览/复制,所有模式) -->
                <div class="card-hover-actions" @click.stop>
                  <ElButton
                    circle
                    size="small"
                    :title="t('zcard.media.preview')"
                    @click="openPreview(index)"
                    ><ArtSvgIcon icon="ri:eye-line" /></ElButton
                  >
                  <ElButton
                    circle
                    size="small"
                    :title="t('zcard.media.copyLink')"
                    @click="copyLink(item.url)"
                    ><ArtSvgIcon icon="ri:link" /></ElButton
                  >
                </div>
              </div>
              <div class="card-info">
                <div class="card-name" :title="item.original_name">{{ item.original_name }}</div>
                <div class="card-meta">
                  <span>{{ formatDate(item.created_at) }}</span>
                  <span>{{ formatSize(item.size) }}</span>
                </div>
                <!-- 底部操作栏(常驻,所有模式:复制/下载/移动/删除) -->
                <div class="card-op-actions" @click.stop>
                  <ElButton text size="small" @click="copyLink(item.url)"><ArtSvgIcon icon="ri:link" /></ElButton>
                  <ElButton text size="small" @click="downloadFile(item)"><ArtSvgIcon icon="ri:download-line" /></ElButton>
                  <ElButton text size="small" @click="openMoveTarget([item.id])"><ArtSvgIcon icon="ri:folder-2-line" /></ElButton>
                  <ElButton text type="danger" size="small" @click="handleDelete(item)">
                    {{ t('zcard.media.delete') }}
                  </ElButton>
                </div>
              </div>
            </li>
          </ul>
          <ElEmpty v-else-if="!loading" :description="t('zcard.media.empty')" />
        </div>

        <!-- 分页 -->
        <div v-if="total > perPage" class="media-pagination">
          <ElPagination
            v-model:current-page="page"
            :page-size="perPage"
            :total="total"
            layout="prev, pager, next, total"
            @current-change="loadMedia"
          />
        </div>

        <!-- 底部确定/取消(选择模式) -->
        <div v-if="selecting" class="media-footer">
          <span class="footer-tip">
            {{ multiple ? t('zcard.media.multiSelectTip') : t('zcard.media.singleSelectTip') }}
            <template v-if="selectedIds.length"> ({{ selectedIds.length }})</template>
          </span>
          <div class="footer-actions">
            <ElButton @click="emit('cancel')">{{ t('zcard.common.cancel') }}</ElButton>
            <ElButton type="primary" :disabled="!selectedIds.length" @click="handleConfirm">
              {{ t('zcard.common.ok') }}
            </ElButton>
          </div>
        </div>
      </section>
    </div>

    <!-- 分类新增/改名弹窗 -->
    <ElDialog
      v-model="categoryDialogVisible"
      :title="
        categoryDialogMode === 'create' ? t('zcard.media.addCategory') : t('zcard.media.rename')
      "
      width="400px"
      destroy-on-close
    >
      <ElInput
        v-model="categoryName"
        :placeholder="t('zcard.media.categoryNamePlaceholder')"
        maxlength="30"
        show-word-limit
        @keyup.enter="submitCategory"
      />
      <template #footer>
        <ElButton @click="categoryDialogVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="categorySaving" @click="submitCategory">{{
          t('zcard.common.ok')
        }}</ElButton>
      </template>
    </ElDialog>

    <!-- 分类删除迁移弹窗 -->
    <ElDialog
      v-model="moveDialogVisible"
      :title="t('zcard.media.moveBeforeDelete')"
      width="440px"
      destroy-on-close
    >
      <p class="move-tip">{{ t('zcard.media.moveBeforeDeleteTip') }}</p>
      <ElSelect
        v-model="moveTargetId"
        :placeholder="t('zcard.media.moveToPlaceholder')"
        class="move-select"
      >
        <ElOption :label="t('zcard.media.uncategorized')" value="" />
        <ElOption
          v-for="cat in categorySummary.categories.filter((c) => c.id !== moveSourceId)"
          :key="cat.id"
          :label="cat.name"
          :value="cat.id"
        />
      </ElSelect>
      <template #footer>
        <ElButton @click="moveDialogVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" @click="confirmMoveCategory">{{
          t('zcard.media.confirmMove')
        }}</ElButton>
      </template>
    </ElDialog>

    <!-- 上传弹窗 -->
    <ElDialog
      v-model="uploadDialogVisible"
      :title="t('zcard.media.upload')"
      width="520px"
      destroy-on-close
    >
      <div class="upload-area">
        <el-upload
          drag
          multiple
          :auto-upload="false"
          accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml"
          :on-change="onUploadChange"
          :on-remove="onUploadRemove"
          :limit="20"
        >
          <div class="upload-inner">
            <div class="upload-icon"><ArtSvgIcon icon="ri:export-line" /></div>
            <div class="upload-text">{{ t('zcard.media.dragTip') }}</div>
            <div class="upload-sub">{{ t('zcard.media.clickUpload') }}</div>
            <div class="upload-formats">JPG / PNG / WEBP / GIF / SVG</div>
          </div>
        </el-upload>
      </div>
      <div v-if="uploading" class="upload-progress">
        <el-progress :percentage="uploadProgress" />
      </div>
      <template #footer>
        <ElButton @click="uploadDialogVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="uploading" @click="doUpload">{{
          t('zcard.media.upload')
        }}</ElButton>
      </template>
    </ElDialog>

    <!-- 移动分类弹窗 -->
    <ElDialog
      v-model="moveTargetDialogVisible"
      :title="t('zcard.media.moveTo')"
      width="400px"
      destroy-on-close
    >
      <ElSelect
        v-model="moveTargetCategoryId"
        :placeholder="t('zcard.media.moveToPlaceholder')"
        class="move-select"
      >
        <ElOption :label="t('zcard.media.uncategorized')" value="" />
        <ElOption
          v-for="cat in categorySummary.categories"
          :key="cat.id"
          :label="cat.name"
          :value="cat.id"
        />
      </ElSelect>
      <template #footer>
        <ElButton @click="moveTargetDialogVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" @click="confirmMoveTarget">{{ t('zcard.common.ok') }}</ElButton>
      </template>
    </ElDialog>

    <!-- 图片预览(Element Plus Image Viewer) -->
    <el-image-viewer
      v-if="previewVisible"
      :url-list="previewSrcList"
      :initial-index="previewIndex"
      @close="previewVisible = false"
    />
  </div>
</template>

<style lang="scss" scoped>
  .media-manager {
    width: 100%;
    height: 100%;
  }
  .hidden-file-input {
    display: none;
  }
  .media-layout {
    display: flex;
    gap: 16px;
    height: 100%;
    min-height: 480px;
  }

  /* 左侧分类 */
  .media-sidebar {
    width: 220px;
    flex-shrink: 0;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 8px;
    padding: 8px;
    display: flex;
    flex-direction: column;
    max-height: 100%;
    overflow: hidden;
  }
  .sidebar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 4px 8px 8px;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }
  .sidebar-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }
  .category-list {
    list-style: none;
    margin: 0;
    padding: 8px 0 0;
    overflow-y: auto;
    flex: 1;
  }
  .category-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
    padding: 6px 8px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    color: var(--el-text-color-regular);
    transition: background-color 0.15s;
    &:hover {
      background: var(--el-fill-color-light);
      .cat-actions {
        opacity: 1;
      }
    }
    &.active {
      background: var(--el-color-primary-light-9);
      color: var(--el-color-primary);
      font-weight: 500;
    }
    .cat-name {
      flex: 1;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .cat-count {
      font-size: 12px;
      color: var(--el-text-color-secondary);
    }
    .cat-actions {
      display: flex;
      align-items: center;
      opacity: 0;
      transition: opacity 0.15s;
      :deep(.el-button) {
        padding: 2px;
        min-width: 0;
        font-size: 12px;
      }
    }
  }

  /* 右侧主区 */
  .media-main {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 12px;
  }
  .media-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    .search-input {
      width: 220px;
    }
    .sort-select {
      width: 130px;
    }
  }
  .batch-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: var(--el-color-primary-light-9);
    border-radius: 8px;
    .batch-count {
      font-size: 13px;
      color: var(--el-color-primary);
    }
  }
  .media-grid-wrap {
    flex: 1;
    overflow-y: auto;
    min-height: 300px;
  }
  .media-grid {
    list-style: none;
    margin: 0;
    padding: 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 12px;
  }
  .media-card {
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 8px;
    overflow: hidden;
    background: var(--el-bg-color);
    cursor: default;
    transition:
      border-color 0.15s,
      box-shadow 0.15s;
    &.selectable {
      cursor: pointer;
    }
    &.selected {
      border-color: var(--el-color-primary);
      box-shadow: 0 0 0 2px var(--el-color-primary-light-7);
    }
    .card-thumb {
      position: relative;
      height: 110px;
      background: var(--el-fill-color-lighter);
      img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
      }
      .card-checkbox,
      .card-radio {
        position: absolute;
        top: 6px;
        right: 6px;
        width: 18px;
        height: 18px;
        border-radius: 4px;
        border: 1px solid var(--el-border-color);
        background: rgba(255, 255, 255, 0.9);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        color: transparent;
        &.checked {
          background: var(--el-color-primary);
          border-color: var(--el-color-primary);
          color: #fff;
        }
      }
      .card-radio {
        border-radius: 50%;
      }
      .card-hover-actions {
        position: absolute;
        top: 6px;
        left: 6px;
        right: 6px;
        display: flex;
        justify-content: center;
        gap: 4px;
        opacity: 0.85;
        transition: opacity 0.15s;
        :deep(.el-button) {
          width: 24px;
          height: 24px;
          padding: 0;
          background: rgba(255, 255, 255, 0.92);
          border: none;
          font-size: 12px;
        }
      }
    }
    &:hover .card-hover-actions {
      opacity: 1;
    }
    .card-info {
      padding: 6px 8px;
      .card-name {
        font-size: 12px;
        color: var(--el-text-color-primary);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
      }
      .card-meta {
        display: flex;
        justify-content: space-between;
        font-size: 11px;
        color: var(--el-text-color-secondary);
        margin-top: 2px;
      }
      .card-op-actions {
        display: flex;
        align-items: center;
        gap: 2px;
        margin-top: 4px;
        border-top: 1px solid var(--el-border-color-lighter);
        padding-top: 4px;
        :deep(.el-button) {
          padding: 2px 6px;
          font-size: 12px;
          min-width: 0;
        }
      }
    }
  }
  .media-pagination {
    display: flex;
    justify-content: flex-end;
  }
  .media-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 12px;
    border-top: 1px solid var(--el-border-color-lighter);
    .footer-tip {
      font-size: 13px;
      color: var(--el-text-color-secondary);
    }
    .footer-actions {
      display: flex;
      gap: 8px;
    }
  }

  /* 弹窗通用 */
  .move-tip {
    margin: 0 0 12px;
    font-size: 13px;
    color: var(--el-text-color-regular);
    line-height: 1.6;
  }
  .move-select {
    width: 100%;
  }
  .upload-area {
    width: 100%;
  }
  .upload-inner {
    padding: 12px 0;
    .upload-icon {
      font-size: 40px;
    }
    .upload-text {
      font-size: 14px;
      font-weight: 500;
      margin-top: 8px;
    }
    .upload-sub {
      font-size: 12px;
      color: var(--el-text-color-secondary);
      margin-top: 4px;
    }
    .upload-formats {
      font-size: 11px;
      color: var(--el-text-color-placeholder);
      margin-top: 6px;
    }
  }
  .upload-progress {
    margin-top: 12px;
  }
</style>
