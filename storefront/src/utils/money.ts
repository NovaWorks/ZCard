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
