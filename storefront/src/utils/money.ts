/** 货币元信息(从 /api/currencies 拉取后存 store) */
export interface CurrencyInfo {
  code: string
  name: string
  symbol: string
  symbol_position: 'before' | 'after'
  decimal_places: number
  is_base: boolean
}

/** 最小单位(分) → 带符号展示字符串,如 "¥12.50" / "12.50€" / "-¥12.50"(负数) */
export function formatMoney(minUnit: number, cur: CurrencyInfo | null | undefined): string {
  if (!cur) return (minUnit / 100).toFixed(2)
  const divisor = Math.pow(10, cur.decimal_places)
  const negative = minUnit < 0
  const abs = Math.abs(minUnit)
  const value = (abs / divisor).toFixed(cur.decimal_places)
  const body = cur.symbol_position === 'before' ? `${cur.symbol}${value}` : `${value}${cur.symbol}`
  return negative ? `-${body}` : body
}

/**
 * 库存展示:上游商品库存可能为负数(-1 = 不限/充足,其他负数为未知)。
 * 负数统一显示「不限」,0/正数显示原数量。
 */
export function stockText(stock: number | null | undefined, unlimited = '不限'): string {
  if (stock === null || stock === undefined) return unlimited
  if (stock < 0) return unlimited
  return String(stock)
}
