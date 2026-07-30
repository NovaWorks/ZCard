import request from '@/utils/http'

export interface Category {
  id: number
  name: string
  slug: string
  parent_id: number | null
  sort: number
  status: number | boolean
  children?: Category[]
}

export const getCategories = () => request.get<Category[]>({ url: '/admin/categories' })

export const getAllCategories = () => request.get<Category[]>({ url: '/admin/categories/all' })

export const createCategory = (data: Partial<Category>) =>
  request.post<Category>({ url: '/admin/categories', data })

export const updateCategory = (id: number, data: Partial<Category>) =>
  request.put<Category>({ url: `/admin/categories/${id}`, data })

export const deleteCategory = (id: number) =>
  request.del({ url: `/admin/categories/${id}` })
