import { AppRouteRecord } from '@/types/router'

export const productRoutes: AppRouteRecord = {
  name: 'Product',
  path: '/product',
  component: '/index/index',
  redirect: '/product/list',
  meta: {
    title: '商品管理',
    icon: 'ri:shopping-bag-3-line',
    roles: ['R_SUPER', 'R_ADMIN']
  },
  children: [
    {
      path: 'list',
      name: 'ProductList',
      component: '/product/list',
      meta: {
        title: '商品列表',
        icon: 'ri:list-check',
        keepAlive: true
      }
    }
  ]
}
