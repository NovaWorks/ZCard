import { AppRouteRecord } from '@/types/router'

export const settingRoutes: AppRouteRecord = {
  name: 'SettingMgt',
  path: '/settingmgt',
  component: '/index/index',
  redirect: '/settingmgt/index',
  meta: {
    title: '店铺设置',
    icon: 'ri:settings-line',
    roles: ['R_SUPER', 'R_ADMIN']
  },
  children: [
    {
      path: 'index',
      name: 'SettingIndex',
      component: '/setting/index',
      meta: {
        title: '店铺设置',
        icon: 'ri:settings-3-line',
        keepAlive: false
      }
    }
  ]
}
