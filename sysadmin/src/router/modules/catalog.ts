import { AppRouteRecord } from '@/types/router'

/**
 * 商品中心分组:商品管理 + 分类管理 + 卡密管理
 */
export const catalogRoutes: AppRouteRecord = {
  name: 'Catalog',
  path: '/catalog',
  component: '/index/index',
  redirect: '/product/list',
  meta: {
    title: 'menus.catalog.title',
    icon: 'ri:store-2-line',
    roles: ['R_SUPER', 'R_ADMIN'],
  },
  children: [
    {
      path: '/product/list',
      name: 'ProductList',
      component: '/product/list',
      meta: {
        title: 'menus.product.title',
        icon: 'ri:shopping-bag-3-line',
        keepAlive: true,
      },
    },
    {
      path: '/category/list',
      name: 'CategoryList',
      component: '/category/list',
      meta: {
        title: 'menus.category.title',
        icon: 'ri:price-tag-3-line',
        keepAlive: true,
      },
    },
    {
      path: '/card/list',
      name: 'CardList',
      component: '/card/list',
      meta: {
        title: 'menus.card.title',
        icon: 'ri:bank-card-line',
        keepAlive: true,
      },
    },
    {
      path: '/coupon/list',
      name: 'CouponList',
      component: '/coupon/list',
      meta: {
        title: 'menus.coupon.title',
        icon: 'ri:ticket-line',
        keepAlive: true,
      },
    },
  ],
}
