import { AppRouteRecord } from '@/types/router'

export const categoryRoutes: AppRouteRecord = {
  name: 'Category',
  path: '/category',
  component: '/index/index',
  redirect: '/category/list',
  meta: {
    title: 'menus.category.title',
    icon: 'ri:price-tag-3-line',
    roles: ['R_SUPER', 'R_ADMIN']
  },
  children: [
    {
      path: 'list',
      name: 'CategoryList',
      component: '/category/list',
      meta: {
        title: 'menus.category.list',
        roles: ['R_SUPER', 'R_ADMIN']
      }
    }
  ]
}
