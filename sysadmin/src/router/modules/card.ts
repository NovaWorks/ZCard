import { AppRouteRecord } from '@/types/router'

export const cardRoutes: AppRouteRecord = {
  name: 'Card',
  path: '/card',
  component: '/index/index',
  redirect: '/card/list',
  meta: {
    title: '卡密管理',
    icon: 'ri:bank-card-line',
    roles: ['R_SUPER', 'R_ADMIN']
  },
  children: [
    {
      path: 'list',
      name: 'CardList',
      component: '/card/list',
      meta: {
        title: '卡密列表',
        icon: 'ri:list-check',
        keepAlive: true
      }
    }
  ]
}
