import request from './request'

export interface CreatedOrder {
  order_no: string; amount: number; status: string
  amount_base?: number; amount_display?: number; display_currency?: string
}
export interface OrderDetail {
  id?: number
  order_no: string; status: string; product_name?: string; product_cover?: string | null
  product_id?: number; quantity: number; amount: number; cards: string[]
  created_at: string; paid_at?: string
  amount_base?: number; amount_display?: number; display_currency?: string; exchange_rate?: number
  /** 该订单是否已评价(供"评价"入口显示) */
  reviewed?: boolean
}
export const createOrder = (data: {
  product_id: number; sku_id?: number; qty: number; card_id?: number
  contact: string; password?: string; captcha?: string; captcha_key?: string
  coupon_code?: string; extra?: Record<string, any>
}) => request.post<unknown, CreatedOrder>('/orders', data)

export interface BatchOrderResult {
  orders: { id: number; order_no: string; product_id: number; amount: number; discount_amount: number; status: string }[]
  total_amount: number
  order_ids: number[]
}
export const createBatchOrders = (data: {
  items: { product_id: number; sku_id?: number; qty: number; card_id?: number }[]
  contact: string; password?: string; captcha?: string; captcha_key?: string
  coupon_code?: string; extra?: Record<string, any>
}) => request.post<unknown, BatchOrderResult>('/orders/batch', data)

export const mockPay = (orderNo: string) =>
  request.post<unknown, { order_no: string; status: string; delivered: boolean }>(`/orders/${orderNo}/mock-pay`)

/** 单关键字智能查询历史订单(订单号 OR 联系方式) */
export const queryOrders = (keyword: string, password?: string) =>
  request.get<unknown, OrderDetail[]>('/orders/query', { params: { keyword, password } })

export const getMyOrders = () => request.get<unknown, OrderDetail[]>('/orders/mine')
