<!-- 登录页面 -->
<template>
  <div class="flex w-full h-screen">
    <LoginLeftView />

    <div class="relative flex-1">
      <AuthTopBar />

      <div class="auth-right-wrap">
        <div class="form">
          <h3 class="title">{{ $t('login.title') }}</h3>
          <p class="sub-title">{{ $t('login.subTitle') }}</p>
          <ElForm
            ref="formRef"
            :model="formData"
            :rules="rules"
            :key="formKey"
            @keyup.enter="handleSubmit"
            style="margin-top: 25px"
          >
            <ElFormItem prop="email">
              <ElInput
                class="custom-height"
                :placeholder="$t('login.placeholder.email')"
                v-model.trim="formData.email"
              />
            </ElFormItem>
            <ElFormItem prop="password">
              <ElInput
                class="custom-height"
                :placeholder="$t('login.placeholder.password')"
                v-model.trim="formData.password"
                type="password"
                autocomplete="off"
                show-password
              />
            </ElFormItem>

            <!-- 推拽验证(与登录验证码开关联动:关闭时不显示) -->
            <div v-if="captchaEnabled" class="relative pb-5 mt-6">
              <div
                class="relative z-[2] overflow-hidden select-none rounded-lg border border-transparent tad-300"
                :class="{ '!border-[#FF4E4F]': !isPassing && isClickPass }"
              >
                <ArtDragVerify
                  ref="dragVerify"
                  v-model:value="isPassing"
                  :text="$t('login.sliderText')"
                  textColor="var(--art-gray-700)"
                  :successText="$t('login.sliderSuccessText')"
                  progressBarBg="var(--main-color)"
                  :background="isDark ? '#26272F' : '#F1F1F4'"
                  handlerBg="var(--default-box-color)"
                />
              </div>
              <p
                class="absolute top-0 z-[1] px-px mt-2 text-xs text-[#f56c6c] tad-300"
                :class="{ 'translate-y-10': !isPassing && isClickPass }"
              >
                {{ $t('login.placeholder.slider') }}
              </p>
            </div>

            <!-- 图形验证码(后台开启登录验证码时显示) -->
            <ElFormItem v-if="captchaEnabled" prop="captcha" class="login-captcha-item">
              <div class="flex w-full items-center gap-2">
                <ElInput
                  v-model.trim="formData.captcha"
                  :placeholder="$t('login.placeholder.captcha')"
                  class="flex-1"
                  maxlength="6"
                />
                <img
                  v-if="captchaSrc"
                  :src="captchaSrc"
                  class="h-9 w-28 cursor-pointer rounded-md border border-gray-200 object-cover"
                  :alt="$t('login.placeholder.captcha')"
                  :title="$t('login.captchaRefresh')"
                  @click="loadCaptcha"
                />
              </div>
            </ElFormItem>

            <div class="flex-cb mt-2 text-sm">
              <ElCheckbox v-model="formData.rememberPassword">{{
                $t('login.rememberPwd')
              }}</ElCheckbox>
              <a class="text-theme" href="/forget-password">{{ $t('login.forgetPwd') }}</a>
            </div>

            <div style="margin-top: 30px">
              <ElButton
                class="w-full custom-height"
                type="primary"
                @click="handleSubmit"
                :loading="loading"
                v-ripple
              >
                {{ $t('login.btnText') }}
              </ElButton>
            </div>
          </ElForm>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
  import AppConfig from '@/config'
  import { useUserStore } from '@/store/modules/user'
  import { useI18n } from 'vue-i18n'
  import { HttpError } from '@/utils/http/error'
  import { ElNotification, type FormInstance, type FormRules } from 'element-plus'
  import { useSettingStore } from '@/store/modules/setting'

  defineOptions({ name: 'Login' })

  const settingStore = useSettingStore()
  const { isDark } = storeToRefs(settingStore)
  const { t, locale } = useI18n()
  const formKey = ref(0)

  // 监听语言切换，重置表单
  watch(locale, () => {
    formKey.value++
  })

  const dragVerify = ref()

  const userStore = useUserStore()
  const router = useRouter()
  const route = useRoute()
  const isPassing = ref(false) // 拖拽滑块验证(需滑动通过)
  const isClickPass = ref(false)

  const systemName = AppConfig.systemInfo.name
  const formRef = ref<FormInstance>()

  const formData = reactive({
    email: '',
    password: '',
    rememberPassword: true,
    captcha: ''
  })

  // 图形验证码(后台开启登录验证码时显示并校验)
  const captchaEnabled = ref(false)
  const captchaSrc = ref('')
  const loadCaptcha = async () => {
    try {
      const res = await fetch(`/api/captcha/config?scene=login&t=${Date.now()}`)
      const data = await res.json()
      captchaEnabled.value = !!data.enabled
      captchaSrc.value = data.src || ''
      if (!data.enabled) {
        formData.captcha = ''
        isPassing.value = true
      }
    } catch {
      captchaEnabled.value = false
    }
  }
  onMounted(() => {
    loadCaptcha()
  })

  const rules = computed<FormRules>(() => ({
    email: [
      { required: true, message: t('login.placeholder.email'), trigger: 'blur' },
      { type: 'email', message: t('login.placeholder.email'), trigger: 'blur' }
    ],
    password: [{ required: true, message: t('login.placeholder.password'), trigger: 'blur' }]
  }))

  const loading = ref(false)

  // 登录
  const handleSubmit = async () => {
    if (!formRef.value) return

    try {
      // 表单验证
      const valid = await formRef.value.validate()
      if (!valid) return

      // 拖拽验证(仅开启登录验证码时要求)
      if (captchaEnabled.value && !isPassing.value) {
        isClickPass.value = true
        return
      }

      loading.value = true

      // ZCard 登录（email + password [+ captcha]），userStore.login 已存储 token 和用户信息
      const { email, password, captcha } = formData
      await userStore.login(email, password, captchaEnabled.value ? captcha : undefined)

      // 登录成功处理
      showLoginSuccessNotice()

      // 获取 redirect 参数，如果存在则跳转到指定页面，否则跳转到首页
      const redirect = route.query.redirect as string
      router.push(redirect || '/')
    } catch (error) {
      // 处理 HttpError
      if (error instanceof HttpError) {
        // HTTP 拦截器已展示错误消息
      } else {
        console.error('[Login] Unexpected error:', error)
      }
      // 登录失败刷新图形验证码
      if (captchaEnabled.value) {
        formData.captcha = ''
        loadCaptcha()
      }
    } finally {
      loading.value = false
      resetDragVerify()
    }
  }

  // 重置拖拽验证
  const resetDragVerify = () => {
    dragVerify.value.reset()
  }

  // 登录成功提示
  const showLoginSuccessNotice = () => {
    setTimeout(() => {
      ElNotification({
        title: t('login.success.title'),
        type: 'success',
        duration: 2500,
        zIndex: 10000,
        message: `${t('login.success.message')}, ${systemName}!`
      })
    }, 1000)
  }
</script>

<style scoped>
  @import './style.css';
</style>
