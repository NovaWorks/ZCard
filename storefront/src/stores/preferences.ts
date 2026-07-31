import { defineStore } from 'pinia'
import { getCurrencies, type CurrencyListResponse } from '@/api/currency'
import type { CurrencyInfo } from '@/utils/money'
import i18n from '@/locales'

export const usePreferencesStore = defineStore('preferences', {
  state: () => ({
    baseCurrency: 'CNY',
    currencies: [] as CurrencyInfo[],
    currency: (localStorage.getItem('zcard_currency') || '') as string,
    language: (localStorage.getItem('zcard_language') || '') as string,
    loaded: false,
  }),
  getters: {
    currentCurrency(state): CurrencyInfo | undefined {
      return state.currencies.find((c) => c.code === state.currency)
        || state.currencies.find((c) => c.is_base)
    },
  },
  actions: {
    async load() {
      if (this.loaded) return
      try {
        const data: CurrencyListResponse = await getCurrencies()
        this.baseCurrency = data.base_currency
        this.currencies = data.currencies
        if (!this.currency) this.currency = data.base_currency
        this.loaded = true
      } catch {
        // /currencies 失败不阻塞渲染
      }
    },
    setCurrency(code: string) {
      this.currency = code
      localStorage.setItem('zcard_currency', code)
    },
    setLanguage(lang: string) {
      this.language = lang
      localStorage.setItem('zcard_language', lang)
      i18n.global.locale.value = lang
    },
  },
})
