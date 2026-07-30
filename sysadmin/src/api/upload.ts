import request from '@/utils/http'

export const uploadImage = (file: File) => {
  const formData = new FormData()
  formData.append('file', file)
  return request.post<{ path: string; url: string }>({
    url: '/admin/upload/image',
    data: formData,
    headers: { 'Content-Type': 'multipart/form-data' }
  })
}
