import request from '@/utils/http'

/** 素材记录 */
export interface MediaItem {
  id: number
  category_id: number | null
  original_name: string
  filename: string
  path: string
  url: string
  mime_type: string
  size: number
  width: number | null
  height: number | null
  created_at: string
  category?: { id: number; name: string } | null
}

/** 素材分类 */
export interface MediaCategory {
  id: number
  name: string
  sort: number
  media_count: number
  created_at?: string
}

/** 分类汇总(含未分类数量/总数) */
export interface MediaCategorySummary {
  categories: MediaCategory[]
  uncategorized: number
  total: number
}

/** 分页响应(后端 Laravel LengthAwarePaginator) */
export interface MediaPage {
  data: MediaItem[]
  current_page: number
  last_page: number
  total: number
  per_page: number
}

/** 列表查询参数 */
export interface MediaQuery {
  keyword?: string
  category_id?: number | null
  uncategorized?: boolean
  sort?: 'created_at' | 'filename' | 'size'
  order?: 'desc' | 'asc'
  page?: number
  per_page?: number
}

// ===== 分类 =====

export const getMediaCategories = () =>
  request.get<MediaCategorySummary>({ url: '/admin/media-categories' })

export const createMediaCategory = (name: string) =>
  request.post<MediaCategory>({ url: '/admin/media-categories', data: { name } })

export const renameMediaCategory = (id: number, name: string) =>
  request.put<MediaCategory>({ url: `/admin/media-categories/${id}`, data: { name } })

export const deleteMediaCategory = (id: number) =>
  request.del({ url: `/admin/media-categories/${id}` })

/** 迁移分类下图片到目标分类后删除分类(target_category_id 传 null=未分类) */
export const moveMediaCategory = (id: number, targetCategoryId: number | null) =>
  request.post<{ deleted: boolean }>({
    url: `/admin/media-categories/${id}/move`,
    data: { target_category_id: targetCategoryId }
  })

// ===== 素材 =====

export const getMediaList = (params: MediaQuery) =>
  request.get<MediaPage>({ url: '/admin/media', params })

/** 多文件上传,指定分类可选 */
export const uploadMediaFiles = (
  files: File[],
  categoryId: number | null = null,
  onProgress?: (percent: number) => void
) => {
  const formData = new FormData()
  files.forEach((file) => formData.append('files[]', file))
  if (categoryId !== null) formData.append('category_id', String(categoryId))
  return request.post<MediaItem[]>({
    url: '/admin/media/upload',
    data: formData,
    headers: { 'Content-Type': 'multipart/form-data' },
    onUploadProgress: (e) => {
      if (onProgress && e.total) onProgress(Math.round((e.loaded / e.total) * 100))
    }
  })
}

export const deleteMediaFile = (id: number) => request.del({ url: `/admin/media/${id}` })

export const batchDeleteMedia = (ids: number[]) =>
  request.post<{ deleted: number }>({ url: '/admin/media/batch-delete', data: { ids } })

export const batchMoveMedia = (ids: number[], categoryId: number | null) =>
  request.post<{ moved: number }>({
    url: '/admin/media/batch-move',
    data: { ids, category_id: categoryId }
  })
