import { defineStore } from 'pinia'
import { getStorefrontSettings, type StorefrontSettings } from '@/api/settings'

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
    async load() {
      if (this.loaded) return
      this.config = await getStorefrontSettings()
      this.loaded = true
    },
    setView(v: 'grid' | 'list' | 'dual') {
      this.view = v
      localStorage.setItem('zcard_view', v)
    },
  },
})
