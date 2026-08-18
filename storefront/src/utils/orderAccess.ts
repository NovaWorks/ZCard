const TOKEN_PREFIX = 'zcard_order_access:'
/** 联系方式 → 订单号列表:按邮箱/手机号查单时,前端无法预知命中哪几笔订单,只能靠本地索引取回全部凭证 */
const CONTACT_INDEX_KEY = 'zcard_order_contacts'
/** 与后端 OrderService::MAX_ACCESS_TOKENS 对齐 */
const MAX_TOKENS = 20

/**
 * 订单访问凭证保存在 localStorage。
 *
 * 早期版本存 sessionStorage,标签页一关凭证即失效,游客只能靠查询密码找回订单;
 * 未设密码的订单会彻底查不到卡密(#付款后看不到卡密)。凭证不进 URL,避免落入 Referer 与服务端日志。
 */
export function storeOrderAccessToken(orderNo: string, token?: string | null, contact?: string | null): void {
  if (!orderNo || !token) return
  try {
    localStorage.setItem(`${TOKEN_PREFIX}${orderNo}`, token)
    if (contact) indexOrderByContact(contact, orderNo)
  } catch { /* 隐私模式/配额满:降级为不持久化,不阻断下单 */ }
}

export function getOrderAccessToken(orderNo: string): string | undefined {
  if (!orderNo) return undefined
  try {
    // 兼容旧版本:老凭证仍在 sessionStorage 里,读到即迁移到 localStorage
    const legacy = sessionStorage.getItem(`${TOKEN_PREFIX}${orderNo}`)
    if (legacy) {
      localStorage.setItem(`${TOKEN_PREFIX}${orderNo}`, legacy)
      sessionStorage.removeItem(`${TOKEN_PREFIX}${orderNo}`)
      return legacy
    }
    return localStorage.getItem(`${TOKEN_PREFIX}${orderNo}`) || undefined
  } catch {
    return undefined
  }
}

export function orderAccessTokensById(
  orders: Array<{ id: number; order_no: string }>,
): Record<string, string> {
  return orders.reduce<Record<string, string>>((tokens, order) => {
    const token = getOrderAccessToken(order.order_no)
    if (token) tokens[String(order.id)] = token
    return tokens
  }, {})
}

/**
 * 查单关键字(订单号 OR 联系方式)对应的全部凭证。
 * 关键字是订单号 → 该单凭证;是联系方式 → 该联系方式下单过的全部订单凭证;
 * 都没命中(如换了邮箱查) → 回退本机全部凭证,由服务端逐笔比对。
 */
export function accessTokensForKeyword(keyword: string): string[] {
  const kw = keyword.trim()
  if (!kw) return []

  const direct = getOrderAccessToken(kw)
  if (direct) return [direct]

  const byContact = readContactIndex()[normalizeContact(kw)] ?? []
  const tokens = byContact
    .map((orderNo) => getOrderAccessToken(orderNo))
    .filter((t): t is string => !!t)

  return (tokens.length ? tokens : allStoredTokens()).slice(0, MAX_TOKENS)
}

function normalizeContact(contact: string): string {
  return contact.trim().toLowerCase()
}

function readContactIndex(): Record<string, string[]> {
  try {
    const raw = localStorage.getItem(CONTACT_INDEX_KEY)
    const parsed = raw ? JSON.parse(raw) : null
    return parsed && typeof parsed === 'object' ? parsed as Record<string, string[]> : {}
  } catch {
    return {}
  }
}

function indexOrderByContact(contact: string, orderNo: string): void {
  const index = readContactIndex()
  const key = normalizeContact(contact)
  const list = index[key] ?? []
  if (!list.includes(orderNo)) list.unshift(orderNo)
  index[key] = list.slice(0, MAX_TOKENS)
  localStorage.setItem(CONTACT_INDEX_KEY, JSON.stringify(index))
}

/** 本机保存的全部订单凭证(最近的在前) */
function allStoredTokens(): string[] {
  const tokens: string[] = []
  try {
    for (let i = localStorage.length - 1; i >= 0; i--) {
      const key = localStorage.key(i)
      if (!key?.startsWith(TOKEN_PREFIX)) continue
      const token = localStorage.getItem(key)
      if (token) tokens.push(token)
    }
  } catch { /* 读不到就返回已收集的部分 */ }
  return tokens
}
