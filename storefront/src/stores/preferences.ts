import { defineStore } from 'pinia'
import { getCurrencies, type CurrencyListResponse } from '@/api/currency'
import type { CurrencyInfo } from '@/utils/money'
import i18n from '@/locales'
import { useSettingsStore } from '@/stores/settings'

export const usePreferencesStore = defineStore('preferences', {
  state: () => ({
    baseCurrency: 'CNY',
    currencies: [] as CurrencyInfo[],
    currency: (localStorage.getItem('zcard_currency') || '') as string,
    language: (localStorage.getItem('zcard_language') || '') as string,
    languages: ['zh', 'en'] as string[],
    loaded: false,
  }),
  getters: {
    currentCurrency(state): CurrencyInfo | undefined {
      return state.currencies.find((c) => c.code === state.currency)
        || state.currencies.find((c) => c.is_base)
    },
    /** 基础货币元信息(账户类金额:余额/佣金/账单/提现,始终以基础货币记账展示) */
    baseCurrencyInfo(state): CurrencyInfo | undefined {
      return state.currencies.find((c) => c.code === state.baseCurrency)
        || state.currencies.find((c) => c.is_base)
    },
    /** 按 code 取货币元信息(展示金额时优先用后端返回的 display_currency,保证符号与金额一致) */
    currencyOf: (state) => (code: string | null | undefined): CurrencyInfo | undefined => {
      if (code) {
        const hit = state.currencies.find((c) => c.code === code)
        if (hit) return hit
      }
      return state.currencies.find((c) => c.is_base)
    },
  },
  actions: {
    async load() {
      if (this.loaded) return
      // 记录用户是否已保存货币选择:load 末尾统一写回,保证后续请求带正确 X-Currency
      const savedCurrency = this.currency
      try {
        const data: CurrencyListResponse = await getCurrencies()
        this.baseCurrency = data.base_currency
        this.currencies = data.currencies
      } catch {
        // /currencies 失败不阻塞渲染
      }
      // 读取后台设置以应用默认语言/货币与启用语言列表
      try {
        const settings = useSettingsStore()
        await settings.load()
        const cfg = settings.config
        if (cfg) {
          this.languages = Array.isArray(cfg.enabled_languages) && cfg.enabled_languages.length
            ? cfg.enabled_languages
            : ['zh', 'en']
          // 默认语言:仅在用户未保存选择时应用
          if (!this.language) {
            const dl = cfg.default_language || 'zh'
            this.language = dl
            localStorage.setItem('zcard_language', dl)
            i18n.global.locale.value = dl as 'zh' | 'en'
          }
        }
      } catch {
        // 设置加载失败不阻塞渲染
      }
      // 货币优先级:用户已保存选择 > 后台默认显示货币 > 基础货币。
      // 必须先判断「是否已保存」再决定用哪个,否则 base 会先占位导致默认货币永远不生效。
      if (this.currencies.length && this.currencies.some((c) => c.code === savedCurrency)) {
        this.currency = savedCurrency
      } else {
        const cfg = useSettingsStore().config
        this.currency = cfg?.default_display_currency || this.baseCurrency
      }
      // 写回 localStorage:保证后续 API 请求带正确的 X-Currency 头(此前只改 store 状态,
      // request.ts 读 localStorage 拿不到默认货币 → 后端回退基础货币,手机首访显示 CNY)
      localStorage.setItem('zcard_currency', this.currency)
      this.loaded = true
    },
    setCurrency(code: string) {
      this.currency = code
      localStorage.setItem('zcard_currency', code)
    },
    setLanguage(lang: string) {
      this.language = lang
      localStorage.setItem('zcard_language', lang)
      i18n.global.locale.value = lang as 'zh' | 'en'
    },
  },
})
