import { AppRouteRecord } from '@/types/router'

export const memberRoutes: AppRouteRecord = {
  name: 'Member',
  path: '/member',
  component: '/index/index',
  redirect: '/member/level',
  meta: {
    title: 'menus.member.title',
    icon: 'ri:vip-crown-line',
    roles: ['R_SUPER', 'R_ADMIN']
  },
  children: [
    {
      path: 'level',
      name: 'MemberLevel',
      component: '/member/level',
      meta: {
        title: 'menus.member.level',
        roles: ['R_SUPER', 'R_ADMIN']
      }
    }
  ]
}
