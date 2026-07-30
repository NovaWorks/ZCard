import { AppRouteRecord } from '@/types/router'

export const memberRoutes: AppRouteRecord = {
  name: 'Member',
  path: '/member',
  component: '/index/index',
  redirect: '/member/level',
  meta: {
    title: '会员等级',
    icon: 'ri:vip-crown-line',
    roles: ['R_SUPER', 'R_ADMIN']
  },
  children: [
    {
      path: 'level',
      name: 'MemberLevel',
      component: '/member/level',
      meta: {
        title: '等级管理',
        roles: ['R_SUPER', 'R_ADMIN']
      }
    }
  ]
}
