<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { register } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import request from '@/api/request'

const router = useRouter()
const { t } = useI18n()
const authStore = useAuthStore()
const settings = useSettingsStore()
onMounted(() => { settings.load() })

const username = ref('')
const email = ref('')
const password = ref('')
const confirmPassword = ref('')
const captcha = ref('')
const err = ref('')
const loading = ref(false)

const registerOpen = computed(() => settings.config?.register_open !== false)
const needCaptcha = computed(() => !!settings.config?.captcha_register)
const minLen = computed(() => settings.config?.username_min_length || 3)
const captchaSrc = ref('')

const refreshCaptcha = () => {
  captchaSrc.value = `${request.defaults?.baseURL || '/api'}/captcha/register?${Date.now()}`
}

onMounted(() => {
  if (needCaptcha.value) refreshCaptcha()
})

// 监听验证码开关变化
watch(needCaptcha, (v) => { if (v && !captchaSrc.value) refreshCaptcha() })

async function submit() {
  err.value = ''
  if (!username.value || !email.value || !password.value) {
    err.value = t('auth.register.fillAllRequired')
    return
  }
  if (username.value.length < minLen.value) {
    err.value = t('auth.register.usernameMinLen', { n: minLen.value })
    return
  }
  if (password.value.length < 6) {
    err.value = t('common.validation.minPassword')
    return
  }
  if (password.value !== confirmPassword.value) {
    err.value = t('common.validation.passwordMismatch')
    return
  }
  if (needCaptcha.value && !captcha.value) {
    err.value = t('common.validation.fillCaptcha')
    return
  }
  loading.value = true
  try {
    const res = await register({
      username: username.value,
      email: email.value,
      password: password.value,
      captcha: needCaptcha.value ? captcha.value : undefined,
    } as any)
    authStore.setAuth(res.token, res.user)
    router.push('/')
  } catch (e: any) {
    err.value = e?.response?.data?.message || t('auth.register.registerFailed')
    if (e?.response?.data?.errors?.captcha) {
      err.value = e.response.data.errors.captcha[0]
    }
    if (needCaptcha.value) refreshCaptcha()
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="max-w-md mx-auto px-4 py-12">
    <!-- 注册关闭提示 -->
    <div v-if="!registerOpen" class="bg-white rounded-card border border-border p-8 shadow-card text-center">
      <div class="text-5xl mb-4 opacity-40">🔒</div>
      <h1 class="text-lg font-bold text-ink mb-2">{{ t('auth.register.closedTitle') }}</h1>
      <p class="text-xs text-ink-muted mb-5">{{ t('auth.register.closedHint') }}</p>
      <button @click="router.push('/login')" class="px-4 py-2 text-xs bg-primary text-white rounded-field hover:bg-primary-hover transition">{{ t('auth.register.backToLogin') }}</button>
    </div>

    <!-- 注册表单 -->
    <div v-else class="bg-white rounded-card border border-border p-6 shadow-card">
      <div class="text-center mb-6">
        <span class="inline-flex w-12 h-12 bg-gradient-to-br from-primary to-primary-hover rounded-card text-white font-extrabold items-center justify-center text-2xl shadow-sm">Z</span>
        <h1 class="text-xl font-bold text-ink mt-3">{{ t('auth.register.title') }}</h1>
        <p class="text-xs text-ink-muted mt-1">{{ t('auth.register.subtitle') }}</p>
      </div>

      <form @submit.prevent="submit" class="space-y-4">
        <div>
          <label class="block text-xs font-semibold text-ink-soft mb-1">{{ t('auth.register.username') }}</label>
          <input v-model="username" type="text" :placeholder="t('auth.register.usernamePlaceholder')"
            class="w-full px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-ink-soft mb-1">{{ t('common.email') }}</label>
          <input v-model="email" type="email" :placeholder="t('auth.register.emailPlaceholder')"
            class="w-full px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-ink-soft mb-1">{{ t('common.password') }}</label>
          <input v-model="password" type="password" :placeholder="t('auth.register.passwordPlaceholder')"
            class="w-full px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-ink-soft mb-1">{{ t('auth.register.confirmPassword') }}</label>
          <input v-model="confirmPassword" type="password" :placeholder="t('auth.register.confirmPasswordPlaceholder')"
            class="w-full px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
        </div>
        <!-- 验证码 -->
        <div v-if="needCaptcha">
          <label class="block text-xs font-semibold text-ink-soft mb-1">{{ t('common.captcha') }}</label>
          <div class="flex gap-2">
            <input v-model="captcha" type="text" :placeholder="t('common.validation.fillCaptcha')" maxlength="6"
              class="flex-1 px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
            <img v-if="captchaSrc" :src="captchaSrc" @click="refreshCaptcha"
              class="h-9 cursor-pointer rounded-field border border-border" :alt="t('common.captcha')" :title="t('order.checkout.captchaRefreshTitle')" />
          </div>
        </div>

        <div v-if="err" class="text-danger text-xs bg-red-50 border border-red-100 rounded-field px-3 py-2">{{ err }}</div>

        <button type="submit" :disabled="loading"
          class="w-full bg-gradient-to-r from-primary to-primary-hover text-white font-bold py-2.5 rounded-card hover:shadow-pop disabled:opacity-60 transition shadow-sm">
          {{ loading ? t('auth.register.registering') : t('auth.register.register') }}
        </button>
      </form>

      <div class="text-sm text-ink-muted text-center mt-4">
        {{ t('auth.register.hasAccount') }}<router-link to="/login" class="text-primary ml-1 hover:text-primary-hover">{{ t('auth.register.loginNow') }}</router-link>
      </div>
    </div>
  </div>
</template>
