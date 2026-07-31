import { AppRouteRecord } from '@/types/router'
import { dashboardRoutes } from './dashboard'
import { catalogRoutes } from './catalog'
import { tradeRoutes } from './trade'
import { customerRoutes } from './customer'
import { systemRoutes } from './system'

/**
 * 菜单分组路由(5 个分组:概览 / 商品中心 / 交易管理 / 用户中心 / 系统设置)
 */
export const routeModules: AppRouteRecord[] = [
  dashboardRoutes,
  catalogRoutes,
  tradeRoutes,
  customerRoutes,
  systemRoutes,
]
