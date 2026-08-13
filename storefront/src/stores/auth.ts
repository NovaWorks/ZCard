import { defineStore } from 'pinia'
import { getMe, logout as apiLogout, type AuthUser } from '@/api/auth'
import { sessionToken } from '@/utils/sessionToken'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    // token 仅存内存(见 utils/sessionToken.ts),不落 localStorage。
    token: sessionToken.get(),
    user: null as AuthUser | null,
  }),
  getters: {
    // 内存 token 或已加载的用户(Cookie 会话)任一存在即视为已登录
    isLoggedIn: (state) => !!state.token || !!state.user,
  },
  actions: {
    setAuth(token: string, user: AuthUser) {
      sessionToken.set(token)
      this.token = token
      this.user = user
    },
    async fetchUser() {
      // 内存 token 或 HttpOnly Cookie 会话都可能有效:始终探测一次;
      // 401 时若内存无 token(匿名/Cookie 失效)拦截器不会跳登录,这里静默清理。
      try {
        this.user = await getMe()
      } catch (e: any) {
        if (e?.response?.status === 401) this.clearAuth()
      }
    },
    async logout() {
      try { await apiLogout() } catch {}
      this.clearAuth()
    },
    clearAuth() {
      sessionToken.clear()
      this.token = ''
      this.user = null
    },
  },
})
