import request from '@/utils/http'

/** 供货账号(下游对接本站的账号,持有 api_key/secret + 预存余额) */
export interface SupplierAccount {
  id: number
  name: string
  api_key: string
  /** 读回时已脱敏(末4位);仅创建/重置时后端返回明文 */
  api_secret: string
  balance: number
  status: 'active' | 'disabled'
  contact: string | null
  remark: string | null
  created_at?: string
  updated_at?: string
}

/** Laravel 分页结构 */
export interface SupplierAccountPage {
  data: SupplierAccount[]
  total: number
  current_page: number
  last_page: number
  per_page: number
}

/** 创建/重置 secret 时的响应(明文 secret 仅此一次) */
export interface SupplierSecretResponse {
  id: number
  name?: string
  api_key: string
  api_secret: string
  warning: string
}

/** 账本流水 */
export interface SupplierLedgerEntry {
  id: number
  supplier_account_id: number
  order_id: number | null
  type: 'recharge' | 'order' | 'refund' | 'adjust'
  amount: number
  balance_after: number
  idempotency_key: string
  remark: string | null
  created_at: string
}
export interface SupplierLedgerPage {
  data: SupplierLedgerEntry[]
  total: number
  current_page: number
  last_page: number
  per_page: number
}

/** 专属定价(账号维度) */
export interface SupplierProductPrice {
  id: number
  supplier_account_id: number
  product_id: number
  sku_id: number | null
  price: number
  product?: { id: number; name: string; slug: string }
  sku?: { id: number; name: string }
}
export interface SupplierPricePage {
  data: SupplierProductPrice[]
  total: number
  current_page: number
  last_page: number
  per_page: number
}

/** 账号列表(分页) */
export const getSupplierAccounts = (params?: { page?: number; per_page?: number; status?: string }) =>
  request.get<SupplierAccountPage>({ url: '/admin/supplier-accounts', params })

/** 单个账号详情(凭证脱敏) */
export const getSupplierAccount = (id: number) =>
  request.get<SupplierAccount>({ url: `/admin/supplier-accounts/${id}` })

/** 新建账号(返回明文 api_secret,仅此一次) */
export const createSupplierAccount = (data: { name: string; contact?: string; remark?: string }) =>
  request.post<SupplierSecretResponse>({ url: '/admin/supplier-accounts', data })

/** 更新账号(改名/状态/联系方式/备注) */
export const updateSupplierAccount = (id: number, data: Partial<Pick<SupplierAccount, 'name' | 'status' | 'contact' | 'remark'>>) =>
  request.put<SupplierAccount>({ url: `/admin/supplier-accounts/${id}`, data })

/** 删除账号 */
export const deleteSupplierAccount = (id: number) =>
  request.del({ url: `/admin/supplier-accounts/${id}` })

/** 重置 secret(返回新明文,仅此一次;旧的失效) */
export const resetSupplierSecret = (id: number) =>
  request.post<SupplierSecretResponse>({ url: `/admin/supplier-accounts/${id}/reset-secret` })

/** 充值预存(金额单位:分) */
export const rechargeSupplierAccount = (id: number, data: { amount: number; remark?: string }) =>
  request.post<{ balance: number }>({ url: `/admin/supplier-accounts/${id}/recharge`, data })

/** 手动调整余额(可正可负,单位:分) */
export const adjustSupplierAccount = (id: number, data: { amount: number; remark?: string }) =>
  request.post<{ balance: number }>({ url: `/admin/supplier-accounts/${id}/adjust`, data })

/** 账本流水 */
export const getSupplierLedger = (id: number, params?: { page?: number; per_page?: number }) =>
  request.get<SupplierLedgerPage>({ url: `/admin/supplier-accounts/${id}/ledger`, params })

/** 专属定价列表(账号维度) */
export const getSupplierPrices = (id: number, params?: { page?: number; per_page?: number; product_id?: number }) =>
  request.get<SupplierPricePage>({ url: `/admin/supplier-accounts/${id}/prices`, params })

/** 批量设置专属定价(账号维度) */
export const updateSupplierPrices = (id: number, data: { prices: Array<{ product_id: number; sku_id?: number | null; price: number }> }) =>
  request.put<{ ok: boolean; count: number }>({ url: `/admin/supplier-accounts/${id}/prices`, data })

/** 删除某条专属定价 */
export const deleteSupplierPrice = (accountId: number, priceId: number) =>
  request.del({ url: `/admin/supplier-accounts/${accountId}/prices/${priceId}` })
