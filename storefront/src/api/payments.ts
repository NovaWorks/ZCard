import request from './request'

export interface PaymentChannel {
  id: number
  name: string
  code: string
  icon: string
  /** 支持的支付方式标识(如 alipay/wechat/paypal/usdt) */
  pay_types?: string[]
  supported_currencies?: string[]
  target_currency?: string | null
  /** 手续费配置(客户承担时前端展示明细) */
  fee?: number
  fee_type?: string
  fee_bearer?: string
}

export interface PaymentResult {
  type: 'redirect' | 'qrcode' | 'form'
  redirect_url?: string
  qrcode_content?: string
  form_html?: string
}

export const getChannels = () =>
  request.get<unknown, PaymentChannel[]>('/payments/channels')

export const createPayment = (orderNo: string, channelId: number) =>
  request.post<unknown, PaymentResult>('/payments/create', {
    order_no: orderNo,
    channel_id: channelId,
  })

/** 购物车聚合支付:一次支付多个订单 */
export const createBatchPayment = (orderIds: number[], channelId: number) =>
  request.post<unknown, PaymentResult>('/payments/batch-create', {
    order_ids: orderIds,
    channel_id: channelId,
  })
