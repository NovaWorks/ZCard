import request from './request'

export interface Sku { id: number; name: string; price: number; stock: number; price_base?: number; price_display?: number; display_currency?: string }
export interface PremiumNumber {
  card_id: number
  number: string
  price: number
  price_display: number
  display_currency: string
}
export interface PremiumNumbers {
  list: PremiumNumber[]
  total: number
  page: number
  per_page: number
  has_more: boolean
  min_price: number | null
  min_price_display: number | null
  min_currency: string | null
}
export interface Product {
  id: number; name: string; slug: string; cover: string | null; price: number
  price_base: number; price_display: number; display_currency: string; exchange_rate: number
  stock: number; sales: number; is_featured: boolean
  description?: string; images?: string[]; category?: { id: number; name: string; slug: string }
  skus?: Sku[]; virtual_reviews?: { rating?: number; count?: number; list?: any[] }
  min_order?: number; max_order?: number; stock_type?: string; delivery_mode?: string
  /** 购买选择方式: general=常规, premium=靓号自选 */
  pick_type?: 'general' | 'premium'
  premium_numbers?: PremiumNumbers
  premium_min_price?: number | null
  premium_min_price_display?: number | null
}
export interface Paginated {
  data: Product[]; current_page: number; last_page: number; total: number
}
export const getProducts = (params: Record<string, any> = {}) =>
  request.get<unknown, Paginated>('/products', { params })
export const getProduct = (slug: string, params: Record<string, any> = {}) =>
  request.get<unknown, Product>(`/products/${slug}`, { params })
export const getFeatured = (limit?: number) =>
  request.get<unknown, Product[]>('/products/featured', { params: limit ? { limit } : {} })
