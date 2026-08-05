/**
 * 自助供货对接(个人中心 API 对接)API
 */
import request from './request'

export interface MySupplyAccount {
  id: number
  name: string
  api_key: string
  api_secret: string
  api_secret_masked: string
  balance: number
  status: string
  is_new?: boolean
}

/** 获取/创建当前用户的供货账号 */
export const getMySupplyAccount = () => request.get<MySupplyAccount>('/supplier-account/me')

/** 查看 api_secret 明文 */
export const getMySupplySecret = () => request.get<{ api_secret: string }>('/supplier-account/secret')

/** 重置 api_secret */
export const regenerateMySupplySecret = () =>
  request.post<{ api_secret: string; warning: string }>('/supplier-account/regenerate')
