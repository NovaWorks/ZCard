import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { useSettingsStore } from './stores/settings'
import './assets/main.css'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
app.use(router)

// 启动时加载店铺外观配置(供前台渲染)
const settingsStore = useSettingsStore(pinia)
settingsStore.load()

app.mount('#app')
