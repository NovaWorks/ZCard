<!-- 工作台 - 欢迎页 + 统计卡片 -->
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

    <!-- 统计卡片 -->
    <div class="stats-section" v-loading="loading">
      <div class="stats-grid">
        <div
          v-for="card in statCards"
          :key="card.key"
          class="stat-card"
          :style="{ '--accent': card.color }"
        >
          <div class="stat-icon">{{ card.icon }}</div>
          <div class="stat-body">
            <div class="stat-label">{{ t(card.label) }}</div>
            <div class="stat-value">
              {{ card.isAmount ? '¥' + formatAmount(card.value) : formatCount(card.value) }}
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
  import { computed, onMounted, ref } from 'vue'
  import { useI18n } from 'vue-i18n'
  import { useUserStore } from '@/store/modules/user'
  import { getProductStats, type ProductStats } from '@/api/products'
  import { getStats as getOrderStats, type OrderStats } from '@/api/orders'
  import { getUserStats, type UserStats } from '@/api/users'
  import { getCommissionStats, type CommissionStats } from '@/api/commission'

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

  const loading = ref(false)

  const productStats = ref<ProductStats | null>(null)
  const orderStats = ref<OrderStats | null>(null)
  const userStats = ref<UserStats | null>(null)
  const commissionStats = ref<CommissionStats | null>(null)

  /** 分(整数)→元(2位小数字符串) */
  const formatAmount = (fen: number | string | null | undefined): string =>
    (Number(fen || 0) / 100).toFixed(2)

  /** 数量直接显示 */
  const formatCount = (n: number | string | null | undefined): string =>
    Number(n || 0).toLocaleString()

  interface StatCard {
    key: string
    label: string
    value: number | string | null | undefined
    icon: string
    color: string
    isAmount: boolean
  }

  const statCards = computed<StatCard[]>(() => [
    {
      key: 'productTotal',
      label: 'zcard.dashboard.statProductTotal',
      value: productStats.value?.total,
      icon: '📦',
      color: '#409eff',
      isAmount: false,
    },
    {
      key: 'orderTotal',
      label: 'zcard.dashboard.statOrderTotal',
      value: orderStats.value?.total_count,
      icon: '🧾',
      color: '#909399',
      isAmount: false,
    },
    {
      key: 'orderPending',
      label: 'zcard.dashboard.statOrderPending',
      value: orderStats.value?.pending_amount,
      icon: '⏳',
      color: '#e6a23c',
      isAmount: true,
    },
    {
      key: 'orderRevenue',
      label: 'zcard.dashboard.statRevenue',
      value: orderStats.value?.paid_amount,
      icon: '💰',
      color: '#67c23a',
      isAmount: true,
    },
    {
      key: 'userTotal',
      label: 'zcard.dashboard.statUserTotal',
      value: userStats.value?.total,
      icon: '👤',
      color: '#9254de',
      isAmount: false,
    },
    {
      key: 'commissionTotal',
      label: 'zcard.dashboard.statCommission',
      value: commissionStats.value?.total_amount,
      icon: '🎯',
      color: '#f56c6c',
      isAmount: true,
    },
  ])

  const fetchStats = async () => {
    loading.value = true
    // 任一接口失败不影响其他展示
    const results = await Promise.allSettled([
      getProductStats(),
      getOrderStats({}),
      getUserStats(),
      getCommissionStats(),
    ])
    if (results[0].status === 'fulfilled') productStats.value = results[0].value
    if (results[1].status === 'fulfilled') orderStats.value = results[1].value
    if (results[2].status === 'fulfilled') userStats.value = results[2].value
    if (results[3].status === 'fulfilled') commissionStats.value = results[3].value
    loading.value = false
  }

  onMounted(() => {
    fetchStats()
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

  // 统计卡片
  .stats-section {
    margin-top: 16px;
  }

  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
  }

  .stat-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 8px;
    border-left: 3px solid var(--accent);
  }

  .stat-icon {
    font-size: 28px;
  }

  .stat-label {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .stat-value {
    margin-top: 4px;
    font-size: 20px;
    font-weight: 700;
    color: var(--el-text-color-primary);
  }
</style>
