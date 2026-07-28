import request from './request'

export interface HealthResp {
  status: string
  service: string
  time: string
}

export const getHealth = () => request.get<unknown, HealthResp>('/health')
