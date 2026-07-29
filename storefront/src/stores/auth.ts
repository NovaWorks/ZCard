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
      catch { this.clearAuth() }
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
