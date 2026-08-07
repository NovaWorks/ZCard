import axios from 'axios'

const request = axios.create({
  baseURL: '/api',
  timeout: 10000,
})

// 请求拦截器：带 token + 货币/语言偏好头
request.interceptors.request.use((config) => {
  const token = localStorage.getItem('zcard_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  const cur = localStorage.getItem('zcard_currency')
  if (cur) config.headers['X-Currency'] = cur
  const lang = localStorage.getItem('zcard_language')
  if (lang) config.headers['X-Lang'] = lang
  return config
})

// 响应拦截器：统一错误
request.interceptors.response.use(
  (res) => res.data,
  (err) => {
    console.error('[API]', err?.response?.status, err?.message)
    // 401 = token 无效/过期:清理登录态并引导重新登录(保留原路径,登录后跳回)。
    // 仅对登录用户的接口生效;公开接口(商品/验证码等)不会返回 401。
    if (err?.response?.status === 401 && !window.location.pathname.startsWith('/login')) {
      localStorage.removeItem('zcard_token')
      const redirect = encodeURIComponent(window.location.pathname + window.location.search)
      window.location.href = `/login?redirect=${redirect}`
    }
    return Promise.reject(err)
  },
)

export default request
