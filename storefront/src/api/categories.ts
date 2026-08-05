import request from './request'

export interface Category {
  id: number; name: string; slug: string; parent_id: number | null
  icon?: string
  children?: Category[]
}
export const getCategories = () => request.get<unknown, Category[]>('/categories')
