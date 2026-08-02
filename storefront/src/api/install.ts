import request from './request'

export interface EnvCheck {
  name: string
  passed: boolean
  optional?: boolean
}

export interface InstallStatus {
  installed: boolean
  php_version?: string
  checks?: EnvCheck[]
  writable?: EnvCheck[]
  all_passed?: boolean
  message?: string
}

export interface InstallPayload {
  db_host: string
  db_port: number
  db_database: string
  db_username: string
  db_password: string
  admin_email: string
  admin_password: string
}

export const getInstallStatus = () =>
  request.get<unknown, InstallStatus>('/install/status')

export const testDbConnection = (data: { host: string; port: number; database: string; username: string; password: string }) =>
  request.post<unknown, { success: boolean; message: string }>('/install/test-db', data, { timeout: 60000 })

export const runInstall = (data: InstallPayload) =>
  // 安装涉及 migrate(几十张表)+ key:generate + 角色权限,服务器上耗时较长,
  // 单独放宽到 5 分钟(全局默认 10s 不够)
  request.post<unknown, { success: boolean; message: string; admin_url?: string }>('/install/run', data, { timeout: 300000 })
