import { AppRouteRecord } from '@/types/router'
import { dashboardRoutes } from './dashboard'
import { productRoutes } from './product'
import { orderRoutes } from './order'
import { cardRoutes } from './card'
import { userRoutes } from './user'
import { paymentRoutes } from './payment'
import { settingRoutes } from './setting'

/**
 * 导出所有模块化路由
 */
export const routeModules: AppRouteRecord[] = [
  dashboardRoutes,
  productRoutes,
  orderRoutes,
  cardRoutes,
  userRoutes,
  paymentRoutes,
  settingRoutes
]
