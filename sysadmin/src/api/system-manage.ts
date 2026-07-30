/**
 * 后端控制模式的菜单 API（ZCard Admin）
 *
 * 仅用于后端控制模式下的菜单获取，前端控制模式（默认）不会调用。
 * 用户管理已迁移至 src/api/users.ts。
 */
import request from '@/utils/http'
import { AppRouteRecord } from '@/types/router'

/**
 * 获取菜单列表（后端控制模式使用）
 * @returns 菜单路由树
 */
export function fetchGetMenuList() {
  return request.get<AppRouteRecord[]>({
    url: '/api/v3/system/menus'
  })
}
