import request from '@/utils/http'

/** 单个货源(上游对接配置) */
export interface SupplySource {
  id: number
  name: string
  driver: 'dujiao_next' | 'acg_faka' | 'zcard'
  base_url: string
  /** 凭证(读回时已脱敏:secret/app_key 显示末4位) */
  credentials: Record<string, string>
  status: 'active' | 'disabled'
  settings: Record<string, any> | null
  last_synced_at: string | null
  last_error: string | null
  balance_cache: number | null
  /** 定时任务上次执行时间(调度器写) */
  last_collect_at?: string | null
  last_price_sync_at?: string | null
  last_status_sync_at?: string | null
  sort: number
  created_at?: string
  updated_at?: string
}

/** Laravel 分页结构 */
export interface SupplySourcePage {
  data: SupplySource[]
  total: number
  current_page: number
  last_page: number
  per_page: number
}

/** 驱动元数据(用于动态渲染凭证表单) */
export interface DriverConfigField {
  key: string
  label: string
  type: 'text' | 'number' | 'url' | 'secret'
  required?: boolean
  help?: string
}
export interface SupplyDriver {
  driver: string
  name: string
  icon: string | null
  config_schema: Record<string, { type: string; label: string; required?: boolean; help?: string }>
}

/** 测试连通结果 */
export interface SupplyTestResult {
  connected: boolean
  name?: string | null
  balance?: number | null
  currency?: string | null
  error?: string | null
}

/** 同步状态 */
export interface SupplySyncStatus {
  ok: boolean
  last_synced_at: string | null
  last_error: string | null
}

/** 驱动元数据(选平台下拉 + 动态凭证字段) */
export const getSupplyDrivers = () =>
  request.get<{ drivers: SupplyDriver[] }>({ url: '/admin/supply-sources/drivers' })

/** 货源列表(分页) */
export const getSupplySources = (params?: { page?: number; per_page?: number; status?: string }) =>
  request.get<SupplySourcePage>({ url: '/admin/supply-sources', params })

/** 单个货源详情 */
export const getSupplySource = (id: number) =>
  request.get<SupplySource>({ url: `/admin/supply-sources/${id}` })

/** 新建货源 */
export const createSupplySource = (data: Partial<SupplySource>) =>
  request.post<SupplySource>({ url: '/admin/supply-sources', data })

/** 更新货源(credentials 里 secret 类字段留空=不修改) */
export const updateSupplySource = (id: number, data: Partial<SupplySource>) =>
  request.put<SupplySource>({ url: `/admin/supply-sources/${id}`, data })

/** 删除货源 */
export const deleteSupplySource = (id: number) =>
  request.del({ url: `/admin/supply-sources/${id}` })

/** 测试连通(调 ping,返回余额/名称) */
export const testSupplySource = (id: number) =>
  request.post<SupplyTestResult>({ url: `/admin/supply-sources/${id}/test` })

/** 触发商品同步(mode: full 全量 / incremental 增量) */
export interface SupplySyncTask {
  id: number
  supply_source_id: number
  mode: 'full' | 'incremental'
  /** 任务类型:collect=采集商品 | price=同步价格 | status=同步上下架 */
  scope: 'collect' | 'price' | 'status'
  force_reprice: boolean
  status: 'queued' | 'running' | 'cancelling' | 'success' | 'failed' | 'cancelled' | 'timed_out'
  total_products: number
  processed_products: number
  created_count: number
  updated_count: number
  price_updated_count: number
  manual_price_skipped_count: number
  hidden_count: number
  deleted_count: number
  error: string | null
  error_code: string | null
  error_context: Record<string, unknown> | null
  started_at: string | null
  heartbeat_at: string | null
  current_stage: string | null
  current_page: number
  stage_current: number
  stage_total: number
  cancel_requested_at: string | null
  cancel_requested_by: number | null
  cancel_requested_by_name: string | null
  cancel_request_ip: string | null
  cancel_reason: string | null
  cancel_trigger: 'admin' | 'system' | null
  worker_version: string | null
  finished_at: string | null
  created_at: string
}

export const syncSupplySource = (
  id: number,
  mode: 'full' | 'incremental' = 'incremental',
  forceReprice = false
) =>
  request.post<{ ok: boolean; task: SupplySyncTask }>({
    url: `/admin/supply-sources/${id}/sync`,
    data: { mode, force_reprice: forceReprice }
  })

/** 同步任务列表(最新优先) */
export const getSupplySyncTasks = (id: number) =>
  request.get<{ ok: boolean; tasks: SupplySyncTask[] }>({
    url: `/admin/supply-sources/${id}/sync-tasks`
  })

/** 取消进行中的同步任务，并记录可选原因 */
export const cancelSupplySync = (id: number, taskId?: number, reason?: string) =>
  request.post<{ ok: boolean; task: SupplySyncTask }>({
    url: `/admin/supply-sources/${id}/sync-cancel`,
    data: { task_id: taskId, reason: reason || undefined }
  })

export interface SupplySyncTaskWithSource extends SupplySyncTask {
  source_name: string
}

/** 全部货源同步任务(含货源名) */
export const getAllSyncTasks = (params?: any) =>
  request.get<{ ok: boolean; tasks: SupplySyncTaskWithSource[] }>({
    url: '/admin/supply-sources/sync-tasks',
    params
  })

/** 派发队列探针(检测 queue:work 是否运行) */
export const probeSyncQueue = () =>
  request.post<{ ok: boolean }>({ url: '/admin/supply-sources/sync-queue-probe' })

