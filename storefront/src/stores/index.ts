import { defineStore } from 'pinia'

// Phase 0 占位；Phase 1 加入 user/cart 等 store
export const useAppStore = defineStore('app', {
  state: () => ({ ready: false }),
})
