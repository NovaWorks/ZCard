/**
 * 内存态访问令牌(不落 localStorage,防 XSS/第三方脚本窃取)。
 *
 * - Bearer token 仅在当前页面生命周期内有效,刷新后由 HttpOnly Cookie 会话恢复登录态;
 * - Cookie 会话由后端 Sanctum stateful 认证 + axios withCredentials 承载。
 */
let token = ''

export const sessionToken = {
  get: (): string => token,
  set: (t: string): void => {
    token = t
  },
  clear: (): void => {
    token = ''
  },
}
