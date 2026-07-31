import request from './request'

export interface PaymentChannel {
  id: number
  name: string
  code: string
  icon: string
  supported_currencies?: string[]
  target_currency?: string | null
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
