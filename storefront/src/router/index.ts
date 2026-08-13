import { createRouter, createWebHistory } from 'vue-router'
import DefaultLayout from '@/layouts/DefaultLayout.vue'
import { useSettingsStore } from '@/stores/settings'
import { setSeo } from '@/utils/seo'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      component: DefaultLayout,
      children: [
        { path: '', name: 'home', component: () => import('@/views/Home.vue') },
        { path: 'product/:id', name: 'product', component: () => import('@/views/Product.vue') },
        { path: 'checkout', name: 'checkout', component: () => import('@/views/Checkout.vue') },
        { path: 'pay/:orderNo', name: 'pay', component: () => import('@/views/Pay.vue') },
        { path: 'pay/result', name: 'pay-result', component: () => import('@/views/PayResult.vue') },
        { path: 'orders/query', name: 'order-query', component: () => import('@/views/OrderQuery.vue') },
        { path: 'orders/mine', name: 'my-orders', component: () => import('@/views/MyOrders.vue'), meta: { requiresAuth: true } },
        { path: 'user', name: 'user-center', component: () => import('@/views/UserCenter.vue'), meta: { requiresAuth: true } },
        { path: 'distribution', name: 'distribution', component: () => import('@/views/Distribution.vue'), meta: { requiresAuth: true } },
        { path: 'my-subsite', name: 'my-subsite', component: () => import('@/views/MySubsite.vue'), meta: { requiresAuth: true } },
        { path: 'withdraw', name: 'withdraw', component: () => import('@/views/Withdraw.vue'), meta: { requiresAuth: true } },
        { path: 'recharge', name: 'recharge', component: () => import('@/views/Recharge.vue'), meta: { requiresAuth: true } },
        { path: 'recharge/pay/:rechargeNo', name: 'recharge-pay', component: () => import('@/views/RechargePay.vue'), meta: { requiresAuth: true } },
        { path: 'recharge/result', name: 'recharge-result', component: () => import('@/views/RechargeResult.vue'), meta: { requiresAuth: true } },
        { path: 'login', name: 'login', component: () => import('@/views/Login.vue') },
        { path: 'register', name: 'register', component: () => import('@/views/Register.vue') },
        { path: 'forget-password', name: 'forget-password', component: () => import('@/views/ForgetPassword.vue') },
      ],
    },
    // 安装向导(独立路由,不在 DefaultLayout 内)
    { path: '/install', name: 'install', component: () => import('@/views/Install.vue') },
  ],
})

// 捕获 ?ref= 邀请码 → localStorage(用于注册时带上 referrer);并对受限路由做登录守卫
router.beforeEach(async (to) => {
  const ref = to.query.ref
  if (typeof ref === 'string' && ref) {
    localStorage.setItem('zcard_ref', ref)
  }
  if (to.meta.requiresAuth) {
    const { useAuthStore } = await import('@/stores/auth')
    const auth = useAuthStore()
    if (!auth.isLoggedIn) {
      // 内存 token 已丢但 HttpOnly Cookie 会话可能仍有效:探测一次再决定
      try { await auth.fetchUser() } catch {}
    }
    if (!auth.isLoggedIn) {
      return { name: 'login', query: { redirect: to.fullPath } }
    }
  }
})

// 路由切换后重置为站点默认 SEO(商品等页面加载后再覆盖标题/描述)
router.afterEach(() => {
  const settings = useSettingsStore()
  if (settings.config) {
    setSeo({
      title: settings.config.site_name || 'ZCard',
      description: settings.config.site_description || '',
      keywords: settings.config.site_keywords || '',
      type: 'website',
    })
  }
})

export default router
