<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { sendResetCode, resetPassword } from '@/api/auth'
import { useSettingsStore } from '@/stores/settings'
import request from '@/api/request'

const router = useRouter()
const { t } = useI18n()
const settings = useSettingsStore()
onMounted(() => { settings.load() })

const step = ref(1) // 1=发送验证码, 2=重置密码
const email = ref('')
const code = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const captcha = ref('')
const err = ref('')
const msg = ref('')
const loading = ref(false)
const countdown = ref(0)

const needCaptcha = computed(() => !!settings.config?.captcha_register)
const captchaSrc = ref('')

const refreshCaptcha = () => {
  captchaSrc.value = `${request.defaults?.baseURL || '/api'}/captcha/register?${Date.now()}`
}
watch(needCaptcha, (v) => { if (v && !captchaSrc.value) refreshCaptcha() })

const startCountdown = () => {
  countdown.value = 60
  const timer = setInterval(() => {
    countdown.value--
    if (countdown.value <= 0) clearInterval(timer)
  }, 1000)
}

async function handleSendCode() {
  err.value = ''
  msg.value = ''
  if (!email.value) { err.value = t('auth.forget.fillEmail'); return }
  if (needCaptcha.value && !captcha.value) { err.value = t('common.validation.fillCaptcha'); return }
  loading.value = true
  try {
    const res = await sendResetCode({
      email: email.value,
      captcha: needCaptcha.value ? captcha.value : undefined,
    })
    msg.value = res.message || t('auth.forget.codeSent')
    step.value = 2
    startCountdown()
  } catch (e: any) {
    err.value = e?.response?.data?.message || t('auth.forget.sendFailed')
    if (e?.response?.data?.errors?.captcha) err.value = e.response.data.errors.captcha[0]
    if (e?.response?.data?.errors?.email) err.value = e.response.data.errors.email[0]
    if (needCaptcha.value) refreshCaptcha()
  } finally {
    loading.value = false
  }
}

async function handleReset() {
  err.value = ''
  msg.value = ''
  if (!code.value) { err.value = t('auth.forget.fillCode'); return }
  if (newPassword.value.length < 8) { err.value = t('common.validation.minPassword'); return }
  if (newPassword.value !== confirmPassword.value) { err.value = t('common.validation.passwordMismatch'); return }
  loading.value = true
  try {
    const res = await resetPassword({
      email: email.value,
      code: code.value,
      password: newPassword.value,
    })
    msg.value = res.message || t('auth.forget.resetSuccess')
    setTimeout(() => router.push('/login'), 1500)
  } catch (e: any) {
    err.value = e?.response?.data?.message || t('auth.forget.resetFailed')
    if (e?.response?.data?.errors?.code) err.value = e.response.data.errors.code[0]
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="max-w-md mx-auto px-4 py-12">
    <div class="bg-white rounded-card border border-border p-6 shadow-card">
      <div class="text-center mb-6">
        <span class="inline-flex w-12 h-12 bg-gradient-to-br from-primary to-primary-hover rounded-card text-white font-extrabold items-center justify-center text-2xl shadow-sm">Z</span>
        <h1 class="text-xl font-bold text-ink mt-3">{{ t('auth.forget.title') }}</h1>
        <p class="text-xs text-ink-muted mt-1">{{ step === 1 ? t('auth.forget.step1Hint') : t('auth.forget.step2Hint') }}</p>
      </div>

      <!-- Step 1: 发送验证码 -->
      <form v-if="step === 1" @submit.prevent="handleSendCode" class="space-y-4">
        <div>
          <label class="block text-xs font-semibold text-ink-soft mb-1">{{ t('auth.forget.registerEmail') }}</label>
          <input v-model="email" type="email" :placeholder="t('auth.forget.registerEmailPlaceholder')"
            class="w-full px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
        </div>
        <div v-if="needCaptcha">
          <label class="block text-xs font-semibold text-ink-soft mb-1">{{ t('common.captcha') }}</label>
          <div class="flex gap-2">
            <input v-model="captcha" type="text" :placeholder="t('common.validation.fillCaptcha')" maxlength="6"
              class="flex-1 px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
            <img v-if="captchaSrc" :src="captchaSrc" @click="refreshCaptcha"
              class="h-9 cursor-pointer rounded-field border border-border" :alt="t('common.captcha')" />
          </div>
        </div>
        <div v-if="err" class="text-danger text-xs bg-red-50 border border-red-100 rounded-field px-3 py-2">{{ err }}</div>
        <div v-if="msg" class="text-success text-xs bg-green-50 border border-green-100 rounded-field px-3 py-2">{{ msg }}</div>
        <button type="submit" :disabled="loading"
          class="w-full bg-gradient-to-r from-primary to-primary-hover text-white font-bold py-2.5 rounded-card hover:shadow-pop disabled:opacity-60 transition shadow-sm">
          {{ loading ? t('auth.forget.sending') : t('auth.forget.sendCode') }}
        </button>
      </form>

      <!-- Step 2: 重置密码 -->
      <form v-else @submit.prevent="handleReset" class="space-y-4">
        <div>
          <label class="block text-xs font-semibold text-ink-soft mb-1">{{ t('auth.forget.emailCode') }}</label>
          <div class="flex gap-2">
            <input v-model="code" type="text" :placeholder="t('auth.forget.codePlaceholder')" maxlength="6"
              class="flex-1 px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
            <button type="button" @click="handleSendCode" :disabled="countdown > 0"
              class="px-3 py-2 text-xs bg-surface-subtle text-ink-soft rounded-field border border-border hover:bg-border transition whitespace-nowrap disabled:opacity-50">
              {{ countdown > 0 ? `${countdown}s` : t('auth.forget.resend') }}
            </button>
          </div>
        </div>
        <div>
          <label class="block text-xs font-semibold text-ink-soft mb-1">{{ t('auth.forget.newPassword') }}</label>
          <input v-model="newPassword" type="password" :placeholder="t('auth.forget.newPasswordPlaceholder')"
            class="w-full px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-ink-soft mb-1">{{ t('auth.forget.confirmNewPassword') }}</label>
          <input v-model="confirmPassword" type="password" :placeholder="t('auth.forget.confirmPlaceholder')"
            class="w-full px-3 py-2 border border-border rounded-field text-sm focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition" />
        </div>
        <div v-if="err" class="text-danger text-xs bg-red-50 border border-red-100 rounded-field px-3 py-2">{{ err }}</div>
        <div v-if="msg" class="text-success text-xs bg-green-50 border border-green-100 rounded-field px-3 py-2">{{ msg }}</div>
        <button type="submit" :disabled="loading"
          class="w-full bg-gradient-to-r from-primary to-primary-hover text-white font-bold py-2.5 rounded-card hover:shadow-pop disabled:opacity-60 transition shadow-sm">
          {{ loading ? t('auth.forget.resetting') : t('auth.forget.reset') }}
        </button>
      </form>

      <div class="text-sm text-ink-muted text-center mt-4">
        <router-link to="/login" class="text-primary hover:text-primary-hover">{{ t('auth.forget.backToLogin') }}</router-link>
      </div>
    </div>
  </div>
</template>
