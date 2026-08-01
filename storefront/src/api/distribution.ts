import request from './request'

export interface DistributionStats {
  total_commission: number       // 分
  available_commission: number   // 分
  balance: number                // 分
  referral_count: number
  referral_link: string
}
export interface Referral { id: number; username: string; created_at: string }
export interface CommissionRecord {
  id: number
  tier: number
  rate: string
  base_amount: number
  amount: number
  status: string
  created_at: string
  order?: { id: number; order_no: string; amount: number }
  buyer?: { id: number; username: string }
}

export const getStats = () => request.get<unknown, DistributionStats>('/distribution/stats')
export const getReferrals = () => request.get<unknown, Referral[]>('/distribution/referrals')
export const getCommissions = () => request.get<unknown, CommissionRecord[]>('/distribution/commissions')
