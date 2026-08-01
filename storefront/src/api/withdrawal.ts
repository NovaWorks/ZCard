import request from './request'

/** 提现记录(金额字段均为分,展示时需 formatMoney) */
export interface WithdrawalRecord {
  id: number
  amount: number
  actual_amount: number
  fee: number
  method: string
  account: string
  account_name: string
  status: string
  reject_reason?: string
  created_at: string
}

/** 发起提现(amount 单位为元) */
export const requestWithdrawal = (data: {
  amount: number
  method: string
  account: string
  account_name: string
}) => request.post('/withdrawals', data)

/** 提现历史 */
export const getWithdrawalHistory = () =>
  request.get<unknown, WithdrawalRecord[]>('/withdrawals/history')
