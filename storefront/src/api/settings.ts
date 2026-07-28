import request from './request'

export interface StorefrontSettings {
  category_nav_style: 'pills' | 'sidebar' | 'combo'
  list_default_view: 'grid' | 'list' | 'dual'
  grid_columns: number
  page_size: number
  default_order: string
  show_stock: boolean; show_sales: boolean; show_reviews: boolean
  allow_post_review: boolean; review_need_audit: boolean
  show_featured: boolean; featured_count: number
  show_hot_tags: boolean; hot_tag_categories: number[]
  order_query_password: boolean; trade_captcha: boolean
}
export const getStorefrontSettings = () =>
  request.get<unknown, StorefrontSettings>('/settings/storefront')
