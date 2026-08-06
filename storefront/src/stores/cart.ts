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
  /** 靓号自选:选定的卡密 id */
  card_id?: number | null
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
      // 靓号自选:按 card_id 精确匹配,不同靓号不合并(同一商品多个靓号各自成行)
      const exist = item.card_id
        ? this.items.find((i) => i.product_id === item.product_id && i.card_id === item.card_id)
        : this.items.find(
            (i) => i.product_id === item.product_id && (i.sku_id ?? null) === (item.sku_id ?? null),
          )
      if (exist) exist.qty += item.qty
      else this.items.push(item)
      this.persist()
    },
    updateQty(productId: number, skuId: number | null, qty: number, cardId?: number | null) {
      const it = this.items.find(
        (i) => i.product_id === productId
          && (i.sku_id ?? null) === (skuId ?? null)
          && (i.card_id ?? null) === (cardId ?? null),
      )
      if (it) {
        it.qty = Math.max(1, qty)
        this.persist()
      }
    },
    remove(productId: number, skuId: number | null, cardId?: number | null) {
      this.items = this.items.filter(
        (i) => !(i.product_id === productId
          && (i.sku_id ?? null) === (skuId ?? null)
          && (i.card_id ?? null) === (cardId ?? null)),
      )
      this.persist()
    },
    clear() {
      this.items = []
      this.persist()
    },
  },
})
