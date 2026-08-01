import { AppRouteRecord } from '@/types/router'

/**
 * 系统设置分组:店铺设置
 */
export const systemRoutes: AppRouteRecord = {
  name: 'System',
  path: '/system',
  component: '/index/index',
  redirect: '/settingmgt/index',
  meta: {
    title: 'menus.system_group.title',
    icon: 'ri:settings-3-line',
    roles: ['R_SUPER', 'R_ADMIN'],
  },
  children: [
    {
      path: '/settingmgt/index',
      name: 'SettingIndex',
      component: '/setting/index',
      meta: {
        title: 'menus.setting.title',
        icon: 'ri:settings-line',
        keepAlive: false,
      },
    },
    {
      path: '/currencymgt/index',
      name: 'CurrencyIndex',
      component: '/currency/list/index',
      meta: {
        title: 'menus.currency.title',
        icon: 'ri:coins-line',
        keepAlive: false,
      },
    },
    {
      path: '/subsitemgt/index',
      name: 'SubsiteIndex',
      component: '/subsite/list/index',
      meta: {
        title: 'menus.subsite.title',
        icon: 'ri:global-line',
        keepAlive: false,
      },
    },
  ],
}
