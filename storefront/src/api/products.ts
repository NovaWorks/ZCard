import request from './request'

export interface Sku { id: number; name: string; price: number; stock: number }
export interface Product {
  id: number; name: string; slug: string; cover: string | null; price: number
  stock: number; sales: number; is_featured: boolean
  description?: string; images?: string[]; category?: { id: number; name: string; slug: string }
  skus?: Sku[]; virtual_reviews?: { rating?: number; count?: number; list?: any[] }
  min_order?: number; max_order?: number; stock_type?: string; delivery_mode?: string
}
export interface Paginated {
  data: Product[]; current_page: number; last_page: number; total: number
}
export const getProducts = (params: Record<string, any> = {}) =>
  request.get<unknown, Paginated>('/products', { params })
export const getProduct = (slug: string) =>
  request.get<unknown, Product>(`/products/${slug}`)
export const getFeatured = (limit?: number) =>
  request.get<unknown, Product[]>('/products/featured', { params: limit ? { limit } : {} })
