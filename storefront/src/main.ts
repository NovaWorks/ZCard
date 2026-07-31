import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { useSettingsStore } from './stores/settings'
import { usePreferencesStore } from './stores/preferences'
import i18n from './locales'
import './assets/main.css'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
app.use(router)
app.use(i18n)

// 启动时加载店铺外观配置(供前台渲染)
const settingsStore = useSettingsStore(pinia)
settingsStore.load()

// 启动时加载货币列表(供价格展示与切换)
const prefsStore = usePreferencesStore(pinia)
prefsStore.load()

import { useAuthStore } from './stores/auth'
const authStore = useAuthStore(pinia)
authStore.fetchUser()

app.mount('#app')
