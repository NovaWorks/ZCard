import { AppRouteRecord } from '@/types/router'

export const orderRoutes: AppRouteRecord = {
  name: 'Order',
  path: '/order',
  component: '/index/index',
  redirect: '/order/list',
  meta: {
    title: 'menus.order.title',
    icon: 'ri:file-list-3-line',
    roles: ['R_SUPER', 'R_ADMIN']
  },
  children: [
    {
      path: 'list',
      name: 'OrderList',
      component: '/order/list',
      meta: {
        title: 'menus.order.list',
        icon: 'ri:list-check',
        keepAlive: true
      }
    }
  ]
}
