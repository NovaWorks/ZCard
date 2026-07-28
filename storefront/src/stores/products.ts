import { defineStore } from 'pinia'
import { getProducts, type Product } from '@/api/products'

export const useProductsStore = defineStore('products', {
  state: () => ({
    list: [] as Product[],
    page: 1,
    lastPage: 1,
    loading: false,
  }),
  actions: {
    async fetch(params: Record<string, any> = {}) {
      this.loading = true
      try {
        const res = await getProducts(params)
        this.list = res.data
        this.page = res.current_page
        this.lastPage = res.last_page
      } finally {
        this.loading = false
      }
    },
  },
})
