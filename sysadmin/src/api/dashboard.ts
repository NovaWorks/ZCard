import http from '@/utils/http'

export interface OverviewData {
  online_users: number
  total_orders: number
  paid_orders: number
  paid_amount: number
  total_cost: number
  profit: number
  profit_margin: number
  pending_amount: number
  payment_success: number
  payment_failed: number
  payment_rate: number
  new_users: number
  total_products: number
  low_stock_products: number
  total_stock: number
  pending_withdrawals: number
}

export interface TrendPoint {
  date: string
  order_count: number
  paid_count: number
  paid_amount: number
  paid_cost: number
  profit: number
  refunded_count: number
  refund_rate: number
}

export interface TrafficPoint {
  date: string
  pv: number
  uv: number
}

export interface TopProduct {
  product_id: number
  product_name: string
  order_count: number
  paid_amount: number
  profit: number
}

export interface TopChannel {
  channel: string
  success_count: number
  failed_count: number
  total_count: number
  success_rate: number
}

export const getOverview = (params?: any) =>
  http.get<OverviewData>({ url: '/admin/dashboard/overview', params })

export const getTrends = (params?: any) =>
  http.get<TrendPoint[]>({ url: '/admin/dashboard/trends', params })

export const getTopProducts = (params?: any) =>
  http.get<TopProduct[]>({ url: '/admin/dashboard/top-products', params })

export const getTopChannels = (params?: any) =>
  http.get<TopChannel[]>({ url: '/admin/dashboard/top-channels', params })

export const getTraffic = (params?: any) =>
  http.get<TrafficPoint[]>({ url: '/admin/dashboard/traffic', params })
