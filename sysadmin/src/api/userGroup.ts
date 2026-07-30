import request from '@/utils/http'

export interface UserGroup {
  id?: number
  name: string
  discount: number
  min_recharge: number
  sort: number
  status: boolean
  created_at?: string
}

export const getUserGroups = () =>
  request.get<UserGroup[]>({ url: '/admin/user-groups' })

export const createUserGroup = (data: Partial<UserGroup>) =>
  request.post<UserGroup>({ url: '/admin/user-groups', data })

export const updateUserGroup = (id: number, data: Partial<UserGroup>) =>
  request.put<UserGroup>({ url: `/admin/user-groups/${id}`, data })

export const deleteUserGroup = (id: number) =>
  request.del({ url: `/admin/user-groups/${id}` })
