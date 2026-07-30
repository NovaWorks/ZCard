import { AppRouteRecord } from '@/types/router'

export const userRoutes: AppRouteRecord = {
  name: 'UserMgt',
  path: '/usermgt',
  component: '/index/index',
  redirect: '/usermgt/list',
  meta: {
    title: '用户管理',
    icon: 'ri:user-line',
    roles: ['R_SUPER', 'R_ADMIN']
  },
  children: [
    {
      path: 'list',
      name: 'UserList',
      component: '/user/list',
      meta: {
        title: '用户列表',
        icon: 'ri:list-check',
        keepAlive: true
      }
    }
  ]
}
