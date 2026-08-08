/**
 * 充值单管理 API（ZCard Admin）
 *
 * 后端 /api/admin/recharges，Sanctum 鉴权。
 * 金额一律以「分」为单位，前端展示需 / 100 转为元。
 */
import http from '@/utils/http'

/** 充值单状态 */
export type RechargeStatus = 'pending' | 'paid' | 'closed'

/** 充值目标 */
export type RechargeTarget = 'balance' | 'supply'

/** 充值单实体 */
export interface Recharge {
  id: number
  recharge_no: string
  user_id: number
  user?: { id: number; username: string; email: string }
  amount: number
  status: RechargeStatus
  target: RechargeTarget
  paid_at: string | null
  created_at: string
  updated_at: string
}

/** Laravel paginate 返回结构 */
export interface RechargePage {
  data: Recharge[]
  current_page: number
  total: number
  last_page: number
  per_page: number
}

/** 统计数据 */
export interface RechargeStats {
  total_count: number
  total_amount: number
  pending_amount: number
  paid_amount: number
  closed_amount: number
}

/** 列表查询参数 */
export interface RechargeListParams {
  page?: number
  pageSize?: number
  keyword?: string
  status?: RechargeStatus
  target?: RechargeTarget
  start_date?: string
  end_date?: string
}

/** 获取充值单列表 */
export const getRecharges = (params: RechargeListParams) =>
  http.get<RechargePage>({ url: '/admin/recharges', params })

/** 获取充值单详情 */
export const getRecharge = (id: number) =>
  http.get<Recharge>({ url: `/admin/recharges/${id}` })

/** 统计数据 */
export const getRechargeStats = (params: RechargeListParams) =>
  http.get<RechargeStats>({ url: '/admin/recharges/stats', params })
