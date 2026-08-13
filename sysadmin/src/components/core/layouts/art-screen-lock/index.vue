<!-- 锁屏 -->
<template>
  <div class="layout-lock-screen">
    <!-- 锁屏弹窗 -->
    <div v-if="!isLock">
      <ElDialog v-model="visible" :width="370" :show-close="false" @open="handleDialogOpen">
        <div class="flex-c flex-col">
          <img class="w-16 h-16 rounded-full" src="@imgs/user/avatar.webp" alt="用户头像" />
          <div class="mt-7.5 mb-3.5 text-base font-medium">{{ userInfo.username }}</div>
          <ElForm
            ref="formRef"
            :model="formData"
            :rules="rules"
            class="w-[90%]"
            @submit.prevent="handleLock"
          >
            <ElFormItem prop="password">
              <ElInput
                v-model="formData.password"
                type="password"
                :placeholder="$t('lockScreen.lock.inputPlaceholder')"
                :show-password="true"
                autocomplete="new-password"
                ref="lockInputRef"
                class="w-full mt-9"
                @keyup.enter="handleLock"
              >
                <template #suffix>
                  <ElIcon class="c-p" @click="handleLock">
                    <Lock />
                  </ElIcon>
                </template>
              </ElInput>
            </ElFormItem>
            <ElButton type="primary" class="w-full mt-0.5" @click="handleLock" v-ripple>
              {{ $t('lockScreen.lock.btnText') }}
            </ElButton>
          </ElForm>
        </div>
      </ElDialog>
    </div>

    <!-- 解锁界面 -->
    <div v-else class="unlock-content">
      <div class="flex-c flex-col w-80">
        <img class="w-16 h-16 mt-5 rounded-full" src="@imgs/user/avatar.webp" alt="用户头像" />
        <div class="mt-3 mb-3.5 text-base font-medium">
          {{ userInfo.username }}
        </div>
        <ElForm
          ref="unlockFormRef"
          :model="unlockForm"
          :rules="rules"
          class="w-full !px-2.5"
          @submit.prevent="handleUnlock"
        >
          <ElFormItem prop="password">
            <ElInput
              v-model="unlockForm.password"
              type="password"
              :placeholder="$t('lockScreen.unlock.inputPlaceholder')"
              :show-password="true"
              autocomplete="new-password"
              ref="unlockInputRef"
              class="mt-5"
            >
              <template #suffix>
                <ElIcon class="c-p" @click="handleUnlock">
                  <Unlock />
                </ElIcon>
              </template>
            </ElInput>
          </ElFormItem>

          <ElButton type="primary" class="w-full mt-2" @click="handleUnlock" v-ripple>
            {{ $t('lockScreen.unlock.btnText') }}
          </ElButton>
          <div class="w-full text-center">
            <ElButton
              text
              class="mt-2.5 !text-g-600 hover:!text-theme hover:!bg-transparent"
              @click="toLogin"
            >
              {{ $t('lockScreen.unlock.backBtnText') }}
            </ElButton>
          </div>
        </ElForm>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { Lock, Unlock } from '@element-plus/icons-vue'
  import type { FormInstance, FormRules } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import { useUserStore } from '@/store/modules/user'
  import { mittBus } from '@/utils/sys'

  // 国际化
  const { t } = useI18n()

  // Store
  const userStore = useUserStore()
  const { info: userInfo, lockPassword, isLock } = storeToRefs(userStore)

  // 响应式数据
  const visible = ref<boolean>(false)
  const lockInputRef = ref<any>(null)
  const unlockInputRef = ref<any>(null)

  // 表单相关
  const formRef = ref<FormInstance>()
  const unlockFormRef = ref<FormInstance>()

  const formData = reactive({
    password: ''
  })

  const unlockForm = reactive({
    password: ''
  })

  // 表单验证规则
  const rules = computed<FormRules>(() => ({
    password: [
      {
        required: true,
        message: t('lockScreen.lock.inputPlaceholder'),
        trigger: 'blur'
      }
    ]
  }))

  /**
   * 校验锁屏密码。
   * 注意:锁屏仅是防"旁边的人顺手操作"的 UX 层,客户端无法提供真实机密性,
   * 因此密码只保存在内存(不落 localStorage、不做伪加密),真正的安全边界
   * 是后端会话(操作接口全部经 HttpOnly Cookie/Bearer 鉴权)。
   */
  const verifyPassword = (inputPassword: string, storedPassword: string): boolean => {
    return inputPassword === storedPassword
  }

  // 事件处理函数
  const handleKeydown = (event: KeyboardEvent) => {
    if (event.altKey && event.key.toLowerCase() === '¬') {
      event.preventDefault()
      visible.value = true
    }
  }

  const handleDialogOpen = () => {
    setTimeout(() => {
      lockInputRef.value?.input?.focus()
    }, 100)
  }

  const handleLock = async () => {
    if (!formRef.value) return

    await formRef.value.validate((valid, fields) => {
      if (valid) {
        userStore.setLockStatus(true)
        userStore.setLockPassword(formData.password)
        visible.value = false
        formData.password = ''
      } else {
        console.error('表单验证失败:', fields)
      }
    })
  }

  const handleUnlock = async () => {
    if (!unlockFormRef.value) return

    await unlockFormRef.value.validate((valid, fields) => {
      if (valid) {
        const isValid = verifyPassword(unlockForm.password, lockPassword.value)

        if (isValid) {
          try {
            userStore.setLockStatus(false)
            userStore.setLockPassword('')
            unlockForm.password = ''
            visible.value = false
          } catch (error) {
            console.error('更新store失败:', error)
          }
        } else {
          // 触发抖动动画
          const inputElement = unlockInputRef.value?.$el
          if (inputElement) {
            inputElement.classList.add('shake-animation')
            setTimeout(() => {
              inputElement.classList.remove('shake-animation')
            }, 300)
          }
          ElMessage.error(t('lockScreen.pwdError'))
          unlockForm.password = ''
        }
      } else {
        console.error('表单验证失败:', fields)
      }
    })
  }

  const toLogin = () => {
    userStore.logOut()
  }

  const openLockScreen = () => {
    visible.value = true
  }

  // 监听锁屏状态变化
  watch(isLock, (newValue) => {
    if (newValue) {
      document.body.style.overflow = 'hidden'
      setTimeout(() => {
        unlockInputRef.value?.input?.focus()
      }, 100)
    } else {
      document.body.style.overflow = 'auto'
    }
  })

  // 生命周期钩子
  onMounted(() => {
    mittBus.on('openLockScreen', openLockScreen)
    document.addEventListener('keydown', handleKeydown)

    if (isLock.value) {
      visible.value = true
      setTimeout(() => {
        unlockInputRef.value?.input?.focus()
      }, 100)
    }
  })

  onUnmounted(() => {
    document.removeEventListener('keydown', handleKeydown)
    document.body.style.overflow = 'auto'
  })
</script>

<style lang="scss" scoped>
  .layout-lock-screen :deep(.el-dialog) {
    border-radius: 10px;
  }

  .unlock-content {
    position: fixed;
    inset: 0;
    z-index: 2500;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    background-color: #fff;
    background-image: url('@imgs/lock/bg_light.webp');
    background-size: cover;
    transition: transform 0.3s ease-in-out;
  }

  .dark {
    .unlock-content {
      background-image: url('@imgs/lock/bg_dark.webp');
    }
  }

  @keyframes shake {
    0%,
    100% {
      transform: translateX(0);
    }

    10%,
    30%,
    50%,
    70%,
    90% {
      transform: translateX(-10px);
    }

    20%,
    40%,
    60%,
    80%,
    90% {
      transform: translateX(10px);
    }
  }

  .shake-animation {
    animation: shake 0.5s ease-in-out;
  }
</style>
