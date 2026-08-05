import request from './request'
import { type PaymentResult } from './payments'

/** 充值记录(金额字段均为分,展示时需 formatMoney) */
export interface RechargeRecord {
  id: number
  recharge_no: string
  amount: number
  status: string
  created_at: string
  paid_at?: string
}

/** 创建充值单(amount 单位为元,target: balance=个人余额 / supply=供货余额) */
export const createRecharge = (amount: number, target: 'balance' | 'supply' = 'balance') =>
  request.post<unknown, { recharge_no: string; amount: number; status: string; target: string }>(
    '/recharges',
    { amount, target }
  )

/** 充值历史 */
export const getRechargeHistory = () =>
  request.get<unknown, RechargeRecord[]>('/recharges/history')

/** 充值单状态(支付页轮询用) */
export const getRechargeStatus = (rechargeNo: string) =>
  request.get<unknown, { recharge_no: string; amount: number; status: string }>(
    `/recharges/${rechargeNo}/status`,
  )

/**
 * 发起充值支付。
 * 复用 /payments/create(后端按 RCH 前缀识别为充值单)。
 */
export const createRechargePayment = (rechargeNo: string, channelId: number) =>
  request.post<unknown, PaymentResult>('/payments/create', {
    order_no: rechargeNo,
    channel_id: channelId,
  })
