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
  request.post<unknown, { success: boolean; message: string }>('/install/test-db', data)

export const runInstall = (data: InstallPayload) =>
  request.post<unknown, { success: boolean; message: string; admin_url?: string }>('/install/run', data)
