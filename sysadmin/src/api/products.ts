/**
 * 商品管理 API（ZCard Admin）
 *
 * 后端 /api/admin/products，Sanctum 鉴权。
 * 金额一律以「分」为单位，前端展示需 / 100 转为元。
 */
import request from '@/utils/http'

/** 商品实体 */
export interface Product {
  id: number
  name: string
  slug: string
  category_id: number | null
  category?: { id: number; name: string }
  price: number // 分
  price_manual?: boolean // 售价是否被手动修改(同步保护)
  factory_price?: number // 分
  draft_premium?: number // 分
  description?: string | null
  leave_message?: string | null
  seo_title?: string | null
  seo_keywords?: string | null
  seo_description?: string | null
  cover?: string | null
  images?: string[] | null
  member_price?: Record<string, number> | null
  stock?: number
  status: number
  is_featured: boolean
  virtual_sales: number
  stock_type: string
  fulfillment_type?: 'auto_card' | 'fixed' | 'manual' | 'upstream'
  upstream_source_id?: number | null
  stock_visible?: boolean
  delivery_mode: string
  sort: number
  min_order?: number | null
  max_order?: number | null
  dedup?: boolean
  created_at: string
}

/** Laravel paginate 返回结构（部分字段） */
export interface ProductPage {
  data: Product[]
  current_page: number
  total: number
  last_page: number
  per_page: number
}

/** 列表查询参数 */
export interface ProductListParams {
  page?: number
  pageSize?: number
  keyword?: string
  status?: number
  category_id?: number
  is_featured?: number
  stock_type?: string
  stock_status?: string
  /** 货源商筛选(上游供货商品) */
  upstream_source_id?: number
}

/** 获取商品列表 */
export const getProducts = (params: ProductListParams) =>
  request.get<ProductPage>({ url: '/admin/products', params })

/** 获取单个商品 */
export const getProduct = (id: number) => request.get<Product>({ url: `/admin/products/${id}` })

/** 新增商品 */
export const createProduct = (data: Partial<Product>) =>
  request.post<Product>({ url: '/admin/products', data })

/** 更新商品 */
export const updateProduct = (id: number, data: Partial<Product>) =>
  request.put<Product>({ url: `/admin/products/${id}`, data })

/** 删除商品 */
export const deleteProduct = (id: number) => request.del<void>({ url: `/admin/products/${id}` })

/** 商品统计数据 */
export interface ProductStats {
  total: number
  active: number
  inactive: number
  featured: number
  total_stock: number
  total_orders: number
  paid_orders: number
}

/** 获取商品统计 */
export const getProductStats = () => request.get<ProductStats>({ url: '/admin/products/stats' })

/** 批量操作商品 */
export const batchAction = (
  ids: number[],
  action: 'activate' | 'deactivate' | 'delete' | 'set_category',
  extra?: { category_id?: number }
) =>
  request.post<{ message: string; affected: number }>({
    url: '/admin/products/batch',
    data: { ids, action, ...extra }
  })

/** 会员等级(user_groups)实体(用于会员价编辑器下拉) */
export interface UserGroup {
  id: number
  name: string
}

/** 获取启用的会员等级列表 */
export const getUserGroups = () =>
  request.get<UserGroup[]>({ url: '/admin/user-groups' })
