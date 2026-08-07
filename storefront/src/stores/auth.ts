import { defineStore } from 'pinia'
import { getMe, logout as apiLogout, type AuthUser } from '@/api/auth'
import request from '@/api/request'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('zcard_token') || '',
    user: null as AuthUser | null,
  }),
  getters: {
    isLoggedIn: (state) => !!state.token,
  },
  actions: {
    setAuth(token: string, user: AuthUser) {
      this.token = token
      this.user = user
      localStorage.setItem('zcard_token', token)
    },
    async fetchUser() {
      if (!this.token) return
      try { this.user = await getMe() }
      catch (e: any) {
        // 仅当 token 被后端明确拒绝(401)时才清除登录态;
        // 网络错误/服务器 5xx/超时等瞬时故障保留 token,避免已登录用户被误登出后
        // 点击充值/验证码等入口被路由守卫拦回登录页(老客户反馈"我已登录却提示先登录")。
        if (e?.response?.status === 401) this.clearAuth()
      }
    },
    async logout() {
      try { await apiLogout() } catch {}
      this.clearAuth()
    },
    clearAuth() {
      this.token = ''
      this.user = null
      localStorage.removeItem('zcard_token')
    },
  },
})
