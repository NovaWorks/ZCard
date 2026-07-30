/**
 * 用户管理 API（ZCard Admin）
 *
 * 后端 /api/admin/users，Sanctum 鉴权。
 * 余额以「分」为单位，前端展示需 / 100 转为元。
 */
import request from '@/utils/http'

/** 用户实体 */
export interface User {
  id: number
  username: string
  email: string
  /** 余额，单位：分 */
  balance: number
  status: number
  roles: string[]
  created_at: string
  updated_at: string
}

/** Laravel paginate 返回结构 */
export interface UserPage {
  data: User[]
  current_page: number
  total: number
  last_page: number
  per_page: number
}

/** 列表查询参数 */
export interface UserListParams {
  page?: number
  pageSize?: number
  keyword?: string
}

/** 新增/编辑表单 */
export interface UserForm {
  username: string
  email: string
  password?: string
  roles: string[]
  status?: number
}

/** 获取用户列表 */
export const getUsers = (params: UserListParams) =>
  request.get<UserPage>({ url: '/admin/users', params })

/** 获取用户详情 */
export const getUser = (id: number) => request.get<User>({ url: `/admin/users/${id}` })

/** 新增用户 */
export const createUser = (data: UserForm) =>
  request.post<User>({ url: '/admin/users', data })

/** 更新用户 */
export const updateUser = (id: number, data: Partial<UserForm>) =>
  request.put<User>({ url: `/admin/users/${id}`, data })

/** 删除用户 */
export const deleteUser = (id: number) => request.del<void>({ url: `/admin/users/${id}` })
