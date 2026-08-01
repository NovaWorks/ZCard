import { AppRouteRecord } from '@/types/router'

/**
 * 交易管理分组:订单管理 + 支付设置
 */
export const tradeRoutes: AppRouteRecord = {
  name: 'Trade',
  path: '/trade',
  component: '/index/index',
  redirect: '/order/list',
  meta: {
    title: 'menus.trade.title',
    icon: 'ri:exchange-dollar-line',
    roles: ['R_SUPER', 'R_ADMIN'],
  },
  children: [
    {
      path: '/order/list',
      name: 'OrderList',
      component: '/order/list',
      meta: {
        title: 'menus.order.title',
        icon: 'ri:file-list-3-line',
        keepAlive: true,
      },
    },
    {
      path: '/payment/list',
      name: 'PaymentList',
      component: '/payment/list',
      meta: {
        title: 'menus.payment.title',
        icon: 'ri:wallet-line',
        keepAlive: true,
      },
    },
    {
      path: '/commissionmgt/index',
      name: 'CommissionIndex',
      component: '/commission/list/index',
      meta: {
        title: 'menus.commission.title',
        icon: 'ri:share-line',
        keepAlive: false,
      },
    },
  ],
}
