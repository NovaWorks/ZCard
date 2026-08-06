import { defineStore } from 'pinia'

export interface CartItem {
  product_id: number
  sku_id: number | null
  qty: number
  slug: string
  name: string
  cover: string | null
  price: number
  price_display: number
  sku_name: string | null
}

const STORAGE_KEY = 'zcard_cart'

function load(): CartItem[] {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    return raw ? (JSON.parse(raw) as CartItem[]) : []
  } catch {
    return []
  }
}

export const useCartStore = defineStore('cart', {
  state: () => ({ items: load() as CartItem[] }),
  getters: {
    totalQty: (s) => s.items.reduce((n, i) => n + i.qty, 0),
    subtotal: (s) => s.items.reduce((n, i) => n + i.price * i.qty, 0),
  },
  actions: {
    persist() {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(this.items))
    },
    add(item: CartItem) {
      const exist = this.items.find(
        (i) => i.product_id === item.product_id && (i.sku_id ?? null) === (item.sku_id ?? null),
      )
      if (exist) exist.qty += item.qty
      else this.items.push(item)
      this.persist()
    },
    updateQty(productId: number, skuId: number | null, qty: number) {
      const it = this.items.find(
        (i) => i.product_id === productId && (i.sku_id ?? null) === (skuId ?? null),
      )
      if (it) {
        it.qty = Math.max(1, qty)
        this.persist()
      }
    },
    remove(productId: number, skuId: number | null) {
      this.items = this.items.filter(
        (i) => !(i.product_id === productId && (i.sku_id ?? null) === (skuId ?? null)),
      )
      this.persist()
    },
    clear() {
      this.items = []
      this.persist()
    },
  },
})
