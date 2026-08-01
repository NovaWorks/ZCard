import http from '@/utils/http'

export interface CommissionStats {
  total_amount: number
  total_count: number
  available_amount: number
  pending_amount: number
  paid_amount: number
}

export interface CommissionRecord {
  id: number
  order_id: number
  buyer_id: number
  referrer_id: number
  tier: number
  rate: string
  base_amount: number
  amount: number
  status: string
  created_at: string
  order?: { id: number; order_no: string; amount: number }
  buyer?: { id: number; username: string }
  referrer?: { id: number; username: string }
}

export interface CommissionPage {
  data: CommissionRecord[]
  current_page: number
  last_page: number
  total: number
}

export interface CommissionListParams {
  page?: number
  pageSize?: number
  page_size?: number
  keyword?: string
  referrer_id?: number
  order_id?: number
  tier?: number
  status?: string
}

export const getCommissionStats = () =>
  http.get<CommissionStats>({ url: '/admin/commissions/stats' })

export const getCommissions = (params: Record<string, any>) =>
  http.get<CommissionPage>({ url: '/admin/commissions', params })
