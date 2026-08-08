/**
 * 轻量全局事件总线(无外部依赖)。
 * 用于跨组件通信(如 AppHeader 触发公告弹窗,DefaultLayout 监听)。
 */
type Handler = (...args: any[]) => void

const handlers = new Map<string, Set<Handler>>()

export function on(event: string, handler: Handler): () => void {
  if (!handlers.has(event)) handlers.set(event, new Set())
  handlers.get(event)!.add(handler)
  return () => {
    handlers.get(event)?.delete(handler)
  }
}

export function emit(event: string, ...args: any[]): void {
  handlers.get(event)?.forEach((h) => h(...args))
}
