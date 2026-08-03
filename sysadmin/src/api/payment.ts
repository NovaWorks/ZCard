/**
 * 支付渠道管理 API（ZCard Admin）
 *
 * 后端 /api/admin/payment-channels，Sanctum 鉴权。
 */
import request from '@/utils/http'

/** 支付渠道实体 */
export interface PaymentChannel {
  id: number
  code: string
  name: string
  icon?: string
  enabled: boolean
  /** 渠道配置（键值对） */
  config?: Record<string, any>
  sort?: number
  created_at?: string
  updated_at?: string
}

/** 动态配置字段描述 */
export interface ConfigField {
  /** 字段名 */
  key: string
  /** 显示名称 */
  label: string
  /** 字段类型 */
  type?: 'text' | 'textarea' | 'password' | 'number' | 'select' | 'multiselect' | 'switch'
  /** 占位提示 */
  placeholder?: string
  /** 选项（type=select 时使用） */
  options?: Array<{ label: string; value: any }>
  /** 是否必填 */
  required?: boolean
  /** 默认值 */
  default?: any
  /** 帮助说明 */
  help?: string
}

/** 获取支付渠道列表 */
export const getChannels = () =>
  request.get<PaymentChannel[]>({ url: '/admin/payment-channels' })

/** 更新支付渠道 */
export const updateChannel = (id: number, data: Partial<PaymentChannel>) =>
  request.put<PaymentChannel>({ url: `/admin/payment-channels/${id}`, data })

/** config-fields 接口返回结构 */
export interface ConfigFieldsResult {
  channel_id: number
  driver: string
  fields: ConfigField[]
  callback_url: string
}

/** 获取渠道动态配置字段 */
export const getConfigFields = (id: number) =>
  request.get<ConfigFieldsResult>({ url: `/admin/payment-channels/${id}/config-fields` })
