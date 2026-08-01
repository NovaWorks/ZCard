import http from '@/utils/http'

export interface UpdateCheck {
  current_version: string
  latest_version: string
  has_update: boolean
  release_url: string
  release_notes: string
  published_at: string
}

export interface VersionInfo {
  version: string
  url: string
  notes: string
  published_at: string
  prerelease: boolean
}

export interface UpdateResult {
  message: string
  old_version?: string
  new_version?: string
  log: string
}

export interface UpdateLog {
  running: boolean
  log: string
}

export const checkUpdate = () => http.get<UpdateCheck>({ url: '/admin/update/check' })

export const getVersions = () => http.get<VersionInfo[]>({ url: '/admin/update/versions' })

// 更新执行耗时 30s-2min,设置 3 分钟超时
export const runUpdate = () =>
  http.post<UpdateResult>({ url: '/admin/update/run', timeout: 180000 })

export const getUpdateLog = () => http.get<UpdateLog>({ url: '/admin/update/log' })
