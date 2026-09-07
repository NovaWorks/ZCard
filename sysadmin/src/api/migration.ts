import http from '@/utils/http'

/** 1.x → 2.0 数据迁移·源端控制台 API（真正搬数据由 2.0 的 zcard migrate-from-v1 执行） */

export interface MigrationCheck {
  name: string
  status: 'ok' | 'warn' | 'fail' | 'info'
  message: string
}

export interface MigrationPreflight {
  version: string
  ready: boolean
  checks: MigrationCheck[]
  cards: { sampled: number; encrypted: number; plaintext: number; failed: number }
  stats: Record<string, number | null>
  pending: { orders: number | null; payments: number | null }
  trashed: { users: number; products: number } | null
  keys: { app_key: boolean; card_key: 'settings' | 'env' | 'missing' }
}

export interface MigrationCommand {
  steps: { title: string; detail: string }[]
  commands: { dry_run: string; run: string; phases_main_first: string; verify: string }
  notes: string[]
}

export interface MigrationKeys {
  filename: string
  content: string
  card_key_source: string
}

export const getMigrationPreflight = () =>
  http.get<MigrationPreflight>({ url: '/admin/migration/preflight', timeout: 60000 })

export const getMigrationCommand = () =>
  http.get<MigrationCommand>({ url: '/admin/migration/command' })

// 密钥包含数据库凭据与加密密钥，前端弹窗二次确认后调用
export const getMigrationKeys = () => http.get<MigrationKeys>({ url: '/admin/migration/keys' })
