import request from './request'

export interface PaymentChannel {
  id: number
  name: string
  code: string
  icon: string
  /** 支持的支付方式标识(如 alipay/wechat/paypal/usdt/balance) */
  pay_types?: string[]
  supported_currencies?: string[]
  target_currency?: string | null
  /** 手续费配置(客户承担时前端展示明细) */
  fee?: number
  fee_type?: string
  fee_bearer?: string
  /** 余额支付通道:当前用户余额(分) */
  balance?: number
}

export interface PaymentResult {
  type: 'redirect' | 'qrcode' | 'form'
  redirect_url?: string
  qrcode_content?: string
  form_html?: string
}

export const getChannels = () =>
  request.get<unknown, PaymentChannel[]>('/payments/channels')

export const createPayment = (orderNo: string, channelId: number, payType?: string) =>
  request.post<unknown, PaymentResult>('/payments/create', {
    order_no: orderNo,
    channel_id: channelId,
    pay_type: payType || undefined,
  })

/** 购物车聚合支付:一次支付多个订单 */
export const createBatchPayment = (orderIds: number[], channelId: number, payType?: string) =>
  request.post<unknown, PaymentResult>('/payments/batch-create', {
    order_ids: orderIds,
    channel_id: channelId,
    pay_type: payType || undefined,
  })

/** 余额支付单个订单(需登录) */
export const balancePay = (orderNo: string) =>
  request.post<unknown, BalancePayResult>('/payments/balance', { order_no: orderNo })

/** 购物车聚合余额支付 */
export const balanceBatchPay = (orderIds: number[]) =>
  request.post<unknown, BalancePayResult>('/payments/balance-batch', { order_ids: orderIds })

export interface BalancePayResult {
  orders: { order_no: string; status: string; delivered: boolean }[]
  amount: number
  balance_after: number
}
