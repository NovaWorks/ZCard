import request from './request'

export interface AuthUser { id: number; username: string; email: string; balance: number }
export interface AuthResponse { token: string; user: AuthUser }

export const register = (data: { username: string; email: string; password: string }) =>
  request.post<unknown, AuthResponse>('/auth/register', data)

export const login = (data: { email: string; password: string }) =>
  request.post<unknown, AuthResponse>('/auth/login', data)

export const logout = () => request.post('/auth/logout')

export const getMe = () => request.get<unknown, AuthUser>('/auth/me')
