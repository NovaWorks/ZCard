import request from './request'
import type { CurrencyInfo } from '@/utils/money'

export interface CurrencyListResponse {
  base_currency: string
  currencies: CurrencyInfo[]
}

export const getCurrencies = () =>
  request.get<unknown, CurrencyListResponse>('/currencies')
