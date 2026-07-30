/**
 * 卡密管理 API（ZCard Admin）
 *
 * 后端 /api/admin/cards，Sanctum 鉴权。
 */
import request from '@/utils/http'

/** 卡密状态 */
export type CardStatus = 'unused' | 'locked' | 'used' | 'disabled'

/** 卡密实体 */
export interface Card {
  id: number
  product_id: number
  product?: { id: number; name: string }
  content: string
  status: CardStatus
  source: string | null
  batch_id: number | null
  created_at: string
  updated_at: string
}

/** Laravel paginate 返回结构 */
export interface CardPage {
  data: Card[]
  current_page: number
  total: number
  last_page: number
  per_page: number
}

/** 列表查询参数 */
export interface CardListParams {
  page?: number
  pageSize?: number
  product_id?: number
  status?: CardStatus
}

/** 导入卡密请求 */
export interface ImportCardsPayload {
  product_id: number
  /** 多行卡密，每行一条 */
  contents: string
}

/** 导入卡密响应 */
export interface ImportCardsResult {
  success_count: number
  fail_count: number
  batch_id?: number
  message?: string
}

/** 导入批次 */
export interface ImportBatch {
  id: number
  product_id: number
  product?: { id: number; name: string }
  total: number
  success_count: number
  fail_count: number
  source: string | null
  created_at: string
}

/** 获取卡密列表 */
export const getCards = (params: CardListParams) =>
  request.get<CardPage>({ url: '/admin/cards', params })

/** 导入卡密 */
export const importCards = (data: ImportCardsPayload) =>
  request.post<ImportCardsResult>({ url: '/admin/cards/import', data })

/** 禁用卡密 */
export const disableCards = (ids: number[]) =>
  request.post<{ affected: number }>({ url: '/admin/cards/disable', data: { ids } })

/** 获取导入批次列表 */
export const getImportBatches = () =>
  request.get<ImportBatch[]>({ url: '/admin/cards/import-batches' })
