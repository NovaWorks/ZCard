<!-- 工作台 - 欢迎页 -->
<template>
  <div class="p-2">
    <ElCard class="welcome-card" shadow="never">
      <div class="welcome-inner">
        <div class="welcome-icon">
          <ArtSvgIcon icon="ri:bank-card-2-line" style="font-size: 56px" />
        </div>
        <div class="welcome-text">
          <h1 class="welcome-title">{{ t('zcard.dashboard.title') }}</h1>
          <p class="welcome-sub">
            {{ greeting }}，{{ displayName }}，{{ t('zcard.dashboard.welcomeBack') }}。
          </p>
          <p class="welcome-tip">{{ t('zcard.dashboard.tip') }}</p>
        </div>
      </div>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { computed } from 'vue'
  import { useI18n } from 'vue-i18n'
  import { useUserStore } from '@/store/modules/user'

  defineOptions({ name: 'Console' })

  const { t } = useI18n()

  const userStore = useUserStore()

  const displayName = computed(() => userStore.info?.username || t('zcard.dashboard.admin'))

  // 根据当前时间给出问候语
  const greeting = computed(() => {
    const h = new Date().getHours()
    if (h < 6) return t('zcard.dashboard.gDawn')
    if (h < 9) return t('zcard.dashboard.gMorning')
    if (h < 12) return t('zcard.dashboard.gForenoon')
    if (h < 14) return t('zcard.dashboard.gNoon')
    if (h < 17) return t('zcard.dashboard.gAfternoon')
    if (h < 19) return t('zcard.dashboard.gEvening')
    return t('zcard.dashboard.gNight')
  })
</script>

<style lang="scss" scoped>
  .welcome-card {
    border-radius: 12px;
  }

  .welcome-inner {
    display: flex;
    align-items: center;
    gap: 24px;
    padding: 24px 8px;
  }

  .welcome-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 96px;
    height: 96px;
    flex-shrink: 0;
    border-radius: 50%;
    background: var(--main-color, #409eff);
    color: #fff;
  }

  .welcome-title {
    margin: 0 0 8px;
    font-size: 26px;
    font-weight: 600;
    line-height: 1.2;
  }

  .welcome-sub {
    margin: 0 0 6px;
    font-size: 15px;
    color: var(--art-gray-700, #666);
  }

  .welcome-tip {
    margin: 0;
    font-size: 13px;
    color: var(--art-gray-500, #999);
  }

  @media (max-width: 640px) {
    .welcome-inner {
      flex-direction: column;
      text-align: center;
    }
  }
</style>
