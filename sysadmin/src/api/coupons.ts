import http from '@/utils/http'
import axios from 'axios'
import { useUserStore } from '@/store/modules/user'

export interface Coupon {
  id: number
  code: string
  type: string // fixed / percent
  value: number
  product_id: number | null
  product?: { id: number; name: string } | null
  category_id: number | null
  category?: { id: number; name: string } | null
  min_amount: number
  status: string // active / used / disabled
  expires_at: string | null
  used_at: string | null
  used_by: number | null
  order_id: number | null
  note: string
  created_at: string
}

export interface CouponPage {
  data: Coupon[]
  total: number
  current_page: number
  last_page: number
  per_page: number
}

export interface CouponStats {
  active_count: number
  used_count: number
  disabled_count: number
  total_count: number
}

export interface CouponListParams {
  page?: number
  pageSize?: number
  keyword?: string
  status?: string
  type?: string
  start_date?: string
  end_date?: string
}

export const getCoupons = (params: CouponListParams) =>
  http.get<CouponPage>({ url: '/admin/coupons', params })

export const getCouponStats = () =>
  http.get<CouponStats>({ url: '/admin/coupons/stats' })

export const createCoupons = (data: {
  count: number; type: string; value: number;
  product_id?: number; category_id?: number; min_amount?: number;
  expires_at?: string; note?: string
}) => http.post<{ count: number; codes: string[] }>({ url: '/admin/coupons', data })

export const toggleCoupon = (id: number) =>
  http.post<Coupon>({ url: `/admin/coupons/toggle/${id}` })

export const deleteCoupon = (id: number) =>
  http.del({ url: `/admin/coupons/${id}` })

/** 导出筛选后的优惠券为 CSV(直接用 axios+blob,不走封装 http) */
export const exportCoupons = async (params: CouponListParams): Promise<{ filename: string; blob: Blob }> => {
  const { VITE_API_URL } = import.meta.env
  const { accessToken } = useUserStore()

  const res = await axios.get(`${VITE_API_URL}/admin/coupons/export`, {
    params,
    responseType: 'blob',
    headers: accessToken ? { Authorization: `Bearer ${accessToken}` } : {}
  })

  let filename = `coupons-export-${Date.now()}.csv`
  const cd = res.headers['content-disposition'] as string | undefined
  if (cd) {
    const match = cd.match(/filename\*?=(?:UTF-8'')?["']?([^"';]+)/i)
    if (match) filename = decodeURIComponent(match[1])
  }
  return { filename, blob: res.data as Blob }
}
