import request from './request'

export interface CreatedOrder {
  order_no: string; amount: number; status: string
}
export interface OrderDetail {
  order_no: string; status: string; product_name?: string; product_cover?: string | null
  quantity: number; amount: number; cards: string[]
  created_at: string; paid_at?: string
}
export const createOrder = (data: {
  product_id: number; sku_id?: number; qty: number
  contact: string; password?: string; extra?: Record<string, any>
}) => request.post<unknown, CreatedOrder>('/orders', data)

export const mockPay = (orderNo: string) =>
  request.post<unknown, { order_no: string; status: string; delivered: boolean }>(`/orders/${orderNo}/mock-pay`)

export const queryOrder = (params: { contact: string; order_no: string; password?: string }) =>
  request.get<unknown, OrderDetail>('/orders/query', { params })

export const getMyOrders = () => request.get<unknown, OrderDetail[]>('/orders/mine')
