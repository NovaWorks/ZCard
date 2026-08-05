import request from '@/utils/http'

/** 单个货源(上游对接配置) */
export interface SupplySource {
  id: number
  name: string
  driver: 'dujiao_next' | 'acg_faka' | 'zcard'
  base_url: string
  /** 凭证(读回时已脱敏:secret/app_key 显示末4位) */
  credentials: Record<string, string>
  status: 'active' | 'disabled'
  settings: Record<string, any> | null
  last_synced_at: string | null
  last_error: string | null
  balance_cache: number | null
  sort: number
  created_at?: string
  updated_at?: string
}

/** Laravel 分页结构 */
export interface SupplySourcePage {
  data: SupplySource[]
  total: number
  current_page: number
  last_page: number
  per_page: number
}

/** 驱动元数据(用于动态渲染凭证表单) */
export interface DriverConfigField {
  key: string
  label: string
  type: 'text' | 'number' | 'url' | 'secret'
  required?: boolean
  help?: string
}
export interface SupplyDriver {
  driver: string
  name: string
  icon: string | null
  config_schema: Record<string, { type: string; label: string; required?: boolean; help?: string }>
}

/** 测试连通结果 */
export interface SupplyTestResult {
  connected: boolean
  name?: string | null
  balance?: number | null
  currency?: string | null
  error?: string | null
}

/** 同步状态 */
export interface SupplySyncStatus {
  ok: boolean
  last_synced_at: string | null
  last_error: string | null
}

/** 驱动元数据(选平台下拉 + 动态凭证字段) */
export const getSupplyDrivers = () =>
  request.get<{ drivers: SupplyDriver[] }>({ url: '/admin/supply-sources/drivers' })

/** 货源列表(分页) */
export const getSupplySources = (params?: { page?: number; per_page?: number; status?: string }) =>
  request.get<SupplySourcePage>({ url: '/admin/supply-sources', params })

/** 单个货源详情 */
export const getSupplySource = (id: number) =>
  request.get<SupplySource>({ url: `/admin/supply-sources/${id}` })

/** 新建货源 */
export const createSupplySource = (data: Partial<SupplySource>) =>
  request.post<SupplySource>({ url: '/admin/supply-sources', data })

/** 更新货源(credentials 里 secret 类字段留空=不修改) */
export const updateSupplySource = (id: number, data: Partial<SupplySource>) =>
  request.put<SupplySource>({ url: `/admin/supply-sources/${id}`, data })

/** 删除货源 */
export const deleteSupplySource = (id: number) =>
  request.del({ url: `/admin/supply-sources/${id}` })

/** 测试连通(调 ping,返回余额/名称) */
export const testSupplySource = (id: number) =>
  request.post<SupplyTestResult>({ url: `/admin/supply-sources/${id}/test` })

/** 触发商品同步(mode: full 全量 / incremental 增量) */
export const syncSupplySource = (id: number, mode: 'full' | 'incremental' = 'incremental') =>
  request.post<{ ok: boolean; message: string; mode: string }>({ url: `/admin/supply-sources/${id}/sync`, data: { mode } })

/** 查询同步状态/进度 */
export const getSupplySyncStatus = (id: number) =>
  request.get<SupplySyncStatus>({ url: `/admin/supply-sources/${id}/sync-status` })

/** 上游商品(预览拉取,供勾选导入) */
export interface UpstreamProductItem {
  code: string
  name: string
  price: number          // 分
  factory_price: number  // 分
  cover: string | null
  stock: number
  already_imported: boolean
}
export interface UpstreamCategory {
  category_code: string | null
  category_name: string
  products: UpstreamProductItem[]
}
export interface SupplyPreviewResult {
  ok: boolean
  total: number
  categories: UpstreamCategory[]
  error?: string
}

/** 实时拉取上游商品(按分类树,供勾选导入) */
export const previewSupplyProducts = (id: number) =>
  request.get<SupplyPreviewResult>({ url: `/admin/supply-sources/${id}/products/preview` })

/** 勾选导入商品到本地 */
export const importSupplyProducts = (
  id: number,
  codes: string[],
  options?: { pricing?: { mode?: string; markup_percent?: number; markup_amount?: number }; save_default?: boolean }
) =>
  request.post<{ ok: boolean; imported: number; skipped: number; message: string; error?: string }>({
    url: `/admin/supply-sources/${id}/products/import`,
    data: { codes, ...options }
  })
