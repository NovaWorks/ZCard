import http from '@/utils/http'

export interface SubsiteDomain {
  id: number
  domain: string
  type: string
  verification_status: string
  status: string
  is_primary: boolean
  verified_at: string | null
}

export interface SubsiteMerchant {
  id: number
  user_id: number
  name: string
  slug: string
  status: number
  commission_rate: string
  settings: Record<string, any>
  owner?: { id: number; username: string }
  domains?: SubsiteDomain[]
}

export interface SubsiteProductSetting {
  id: number
  merchant_id: number
  product_id: number
  sku_id: number
  is_listed: boolean
  pricing_mode: string
  markup_percent: string
  fixed_markup_amount: number
  fixed_price_amount: number
  product?: { id: number; name: string; slug: string; price: number }
}

export interface SubsiteListParams {
  page?: number
  pageSize?: number
  keyword?: string
}

export const getSubsites = (params?: SubsiteListParams) =>
  http.get({ url: '/admin/subsites', params })

export const createSubsite = (data: {
  user_id: number
  name: string
  slug: string
  default_markup_percent?: number
  max_markup_percent?: number
}) => http.post({ url: '/admin/subsites', data })

export const updateDomain = (id: number, data: { status?: string; verification_status?: string }) =>
  http.put({ url: `/admin/subsites/domains/${id}`, data })

export const getSubsiteProductSettings = (merchantId: number, params?: { page?: number; pageSize?: number }) =>
  http.get({ url: `/admin/subsites/${merchantId}/product-settings`, params })

export const upsertSubsiteProductSetting = (data: {
  merchant_id: number
  product_id: number
  sku_id?: number
  is_listed?: boolean
  pricing_mode?: string
  markup_percent?: number
  fixed_markup_amount?: number
  fixed_price_amount?: number
}) => http.post({ url: '/admin/subsites/product-settings', data })
