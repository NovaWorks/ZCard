import { AppRouteRecord } from '@/types/router'

export const paymentRoutes: AppRouteRecord = {
  name: 'Payment',
  path: '/payment',
  component: '/index/index',
  redirect: '/payment/list',
  meta: {
    title: 'menus.payment.title',
    icon: 'ri:wallet-line',
    roles: ['R_SUPER', 'R_ADMIN']
  },
  children: [
    {
      path: 'list',
      name: 'PaymentList',
      component: '/payment/list',
      meta: {
        title: 'menus.payment.list',
        icon: 'ri:bank-card-2-line',
        keepAlive: true
      }
    }
  ]
}
