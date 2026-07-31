import request from '@/utils/http'

export interface Currency {
  code: string
  name: string
  symbol: string
  symbol_position: 'before' | 'after'
  decimal_places: number
  exchange_rate: string
  is_base: boolean
  is_enabled: boolean
  sort: number
  created_at?: string
}

export const getCurrencies = () =>
  request.get<Currency[]>({ url: '/admin/currencies' })

export const createCurrency = (data: Partial<Currency>) =>
  request.post<Currency>({ url: '/admin/currencies', data })

export const updateCurrency = (code: string, data: Partial<Currency>) =>
  request.put<Currency>({ url: `/admin/currencies/${code}`, data })

export const deleteCurrency = (code: string) =>
  request.del({ url: `/admin/currencies/${code}` })
