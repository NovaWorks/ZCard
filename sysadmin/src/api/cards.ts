/**
 * 卡密管理 API（ZCard Admin）
 *
 * 后端 /api/admin/cards，Sanctum 鉴权。
 * 安全：列表不返回明文内容，只有 export 才下发。
 */
import axios from 'axios'
import request from '@/utils/http'
import { useUserStore } from '@/store/modules/user'

/** 卡密状态 */
export type CardStatus = 'unused' | 'locked' | 'used' | 'disabled'

/** 卡密实体 */
export interface Card {
  id: number
  product_id: number
  product?: { id: number; name: string }
  status: CardStatus
  /** 卡密内容预览(前30字符) */
  content_preview?: string
  /** 备注 */
  note?: string | null
  /** 卡密类型，如 月卡/周卡 */
  card_type?: string | null
  /** 所属会员ID，0=系统 */
  owner_id?: number
  /** 预选加价 */
  draft_premium?: number | null
  /** 预选成本 */
  draft_cost?: number | null
  /** 关联订单ID */
  order_id?: number | null
  /** 关联订单号（来自 order 关系） */
  order?: { id: number; order_no: string } | null
  /** 导入批次ID */
  import_id?: number | null
  /** 来源（import.source） */
  source?: string | null
  import?: { id: number; source: string | null } | null
  /** content_hash 截断展示用 */
  content_hash?: string
  locked_at?: string | null
  used_at?: string | null
  created_at: string
  updated_at?: string
}

/** Laravel paginate 返回结构 */
export interface CardPage {
  data: Card[]
  current_page: number
  total: number
  last_page: number
  per_page: number
}

/** 顶部统计 */
export interface CardStats {
  total: number
  unused: number
  locked: number
  used: number
  disabled: number
}

/** 列表查询参数 */
export interface CardListParams {
  page?: number
  pageSize?: number
  product_id?: number
  status?: CardStatus
  /** 卡密类型 */
  card_type?: string
  /** 备注（模糊匹配） */
  note?: string
  /** 所属会员ID */
  owner_id?: number
  /** 关键词：用明文卡密精确匹配 content_hash（后端 hash 后比对，不暴露明文长度） */
  keyword?: string
  /** 入库时间起 YYYY-MM-DD */
  date_from?: string
  /** 入库时间止 YYYY-MM-DD */
  date_to?: string
}

/** 导入卡密请求 */
export interface ImportCardsPayload {
  product_id: number
  /** 多行卡密，每行一条（后端字段名为 content） */
  contents: string
  /** 卡密类型，可选 */
  card_type?: string
  /** 备注，可选 */
  note?: string
  /** 是否去重（默认 false） */
  dedup?: boolean
}

/** 导入卡密响应 */
export interface ImportCardsResult {
  import_id?: number
  status?: string
  success_count: number
  failed_count: number
  total?: number
  /** 兼容旧字段 */
  fail_count?: number
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

/** 获取卡密统计（顶部 4 张卡片） */
export const getCardStats = (params?: { product_id?: number }) =>
  request.get<CardStats>({ url: '/admin/cards/stats', params })

/** 导入卡密 */
export const importCards = (data: ImportCardsPayload) => {
  // 后端字段名为 content，前端语义为多行 contents
  return request.post<ImportCardsResult>({
    url: '/admin/cards/import',
    data: {
      product_id: data.product_id,
      content: data.contents,
      card_type: data.card_type,
      note: data.note
    }
  })
}

/** 禁用卡密(unused/locked → disabled) */
export const disableCards = (ids: number[]) =>
  request.post<{ disabled: number }>({ url: '/admin/cards/disable', data: { ids } })

/** 启用卡密(disabled → unused) */
export const enableCards = (ids: number[]) =>
  request.post<{ enabled: number }>({ url: '/admin/cards/enable', data: { ids } })

/** 锁定卡密(unused → locked) */
export const lockCards = (ids: number[]) =>
  request.post<{ locked: number }>({ url: '/admin/cards/lock', data: { ids } })

/** 解锁卡密(locked → unused) */
export const unlockCards = (ids: number[]) =>
  request.post<{ unlocked: number }>({ url: '/admin/cards/unlock', data: { ids } })

/** 标记为已出售(unused/locked/disabled → used) */
export const markCardsSold = (ids: number[]) =>
  request.post<{ sold: number }>({ url: '/admin/cards/mark-sold', data: { ids } })

/** 批量删除卡密（只删 unused/disabled） */
export const deleteCards = (ids: number[]) =>
  request.post<{ deleted: number }>({ url: '/admin/cards/destroy', data: { ids } })

/** 查看单条卡密明文(管理员核对) */
export const revealCard = (id: number) =>
  request.get<{ id: number; content: string; status: string; note: string | null; card_type: string | null; draft_premium: number; draft_cost: number; owner_id: number; product_name: string | null; order_no: string | null; created_at: string; used_at: string | null }>({ url: `/admin/cards/${id}/reveal` })

/** 编辑卡密(更新备注/类型/成本/加价/状态) */
export const updateCard = (id: number, data: Partial<{ note: string; card_type: string; draft_premium: number; draft_cost: number; status: string }>) =>
  request.put({ url: `/admin/cards/${id}`, data })

/**
 * 导出筛选后的卡密为 CSV（明文）。
 * 直接用 axios 取 blob，避免被 JSON transformResponse 处理；自动带上 Sanctum token。
 * 返回 { filename, blob }，由调用方触发浏览器下载。
 */
export const exportCards = async (params: CardListParams): Promise<{ filename: string; blob: Blob }> => {
  const { VITE_API_URL } = import.meta.env
  const { accessToken } = useUserStore()

  const res = await axios.get(`${VITE_API_URL}/admin/cards/export`, {
    params,
    responseType: 'blob',
    headers: accessToken ? { Authorization: `Bearer ${accessToken}` } : {}
  })

  // 从 Content-Disposition 解析文件名，拿不到就给个默认名
  let filename = `cards-export-${Date.now()}.csv`
  const cd = res.headers['content-disposition'] as string | undefined
  if (cd) {
    const match = cd.match(/filename\*?=(?:UTF-8'')?["']?([^"';]+)/i)
    if (match) filename = decodeURIComponent(match[1])
  }

  return { filename, blob: res.data as Blob }
}

/** 获取导入批次列表 */
export const getImportBatches = (params?: { page?: number; pageSize?: number }) =>
  request.get<CardPage | ImportBatch[]>({ url: '/admin/cards/import-batches', params })
