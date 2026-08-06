import { defineStore } from 'pinia'
import { getProducts, type Product } from '@/api/products'

export const useProductsStore = defineStore('products', {
  state: () => ({
    list: [] as Product[],
    page: 1,
    lastPage: 1,
    loading: false,
    /** 当前查询参数(供加载更多复用) */
    params: {} as Record<string, any>,
  }),
  actions: {
    async fetch(params: Record<string, any> = {}) {
      this.loading = true
      this.params = params
      try {
        const res = await getProducts({ ...params, page: 1 })
        this.list = res.data
        this.page = res.current_page
        this.lastPage = res.last_page
      } finally {
        this.loading = false
      }
    },
    /** 加载下一页(追加到列表尾部) */
    async fetchMore() {
      if (this.loading || this.page >= this.lastPage) return
      this.loading = true
      try {
        const res = await getProducts({ ...this.params, page: this.page + 1 })
        this.list = [...this.list, ...res.data]
        this.page = res.current_page
        this.lastPage = res.last_page
      } finally {
        this.loading = false
      }
    },
  },
})
