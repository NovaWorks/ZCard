/**
 * 认证相关 API（ZCard Sanctum）
 *
 * 注意：HTTP 拦截器已配置 baseURL = VITE_API_URL(/api)，
 * 此处路径均为相对于 /api 的相对路径。
 * ZCard 接口直接返回裸 JSON（无 code/msg/data 信封），拦截器已适配。
 */
import request from '@/utils/http'

/**
 * 登录
 * @param params 登录参数（email + password）
 * @returns { token, user }
 */
export function fetchLogin(params: Api.Auth.LoginParams) {
  return request.post<Api.Auth.LoginResponse>({
    url: '/auth/login',
    data: params
  })
}

/**
 * 获取当前登录用户信息
 * @returns 用户信息
 */
export function fetchGetUserInfo() {
  return request.get<Api.Auth.UserInfo>({
    url: '/auth/me'
  })
}

/**
 * 退出登录（吊销当前 Sanctum token）
 */
export function fetchLogout() {
  return request.post<void>({
    url: '/auth/logout'
  })
}
