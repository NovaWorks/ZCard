import { createRouter, createWebHistory } from 'vue-router'
import DefaultLayout from '@/layouts/DefaultLayout.vue'

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
        { path: 'orders/query', name: 'order-query', component: () => import('@/views/OrderQuery.vue') },
        { path: 'login', name: 'login', component: () => import('@/views/Login.vue') },
        { path: 'register', name: 'register', component: () => import('@/views/Register.vue') },
      ],
    },
  ],
})

export default router
