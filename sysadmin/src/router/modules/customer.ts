import { AppRouteRecord } from '@/types/router'

/**
 * 用户中心分组:用户管理 + 会员等级
 */
export const customerRoutes: AppRouteRecord = {
  name: 'Customer',
  path: '/customer',
  component: '/index/index',
  redirect: '/usermgt/list',
  meta: {
    title: 'menus.customer.title',
    icon: 'ri:user-settings-line',
    roles: ['R_SUPER', 'R_ADMIN'],
  },
  children: [
    {
      path: '/usermgt/list',
      name: 'UserList',
      component: '/user/list',
      meta: {
        title: 'menus.user.title',
        icon: 'ri:user-line',
        keepAlive: true,
      },
    },
    {
      path: '/member/level',
      name: 'MemberLevel',
      component: '/member/level',
      meta: {
        title: 'menus.member.title',
        icon: 'ri:vip-crown-line',
        keepAlive: true,
      },
    },
    {
      path: '/bill/list',
      name: 'BillList',
      component: '/bill/list',
      meta: {
        title: 'menus.bill.title',
        icon: 'ri:receipt-line',
        keepAlive: true,
      },
    },
    {
      path: '/withdrawal/list',
      name: 'WithdrawalList',
      component: '/withdrawal/list',
      meta: {
        title: 'menus.withdrawal.title',
        icon: 'ri:bank-card-2-line',
        keepAlive: true,
      },
    },
  ],
}
