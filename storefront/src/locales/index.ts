import { createI18n } from 'vue-i18n'
import zh from './langs/zh.json'
import en from './langs/en.json'

const getInitialLocale = (): string => {
  const saved = localStorage.getItem('zcard_language')
  if (saved) return saved
  const nav = navigator.language?.toLowerCase() ?? ''
  return nav.startsWith('en') ? 'en' : 'zh'
}

const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: getInitialLocale(),
  fallbackLocale: 'zh',
  messages: { zh, en },
})

export default i18n
