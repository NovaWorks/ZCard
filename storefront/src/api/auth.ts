import request from './request'

export interface AuthUser { id: number; username: string; email: string; balance: number }
export interface AuthResponse { token: string; user: AuthUser }

export const register = (data: { username: string; email: string; password: string; captcha?: string }) =>
  request.post<unknown, AuthResponse>('/auth/register', data)

export const login = (data: { email: string; password: string; captcha?: string }) =>
  request.post<unknown, AuthResponse>('/auth/login', data)

export const logout = () => request.post('/auth/logout')

export const getMe = () => request.get<unknown, AuthUser>('/auth/me')

/** 发送找回密码验证码 */
export const sendResetCode = (data: { email: string; captcha?: string }) =>
  request.post<unknown, { message: string }>('/auth/send-reset-code', data)

/** 重置密码 */
export const resetPassword = (data: { email: string; code: string; password: string }) =>
  request.post<unknown, { message: string }>('/auth/reset-password', data)
