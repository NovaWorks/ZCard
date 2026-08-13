import axios from 'axios'
import { sessionToken } from '@/utils/sessionToken'

// withCredentials:携带 HttpOnly 会话 Cookie(Sanctum stateful 认证);
// 刷新后即使内存 token 丢失,也能凭 Cookie 恢复登录态。
const request = axios.create({
  baseURL: '/api',
  timeout: 10000,
  withCredentials: true,
})

// 请求拦截器：带内存 token(如有) + 货币/语言偏好头 + 保证写请求具备 CSRF Cookie
request.interceptors.request.use(async (config) => {
  const token = sessionToken.get()
  if (token) config.headers.Authorization = `Bearer ${token}`
  const cur = localStorage.getItem('zcard_currency')
  if (cur) config.headers['X-Currency'] = cur
  const lang = localStorage.getItem('zcard_language')
  if (lang) config.headers['X-Lang'] = lang

  // 非只读请求先确保 XSRF-TOKEN Cookie 存在(axios 会自动带 X-XSRF-TOKEN 头,
  // 满足 stateful 请求的 CSRF 校验);Cookie 已存在则跳过,避免多余请求。
  const method = (config.method || 'get').toLowerCase()
  if (!['get', 'head', 'options'].includes(method) && !document.cookie.includes('XSRF-TOKEN')) {
    try {
      await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
    } catch {
      // 忽略:同源 Cookie 不可用时后端会按 Bearer 模式处理
    }
  }
  return config
})

// 响应拦截器：统一错误
request.interceptors.response.use(
  (res) => res.data,
  (err) => {
    console.error('[API]', err?.response?.status, err?.message)
    // 401 = 登录态无效。仅当本次会话持有过内存 token(明确是 Bearer 过期)时才
    // 跳登录页;Cookie 会话失效(内存无 token)时静默,避免匿名访客被误跳转。
    if (
      err?.response?.status === 401 &&
      sessionToken.get() &&
      !window.location.pathname.startsWith('/login')
    ) {
      sessionToken.clear()
      const redirect = encodeURIComponent(window.location.pathname + window.location.search)
      window.location.href = `/login?redirect=${redirect}`
    }
    return Promise.reject(err)
  },
)

export default request
