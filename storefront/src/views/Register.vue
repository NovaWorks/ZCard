<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { register } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()

const username = ref('')
const email = ref('')
const password = ref('')
const confirmPassword = ref('')
const err = ref('')
const loading = ref(false)

async function submit() {
  err.value = ''
  if (!username.value || !email.value || !password.value) {
    err.value = '请填写所有必填项'
    return
  }
  if (password.value.length < 6) {
    err.value = '密码至少 6 位'
    return
  }
  if (password.value !== confirmPassword.value) {
    err.value = '两次输入的密码不一致'
    return
  }
  loading.value = true
  try {
    const res = await register({
      username: username.value,
      email: email.value,
      password: password.value,
    })
    authStore.setAuth(res.token, res.user)
    router.push('/')
  } catch (e: any) {
    err.value = e?.response?.data?.message || '注册失败,请稍后重试'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="max-w-md mx-auto px-4 py-12">
    <div class="bg-white rounded-card border border-gray-200 p-6">
      <h1 class="text-xl font-bold text-ink mb-6 text-center">注册</h1>

      <form @submit.prevent="submit" class="space-y-4">
        <div>
          <label class="block text-sm text-ink-soft mb-1">用户名</label>
          <input v-model="username" type="text" placeholder="请输入用户名"
            class="w-full px-3 py-2 border border-gray-200 rounded-field text-sm focus:border-primary" />
        </div>
        <div>
          <label class="block text-sm text-ink-soft mb-1">邮箱</label>
          <input v-model="email" type="email" placeholder="请输入邮箱"
            class="w-full px-3 py-2 border border-gray-200 rounded-field text-sm focus:border-primary" />
        </div>
        <div>
          <label class="block text-sm text-ink-soft mb-1">密码</label>
          <input v-model="password" type="password" placeholder="至少 6 位"
            class="w-full px-3 py-2 border border-gray-200 rounded-field text-sm focus:border-primary" />
        </div>
        <div>
          <label class="block text-sm text-ink-soft mb-1">确认密码</label>
          <input v-model="confirmPassword" type="password" placeholder="请再次输入密码"
            class="w-full px-3 py-2 border border-gray-200 rounded-field text-sm focus:border-primary" />
        </div>

        <div v-if="err" class="text-danger text-sm">{{ err }}</div>

        <button type="submit" :disabled="loading"
          class="w-full bg-primary text-white font-bold py-2.5 rounded-card disabled:opacity-60">
          {{ loading ? '注册中...' : '注册' }}
        </button>
      </form>

      <div class="text-sm text-ink-muted text-center mt-4">
        已有账号?<router-link to="/login" class="text-primary ml-1">立即登录</router-link>
      </div>
    </div>
  </div>
</template>
