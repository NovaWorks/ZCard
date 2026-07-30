import request from '@/utils/http'

export interface Sku {
  id?: number
  product_id?: number
  name: string
  price: number
  stock_type?: string
  sort?: number
  status?: boolean
}

export const getSkus = (productId: number) =>
  request.get<Sku[]>({ url: `/admin/products/${productId}/skus` })

export const createSku = (data: Sku) =>
  request.post<Sku>({ url: '/admin/products/skus', data })

export const updateSku = (id: number, data: Partial<Sku>) =>
  request.put<Sku>({ url: `/admin/products/skus/${id}`, data })

export const deleteSku = (id: number) =>
  request.del({ url: `/admin/products/skus/${id}` })
