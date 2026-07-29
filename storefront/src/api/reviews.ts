import request from './request'

export interface ReviewItem {
  id: string | number
  name: string
  rating: number
  content: string
  created_at: string | null
}

export interface ReviewData {
  rating: number
  count: number
  list: ReviewItem[]
}

export const getProductReviews = (slug: string) =>
  request.get<unknown, ReviewData>(`/products/${slug}/reviews`)

export const createReview = (data: {
  product_id: number
  order_id: number
  rating: number
  content?: string
}) => request.post<unknown, { id: number; status: string }>('/reviews', data)
