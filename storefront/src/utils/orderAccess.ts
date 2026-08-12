const TOKEN_PREFIX = 'zcard_order_access:'

/** 订单访问凭证仅保存在当前浏览器会话，避免进入 URL、Referer、服务端日志或长期存储。 */
export function storeOrderAccessToken(orderNo: string, token?: string | null): void {
  if (!orderNo || !token) return
  sessionStorage.setItem(`${TOKEN_PREFIX}${orderNo}`, token)
}

export function getOrderAccessToken(orderNo: string): string | undefined {
  if (!orderNo) return undefined
  return sessionStorage.getItem(`${TOKEN_PREFIX}${orderNo}`) || undefined
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
