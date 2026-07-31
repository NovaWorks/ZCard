import http from '@/utils/http'

export interface Bill {
  id: number
  user_id: number
  user?: { id: number; username: string; email: string }
  amount: number
  balance_after: number
  type: number // 0=支出, 1=收入
  log: string
  order_id: number | null
  order?: { id: number; order_no: string } | null
  admin_id: number | null
  created_at: string
}

export interface BillPage {
  data: Bill[]
  current_page: number
  total: number
  last_page: number
  per_page: number
}

export interface BillStats {
  total_income: number
  total_expense: number
  net_amount: number
  total_count: number
}

export interface BillListParams {
  page?: number
  pageSize?: number
  keyword?: string
  type?: number
  user_id?: number
  start_date?: string
  end_date?: string
}

export const getBills = (params: BillListParams) =>
  http.get<BillPage>({ url: '/admin/bills', params })

export const getBillStats = (params: BillListParams) =>
  http.get<BillStats>({ url: '/admin/bills/stats', params })

export const adjustBalance = (data: {
  user_id: number
  amount: number
  type: number
  log: string
}) => http.post<Bill>({ url: '/admin/bills/adjust', data })
