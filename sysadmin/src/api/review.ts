import http from '@/utils/http'

export interface ReviewRecord {
  id: number
  product_id: number
  user_id: number
  rating: number
  content: string
  status: string
  created_at: string
  product?: { id: number; name: string }
  user?: { id: number; username: string }
}

export interface ReviewStats {
  total: number
  pending: number
  approved: number
  rejected: number
}

export const getReviews = (params?: any) =>
  http.get<{ data: ReviewRecord[]; current_page: number; total: number; last_page: number; per_page: number }>({
    url: '/admin/reviews',
    params,
  })

export const getReviewStats = () => http.get<ReviewStats>({ url: '/admin/reviews/stats' })

export const approveReview = (id: number) => http.post({ url: `/admin/reviews/${id}/approve` })

export const rejectReview = (id: number) => http.post({ url: `/admin/reviews/${id}/reject` })
