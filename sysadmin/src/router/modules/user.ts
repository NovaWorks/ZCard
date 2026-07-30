import { AppRouteRecord } from '@/types/router'

export const userRoutes: AppRouteRecord = {
  name: 'UserMgt',
  path: '/usermgt',
  component: '/index/index',
  redirect: '/usermgt/list',
  meta: {
    title: 'menus.user.title',
    icon: 'ri:user-line',
    roles: ['R_SUPER', 'R_ADMIN']
  },
  children: [
    {
      path: 'list',
      name: 'UserList',
      component: '/user/list',
      meta: {
        title: 'menus.user.list',
        icon: 'ri:list-check',
        keepAlive: true
      }
    }
  ]
}
