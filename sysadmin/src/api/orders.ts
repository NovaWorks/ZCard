/**
 * 订单管理 API（ZCard Admin）
 *
 * 后端 /api/admin/orders，Sanctum 鉴权。
 * 金额一律以「分」为单位，前端展示需 / 100 转为元。
 */
import http from '@/utils/http'

/** 订单状态 */
export type OrderStatus = 'pending' | 'paid' | 'closed' | 'refunded'

/** 订单实体 */
export interface Order {
  id: number
  order_no: string
  product_id: number
  product?: { id: number; name: string }
  quantity: number
  sku_name: string | null
  amount: number
  cost: number
  payment_channel: string | null
  status: OrderStatus
  delivery_status: 'pending' | 'delivered' | 'failed'
  fulfillment_type_snapshot: 'auto_card' | 'fixed' | 'manual' | 'upstream'
  contact: string | null
  paid_at: string | null
  closed_at: string | null
  created_at: string
  updated_at: string
  order_deliveries_count: number
  /** 详情接口返回的发货列表 */
  deliveries?: Array<{
    id: number
    card_content: string
    delivered_mode: string
    delivered_at: string
  }>
  // 财务(详情接口):单价/成本单价/利润/利润率
  unit_price?: number
  unit_cost?: number
  profit?: number
  profit_rate?: number
  // 货源(详情接口):货源名/上游商品链接/上游单号
  upstream_source_name?: string
  upstream_base_url?: string
  upstream_product_url?: string | null
  upstream_order_id?: string | null
}

/** Laravel paginate 返回结构 */
export interface OrderPage {
  data: Order[]
  current_page: number
  total: number
  last_page: number
  per_page: number
}

/** 统计数据 */
export interface OrderStats {
  total_count: number
  pending_amount: number
  total_amount: number
  paid_amount: number
  refunded_amount: number
  total_cost: number
}

/** 列表查询参数 */
export interface OrderListParams {
  page?: number
  pageSize?: number
  keyword?: string
  status?: OrderStatus
  payment_channel?: string
  product_id?: number
  start_date?: string
  end_date?: string
  delivery_status?: 'pending' | 'delivered'
  user_type?: 'guest' | 'member'
  create_device?: 'win' | 'mac' | 'ios' | 'android' | 'other'
  create_ip?: string
}

/** 获取订单列表 */
export const getOrders = (params: OrderListParams) =>
  http.get<OrderPage>({ url: '/admin/orders', params })

/** 获取订单详情 */
export const getOrder = (id: number) =>
  http.get<Order>({ url: `/admin/orders/${id}` })

/** 关闭订单 */
export const closeOrder = (id: number) =>
  http.post<Order>({ url: `/admin/orders/${id}/close` })

/** 完成人工发货(manual 与 upstream 订单均可,upstream 为拿货失败兜底) */
export const fulfillOrder = (id: number, content: string) =>
  http.post<Order>({ url: `/admin/orders/${id}/fulfill`, data: { content } })

/** 手动重新拿货(自动拿货失败兜底) */
export const refetchUpstreamOrder = (id: number) =>
  http.post<{ ok: boolean; message: string; order?: Order }>({ url: `/admin/orders/${id}/refetch-upstream` })

/** 统计数据 */
export const getStats = (params: OrderListParams) =>
  http.get<OrderStats>({ url: '/admin/orders/stats', params })

/** 清理无用订单 */
export const clearOrders = () =>
  http.post<{ cleared: number }>({ url: '/admin/orders/clear' })
