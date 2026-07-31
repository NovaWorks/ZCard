import http from '@/utils/http'

export interface Withdrawal {
  id: number
  user_id: number
  user?: { id: number; username: string; email: string }
  amount: number
  actual_amount: number
  fee: number
  method: string
  account: string
  account_name: string
  status: string
  reject_reason: string | null
  admin_id: number | null
  processed_at: string | null
  created_at: string
}

export interface WithdrawalPage {
  data: Withdrawal[]
  current_page: number
  total: number
  last_page: number
  per_page: number
}

export interface WithdrawalStats {
  pending_count: number
  approved_count: number
  rejected_count: number
  pending_amount: number
  approved_amount: number
  total_count: number
}

export interface WithdrawalListParams {
  page?: number
  pageSize?: number
  keyword?: string
  status?: string
  method?: string
  user_id?: number
  start_date?: string
  end_date?: string
}

export const getWithdrawals = (params: WithdrawalListParams) =>
  http.get<WithdrawalPage>({ url: '/admin/withdrawals', params })

export const getWithdrawalStats = (params: WithdrawalListParams) =>
  http.get<WithdrawalStats>({ url: '/admin/withdrawals/stats', params })

export const approveWithdrawal = (id: number) =>
  http.post<Withdrawal>({ url: `/admin/withdrawals/${id}/approve` })

export const rejectWithdrawal = (id: number, reason: string) =>
  http.post<Withdrawal>({ url: `/admin/withdrawals/${id}/reject`, data: { reason } })
