<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getProduct, type Product } from '@/api/products'
import { createOrder } from '@/api/orders'
import { useSettingsStore } from '@/stores/settings'
import { useAuthStore } from '@/stores/auth'

interface ControlField {
  type: string; label: string; name: string; required: boolean; options?: string[]
}

const route = useRoute()
const router = useRouter()
const settings = useSettingsStore()
const auth = useAuthStore()
const product = ref<Product | null>(null)
const selectedSku = ref<number | null>(null)
const qty = ref(1)
const contact = ref('')
const password = ref('')
const controlValues = ref<Record<string, string>>({})
const loading = ref(false)
const err = ref('')

const controlFields = computed<ControlField[]>(() => {
  const cc = (product.value as any)?.control_config
  return Array.isArray(cc) ? cc : []
})

onMounted(async () => {
  await settings.load()
  const slug = route.query.product as string
  if (slug) {
    product.value = await getProduct(slug)
    selectedSku.value = route.query.sku ? Number(route.query.sku) : (product.value.skus?.[0]?.id ?? null)
  }
  qty.value = route.query.qty ? Number(route.query.qty) : 1
})

const price = () => {
  if (!product.value) return 0
  const sku = product.value.skus?.find(s => s.id === selectedSku.value)
  return sku ? sku.price : product.value.price
}
const total = () => price() * qty.value
const fmt = (fen: number) => (fen / 100).toFixed(2)

async function submit() {
  if (!product.value) return
  if (!contact.value.trim()) { err.value = '请填写联系方式'; return }

  // 校验必填控件
  for (const f of controlFields.value) {
    if (f.required && !(controlValues.value[f.name]?.trim())) {
      err.value = `请填写 ${f.label}`
      return
    }
  }

  // 仅限会员检查
  if ((product.value as any).only_user && !auth.isLoggedIn) {
    err.value = '该商品仅限会员购买，请先登录'
    return
  }

  err.value = ''
  loading.value = true
  try {
    const res = await createOrder({
      product_id: product.value.id,
      sku_id: selectedSku.value ?? undefined,
      qty: qty.value,
      contact: contact.value,
      password: password.value || undefined,
      extra: { ...controlValues.value },
    })
    router.push(`/pay/${res.order_no}`)
  } catch (e: any) {
    err.value = e?.response?.data?.message || '下单失败(可能库存不足)'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="max-w-2xl mx-auto px-4 py-8">
    <h1 class="text-xl font-bold text-ink mb-6">确认订单</h1>

    <!-- 商品确认 -->
    <div v-if="product" class="flex gap-3 p-4 bg-white rounded-card border border-gray-200 mb-4">
      <div class="w-16 h-16 bg-gradient-to-br from-blue-100 to-indigo-100 rounded-card flex items-center justify-center text-primary text-xs flex-shrink-0">
        <img v-if="product.cover" :src="product.cover" class="w-full h-full object-cover rounded-card" />
        <span v-else>无图</span>
      </div>
      <div class="flex-1">
        <div class="text-sm font-semibold text-ink">{{ product.name }}</div>
        <div v-if="product.skus?.length" class="text-xs text-ink-muted mt-1">
          {{ product.skus.find(s => s.id === selectedSku)?.name }} × {{ qty }}
        </div>
      </div>
      <div class="text-right">
        <div class="text-primary font-bold">¥{{ fmt(price()) }}</div>
        <div class="text-xs text-ink-muted">× {{ qty }}</div>
      </div>
    </div>

    <!-- 小计 -->
    <div class="flex justify-between px-4 py-3 mb-4">
      <span class="text-ink-soft">小计</span>
      <span class="text-xl font-bold text-primary">¥{{ fmt(total()) }}</span>
    </div>

    <!-- 联系方式 -->
    <div class="space-y-3">
      <div>
        <label class="text-xs font-semibold text-ink-soft">{{ (product as any)?.contact_type === 'phone' ? '手机号' : '邮箱地址' }} *</label>
        <input v-model="contact" type="text" :placeholder="(product as any)?.contact_type === 'phone' ? '请输入手机号' : '请输入邮箱'"
          class="w-full mt-1 px-3 py-2 border border-gray-200 rounded-field text-sm focus:border-primary" />
      </div>

      <!-- 动态控件(由后台 control_config 驱动) -->
      <div v-for="f in controlFields" :key="f.name">
        <label class="text-xs font-semibold text-ink-soft">
          {{ f.label }} <span v-if="f.required" class="text-danger">*</span>
        </label>
        <!-- select 下拉 -->
        <select v-if="f.type === 'select'" v-model="controlValues[f.name]"
          class="w-full mt-1 px-3 py-2 border border-gray-200 rounded-field text-sm focus:border-primary bg-white">
          <option value="">请选择</option>
          <option v-for="opt in (f.options || [])" :key="opt" :value="opt">{{ opt }}</option>
        </select>
        <!-- textarea -->
        <textarea v-else-if="f.type === 'textarea'" v-model="controlValues[f.name]" rows="3"
          :placeholder="`请输入${f.label}`"
          class="w-full mt-1 px-3 py-2 border border-gray-200 rounded-field text-sm focus:border-primary"></textarea>
        <!-- text/email/number -->
        <input v-else v-model="controlValues[f.name]" :type="f.type" :placeholder="`请输入${f.label}`"
          class="w-full mt-1 px-3 py-2 border border-gray-200 rounded-field text-sm focus:border-primary" />
      </div>

      <!-- 查询密码 -->
      <div v-if="settings.config?.order_query_password">
        <label class="text-xs font-semibold text-ink-soft">查询密码</label>
        <input v-model="password" type="password" placeholder="设置查询订单的密码"
          class="w-full mt-1 px-3 py-2 border border-gray-200 rounded-field text-sm focus:border-primary" />
      </div>
    </div>

    <!-- 仅限会员提示 -->
    <div v-if="(product as any)?.only_user && !auth.isLoggedIn" class="mt-3 p-2 bg-orange-50 border border-orange-200 rounded text-xs text-orange-700">
      该商品仅限会员购买，请先 <router-link to="/login" class="text-primary underline">登录</router-link>
    </div>

    <div v-if="err" class="text-danger text-xs mt-3">{{ err }}</div>

    <button @click="submit" :disabled="loading"
      class="w-full mt-6 bg-gradient-to-br from-primary to-blue-500 text-white font-bold py-3 rounded-card shadow-md disabled:opacity-50">
      {{ loading ? '提交中...' : `提交订单 ¥${fmt(total())}` }}
    </button>
  </div>
</template>
