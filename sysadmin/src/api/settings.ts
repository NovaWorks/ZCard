/**
 * 店铺设置 API（ZCard Admin）
 *
 * 后端 /api/admin/settings，Sanctum 鉴权。
 * 设置以扁平键值对存储，前端按分组渲染。
 */
import request from '@/utils/http'

/** 设置项（扁平键值对） */
export type Settings = Record<string, string | number | boolean | null>

/** 获取店铺设置 */
export const getSettings = () => request.get<Settings>({ url: '/admin/settings' })

/** 更新店铺设置 */
export const updateSettings = (data: Settings) =>
  request.put<Settings>({ url: '/admin/settings', data })
