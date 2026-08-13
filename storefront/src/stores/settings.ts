import { defineStore } from 'pinia'
import { getStorefrontSettings, type StorefrontSettings } from '@/api/settings'

/** 前台配置本地缓存:刷新瞬间同步恢复,避免闪出默认品牌(ZCard)后再跳变 */
const CACHE_KEY = 'zcard_settings_v1'

export const useSettingsStore = defineStore('settings', {
  state: () => ({
    config: null as StorefrontSettings | null,
    loaded: false,
    view: (localStorage.getItem('zcard_view') || '') as 'grid' | 'list' | 'dual' | '',
  }),
  getters: {
    effectiveView(state): 'grid' | 'list' | 'dual' {
      return state.view || state.config?.list_default_view || 'grid'
    },
  },
  actions: {
    /** 启动时同步恢复上次配置(首帧不再闪默认值);随后由 refresh() 拉取最新覆盖 */
    restore() {
      if (this.config) return
      try {
        const raw = localStorage.getItem(CACHE_KEY)
        if (raw) {
          this.config = JSON.parse(raw)
          this.loaded = true
        }
      } catch {
        // 缓存损坏忽略,走网络
      }
    },
    async load() {
      this.restore()
      if (this.loaded && this.config) return
      await this.refresh()
    },
    /** 拉取最新配置并更新缓存 */
    async refresh() {
      this.config = await getStorefrontSettings()
      this.loaded = true
      try {
        localStorage.setItem(CACHE_KEY, JSON.stringify(this.config))
      } catch {
        // 存储满/隐私模式忽略,不影响功能
      }
    },
    setView(v: 'grid' | 'list' | 'dual') {
      this.view = v
      localStorage.setItem('zcard_view', v)
    },
  },
})
