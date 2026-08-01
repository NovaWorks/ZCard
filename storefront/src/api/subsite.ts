import request from './request'

export interface SubsiteInfo {
  id: number
  name: string
  slug: string
  settings: Record<string, any>
  domains: Array<{
    id: number
    domain: string
    type: string
    status: string
    verification_status: string
  }>
}

export interface SubsiteFinance {
  total_profit: number
  available: number
  pending: number
}

export interface SubsiteLedgerEntry {
  id: number
  type: string
  amount: number
  status: string
  available_at: string | null
  remark: string
  created_at: string
}

export interface SubsiteProductSetting {
  id: number
  product_id: number
  is_listed: boolean
  pricing_mode: string
  markup_percent: string
  product?: { id: number; name: string; slug: string; price: number }
}

export const getMySubsite = () => request.get<unknown, SubsiteInfo>('/subsite-console/')
export const getSubsiteFinance = () => request.get<unknown, SubsiteFinance>('/subsite-console/finance')
export const getSubsiteLedger = () => request.get<unknown, SubsiteLedgerEntry[]>('/subsite-console/ledger')
export const bindSubsiteDomain = (data: { domain: string; type: string }) =>
  request.post('/subsite-console/domains', data)
export const getSubsiteProductSettings = () =>
  request.get<unknown, SubsiteProductSetting[]>('/subsite-console/product-settings')
export const upsertSubsiteProductSetting = (data: any) =>
  request.post('/subsite-console/product-settings', data)
export const requestSubsiteWithdrawal = (data: {
  amount: number
  method: string
  account: string
  account_name: string
}) => request.post('/subsite-console/withdrawals', data)
export const updateSubsiteBranding = (data: { site_name?: string; logo?: string; announcement?: string }) =>
  request.put('/subsite-console/branding', data)