/** 队列心跳状态 */
export interface SupplyQueueStatus {
  ok: boolean
  heartbeat_at: number | null
  connection: string
  healthy: boolean
  status: 'healthy' | 'busy' | 'unavailable'
  probe_healthy: boolean
  active_task_id: number | null
  active_task_heartbeat_at: number | null
  app_version: string
  worker_version: string | null
  worker_started_at: number | null
  version_match: boolean
}

export const getSyncQueueStatus = () =>
  request.get<SupplyQueueStatus>({
    url: '/admin/supply-sources/sync-queue-status'
  })

/** 上游商品(预览拉取,供勾选导入) */
export interface UpstreamProductItem {
  code: string
  name: string
  price: number // 分
  factory_price: number // 分
  cover: string | null
  stock: number
  already_imported: boolean
}
export interface UpstreamCategory {
  category_code: string | null
  category_name: string
  products: UpstreamProductItem[]
}
export interface SupplyPreviewResult {
  ok: boolean
  total: number
  categories: UpstreamCategory[]
  error?: string
}

/** 实时拉取上游商品(按分类树,供勾选导入)。
 * 上游商品多时耗时可能超过默认 15s,单独放宽到 120s。 */
export const previewSupplyProducts = (id: number) =>
  request.get<SupplyPreviewResult>({
    url: `/admin/supply-sources/${id}/products/preview`,
    timeout: 120000
  })

/** 勾选导入商品到本地 */
export const importSupplyProducts = (
  id: number,
  codes: string[],
  options?: {
    pricing?: { mode?: string; markup_percent?: number; markup_amount?: number }
    save_default?: boolean
    category_map?: Record<string, number | null>
  }
) =>
  request.post<{ ok: boolean; imported: number; skipped: number; message: string; error?: string }>(
    {
      url: `/admin/supply-sources/${id}/products/import`,
      data: { codes, ...options }
    }
  )

/** ===== 定时任务计划(settings.schedule) ===== */

/** 执行时间窗口:留空数组=全天 */
export interface ScheduleWindow {
  start: string // "HH:mm"
  end: string // "HH:mm"
}

/** 单类任务计划 */
export interface TaskSchedule {
  enabled: boolean
  /** 执行间隔(分钟) */
  interval: number | null
  /** 仅采集商品:incremental 增量 / full 全量 */
  mode?: 'incremental' | 'full'
  /** 时间窗口,空=全天 */
  windows: ScheduleWindow[]
}

/** 货源定时任务计划(存在 supply_sources.settings.schedule) */
export interface SupplySchedule {
  /** 定时任务总开关 */
  enabled: boolean
  /** 每次请求上游间隔(秒),0=不限 */
  request_delay: number
  /** ACG-Faka 库存补查并发数 */
  stock_concurrency: number
  /** ACG-Faka 库存补查批次间隔(毫秒) */
  stock_request_delay_ms: number
  collect: TaskSchedule
  price: TaskSchedule
  status: TaskSchedule
}

/** 默认计划:采集每 6 小时增量,价格每 30 分钟,上下架每 60 分钟 */
export const defaultSupplySchedule = (): SupplySchedule => ({
  enabled: true,
  request_delay: 0,
  stock_concurrency: 3,
  stock_request_delay_ms: 200,
  collect: { enabled: true, mode: 'incremental', interval: 360, windows: [] },
  price: { enabled: true, interval: 30, windows: [] },
  status: { enabled: true, interval: 60, windows: [] }
})

/** 从货源 settings 读取计划(无配置时返回默认值,旧 auto_sync=true 按每 60 分钟采集兼容) */
export const readSupplySchedule = (settings: Record<string, any> | null): SupplySchedule => {
  const s = settings || {}
  const raw: any = s.schedule
  const def = defaultSupplySchedule()
  const disabled = (base: TaskSchedule): TaskSchedule => ({ ...base, enabled: false })
  // 旧版只开了 auto_sync:保持每小时增量采集,价格/上下架关闭
  if (!raw || typeof raw !== 'object') {
    if (s.auto_sync) {
      return {
        ...def,
        collect: { ...def.collect, interval: 60 },
        price: disabled(def.price),
        status: disabled(def.status)
      }
    }
    return def
  }
  const task = (key: string, base: TaskSchedule): TaskSchedule => {
    const t = raw[key]
    if (!t || typeof t !== 'object') return disabled(base)
    return {
      enabled: t.enabled ?? base.enabled,
      interval: typeof t.interval === 'number' && t.interval > 0 ? t.interval : base.interval,
      mode: t.mode === 'full' ? 'full' : base.mode,
      windows: Array.isArray(t.windows) ? t.windows : base.windows
    }
  }
  return {
    enabled: raw.enabled ?? def.enabled,
    request_delay:
      typeof raw.request_delay === 'number' && raw.request_delay >= 0 ? raw.request_delay : 0,
    stock_concurrency:
      typeof raw.stock_concurrency === 'number' && raw.stock_concurrency >= 1
        ? Math.min(10, Math.floor(raw.stock_concurrency))
        : def.stock_concurrency,
    stock_request_delay_ms:
      typeof raw.stock_request_delay_ms === 'number' && raw.stock_request_delay_ms >= 0
        ? Math.min(10000, Math.floor(raw.stock_request_delay_ms))
        : def.stock_request_delay_ms,
    collect: task('collect', def.collect),
    price: task('price', def.price),
    status: task('status', def.status)
  }
}
