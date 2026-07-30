import { AppRouteRecord } from '@/types/router'

export const orderRoutes: AppRouteRecord = {
  name: 'Order',
  path: '/order',
  component: '/index/index',
  redirect: '/order/list',
  meta: {
    title: '订单管理',
    icon: 'ri:file-list-3-line',
    roles: ['R_SUPER', 'R_ADMIN']
  },
  children: [
    {
      path: 'list',
      name: 'OrderList',
      component: '/order/list',
      meta: {
        title: '订单列表',
        icon: 'ri:list-check',
        keepAlive: true
      }
    }
  ]
}
