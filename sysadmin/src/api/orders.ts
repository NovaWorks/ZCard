/**
 * 订单管理 API（ZCard Admin）
 *
 * 后端 /api/admin/orders，Sanctum 鉴权。
 * 金额一律以「分」为单位，前端展示需 / 100 转为元。
 */
import request from '@/utils/http'

/** 订单状态：pending 待支付 / paid 已支付 / closed 已关闭 */
export type OrderStatus = 'pending' | 'paid' | 'closed'

/** 订单实体 */
export interface Order {
  id: number
  order_no: string
  product_id: number
  product?: { id: number; name: string }
  quantity: number
  /** 金额，单位：分 */
  amount: number
  status: OrderStatus
  contact: string | null
  email: string | null
  paid_at: string | null
  closed_at: string | null
  created_at: string
  updated_at: string
  /** 已发货的卡密列表（详情接口返回） */
  deliveries?: Array<{
    id: number
    card_id: number
    content: string
    created_at: string
  }>
}

/** Laravel paginate 返回结构 */
export interface OrderPage {
  data: Order[]
  current_page: number
  total: number
  last_page: number
  per_page: number
}

/** 列表查询参数 */
export interface OrderListParams {
  page?: number
  pageSize?: number
  keyword?: string
  status?: OrderStatus
}

/** 获取订单列表 */
export const getOrders = (params: OrderListParams) =>
  request.get<OrderPage>({ url: '/admin/orders', params })

/** 获取订单详情 */
export const getOrder = (id: number) => request.get<Order>({ url: `/admin/orders/${id}` })

/** 关闭订单 */
export const closeOrder = (id: number) =>
  request.post<Order>({ url: `/admin/orders/${id}/close` })
