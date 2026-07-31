import request from '@/utils/http'

export interface Category {
  id: number
  name: string
  slug: string
  icon: string | null
  description: string | null
  parent_id: number | null
  sort: number
  status: number
  hide: number
  created_at?: string
  children?: Category[]
}

/** 排序更新项 */
export interface SortItem {
  id: number
  sort: number
  parent_id?: number | null
}

export const getCategories = (params?: { keyword?: string; status?: number; hide?: number }) =>
  request.get<Category[]>({ url: '/admin/categories', params })

export const getAllCategories = () =>
  request.get<Category[]>({ url: '/admin/categories/all' })

export const createCategory = (data: Partial<Category>) =>
  request.post<Category>({ url: '/admin/categories', data })

export const updateCategory = (id: number, data: Partial<Category>) =>
  request.put<Category>({ url: `/admin/categories/${id}`, data })

export const deleteCategory = (id: number) =>
  request.del({ url: `/admin/categories/${id}` })

/** 批量更新排序 */
export const updateCategorySort = (items: SortItem[]) =>
  request.post<{ updated: number }>({ url: '/admin/categories/sort', data: { items } })

/** 批量启用/禁用/隐藏切换 */
export const batchUpdateCategory = (ids: number[], field: 'status' | 'hide', value: number) =>
  request.post<{ updated: number }>({ url: '/admin/categories/batch', data: { ids, field, value } })
