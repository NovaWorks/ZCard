import { AppRouteRecord } from '@/types/router'
import { dashboardRoutes } from './dashboard'
import { productRoutes } from './product'
import { systemRoutes } from './system'

/**
 * 导出所有模块化路由
 */
export const routeModules: AppRouteRecord[] = [dashboardRoutes, productRoutes, systemRoutes]
