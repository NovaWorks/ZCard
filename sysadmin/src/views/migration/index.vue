<script setup lang="ts">
  import { ref, onMounted } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import { getMigrationPreflight, getMigrationCommand, getMigrationKeys } from '@/api/migration'
  import type { MigrationPreflight, MigrationCommand } from '@/api/migration'

  defineOptions({ name: 'MigrationIndex' })

  const { t } = useI18n()

  const loading = ref(false)
  const preflight = ref<MigrationPreflight | null>(null)
  const command = ref<MigrationCommand | null>(null)
  const downloading = ref(false)

  const statusIcon: Record<string, string> = {
    ok: 'ri:checkbox-circle-line',
    warn: 'ri:alert-line',
    fail: 'ri:close-circle-line',
    info: 'ri:information-line'
  }
  const statusColor: Record<string, string> = {
    ok: 'var(--el-color-success)',
    warn: 'var(--el-color-warning)',
    fail: 'var(--el-color-danger)',
    info: 'var(--el-color-info)'
  }

  const statLabels: Record<string, string> = {
    users: 'zcard.migration.statUsers',
    products: 'zcard.migration.statProducts',
    cards: 'zcard.migration.statCards',
    orders: 'zcard.migration.statOrders',
    payments: 'zcard.migration.statPayments',
    bills: 'zcard.migration.statBills'
  }

  const load = async () => {
    loading.value = true
    try {
      const [pf, cmd] = await Promise.all([getMigrationPreflight(), getMigrationCommand()])
      preflight.value = pf
      command.value = cmd
    } finally {
      loading.value = false
    }
  }

  const copyCommand = async (cmd: string) => {
    try {
      await navigator.clipboard.writeText(cmd)
      ElMessage.success(t('zcard.migration.copied'))
    } catch {
      ElMessage.error(t('zcard.migration.copyFailed'))
    }
  }

  /** 下载密钥包：二次确认（内容含数据库凭据与加密密钥） */
  const downloadKeys = () => {
    ElMessageBox.confirm(t('zcard.migration.keysConfirm'), t('zcard.migration.keysTitle'), {
      type: 'warning',
      confirmButtonText: t('zcard.migration.keysConfirmBtn'),
      cancelButtonText: t('zcard.common.cancel')
    })
      .then(async () => {
        downloading.value = true
        try {
          const data = await getMigrationKeys()
          const blob = new Blob([data.content], { type: 'text/plain;charset=utf-8' })
          const url = URL.createObjectURL(blob)
          const a = document.createElement('a')
          a.href = url
          a.download = data.filename
          a.click()
          URL.revokeObjectURL(url)
          ElMessage.success(t('zcard.migration.keysDownloaded'))
        } finally {
          downloading.value = false
        }
      })
      .catch(() => {})
  }

  onMounted(load)
</script>

<template>
  <div class="migration-page art-full-height" v-loading="loading">
    <!-- ========== Hero：迁移就绪状态 ========== -->
    <div class="status-hero">
      <div class="hero-bg-icon"><ArtSvgIcon icon="ri:database-2-line" /></div>
      <div class="hero-content">
        <div class="hero-title-row">
          <div class="hero-title">{{ t('zcard.migration.title') }}</div>
          <ElTag
            v-if="preflight"
            :type="preflight.ready ? 'success' : 'danger'"
            size="large"
            effect="dark"
            round
          >
            <ArtSvgIcon
              :icon="preflight.ready ? 'ri:checkbox-circle-line' : 'ri:close-circle-line'"
            />
            {{ preflight.ready ? t('zcard.migration.ready') : t('zcard.migration.notReady') }}
          </ElTag>
        </div>
        <div class="hero-desc">{{ t('zcard.migration.desc') }}</div>
        <div class="hero-actions">
          <ElButton type="primary" plain :loading="loading" @click="load" round>
            <ArtSvgIcon icon="ri:refresh-line" /> {{ t('zcard.migration.recheck') }}
          </ElButton>
          <ElButton type="warning" :loading="downloading" @click="downloadKeys" round>
            <ArtSvgIcon icon="ri:key-2-line" /> {{ t('zcard.migration.downloadKeys') }}
          </ElButton>
        </div>
        <div class="hero-meta" v-if="preflight">
          {{ t('zcard.migration.currentVersion') }}: v{{ preflight.version }}
          <template v-if="preflight.keys.card_key !== 'missing'">
            ｜ {{ t('zcard.migration.cardKeyFrom') }}:
            {{
              preflight.keys.card_key === 'settings'
                ? t('zcard.migration.cardKeySettings')
                : t('zcard.migration.cardKeyEnv')
            }}
          </template>
        </div>
      </div>
    </div>

    <!-- ========== Section 1: 源端自检 ========== -->
    <ElCard class="art-table-card" shadow="never">
      <template #header>
        <div class="card-header">
          <span class="header-title"
            ><ArtSvgIcon icon="ri:shield-check-line" />
            {{ t('zcard.migration.preflightTitle') }}</span
          >
        </div>
      </template>
      <div v-if="preflight" class="check-list">
        <div v-for="c in preflight.checks" :key="c.name + c.message" class="check-item">
          <ArtSvgIcon
            :icon="statusIcon[c.status] || 'ri:information-line'"
            :style="{ color: statusColor[c.status] }"
            class="check-icon"
          />
          <span class="check-name">{{ c.name }}</span>
          <span class="check-message">{{ c.message }}</span>
        </div>
      </div>

      <div class="stats-row" v-if="preflight">
        <div v-for="(v, k) in preflight.stats" :key="k" class="stat-box">
          <div class="stat-num">{{ v ?? '-' }}</div>
          <div class="stat-label">{{ t(statLabels[k] || k) }}</div>
        </div>
      </div>

      <ElAlert
        v-if="preflight && (preflight.pending.orders || preflight.pending.payments)"
        type="warning"
        :closable="false"
        class="pending-alert"
        :title="t('zcard.migration.pendingTitle')"
        :description="
          t('zcard.migration.pendingDesc', {
            orders: preflight.pending.orders ?? '-',
            payments: preflight.pending.payments ?? '-'
          })
        "
      />
    </ElCard>

    <!-- ========== Section 2: 切换流程与命令 ========== -->
    <ElCard class="art-table-card" shadow="never">
      <template #header>
        <div class="card-header">
          <span class="header-title"
            ><ArtSvgIcon icon="ri:route-line" /> {{ t('zcard.migration.stepsTitle') }}</span
          >
        </div>
      </template>
      <div v-if="command" class="steps-list">
        <div v-for="s in command.steps" :key="s.title" class="step-item">
          <div class="step-title">{{ s.title }}</div>
          <div class="step-detail">{{ s.detail }}</div>
        </div>
      </div>

      <template v-if="command">
        <div v-for="(cmd, key) in command.commands" :key="key" class="cmd-box">
          <div class="cmd-head">
            <span class="cmd-label">{{ t(`zcard.migration.cmd_${key}`) }}</span>
            <ElButton text type="primary" size="small" @click="copyCommand(cmd)">
              <ArtSvgIcon icon="ri:file-copy-line" /> {{ t('zcard.migration.copy') }}
            </ElButton>
          </div>
          <pre class="cmd-text">{{ cmd }}</pre>
        </div>
      </template>

      <ElAlert
        v-if="command"
        type="info"
        :closable="false"
        class="notes-alert"
        :title="t('zcard.migration.notesTitle')"
      >
        <ul class="notes-list">
          <li v-for="n in command.notes" :key="n">{{ n }}</li>
        </ul>
      </ElAlert>
    </ElCard>
  </div>
</template>

<style lang="scss" scoped>
  .migration-page {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  .status-hero {
    position: relative;
    display: flex;
    align-items: center;
    padding: 24px;
    overflow: hidden;
    background: var(--art-main-bg-color);
    border: 1px solid var(--art-border-color);
    border-radius: 12px;

    .hero-bg-icon {
      position: absolute;
      right: -10px;
      bottom: -20px;
      font-size: 140px;
      opacity: 0.06;
    }

    .hero-title-row {
      display: flex;
      gap: 14px;
      align-items: center;
    }

    .hero-title {
      font-size: 22px;
      font-weight: 600;
    }

    .hero-desc {
      max-width: 720px;
      margin-top: 8px;
      font-size: 13px;
      color: var(--art-text-color-secondary);
    }

    .hero-actions {
      display: flex;
      gap: 10px;
      margin-top: 14px;
    }

    .hero-meta {
      margin-top: 10px;
      font-size: 12px;
      color: var(--art-text-color-secondary);
    }
  }

  .card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;

    .header-title {
      display: flex;
      gap: 6px;
      align-items: center;
      font-size: 15px;
      font-weight: 600;
    }
  }

  .check-list {
    display: flex;
    flex-direction: column;
    gap: 10px;

    .check-item {
      display: flex;
      gap: 8px;
      align-items: baseline;
      font-size: 13px;

      .check-icon {
        position: relative;
        top: 2px;
        flex-shrink: 0;
      }

      .check-name {
        flex-shrink: 0;
        min-width: 110px;
        font-weight: 600;
      }

      .check-message {
        color: var(--art-text-color-secondary);
      }
    }
  }

  .stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
    gap: 12px;
    margin-top: 18px;

    .stat-box {
      padding: 12px;
      text-align: center;
      background: var(--art-bg-color, #f5f7fa);
      border-radius: 8px;

      .stat-num {
        font-size: 20px;
        font-weight: 700;
      }

      .stat-label {
        margin-top: 4px;
        font-size: 12px;
        color: var(--art-text-color-secondary);
      }
    }
  }

  .pending-alert {
    margin-top: 14px;
  }

  .steps-list {
    display: flex;
    flex-direction: column;
    gap: 14px;

    .step-item {
      padding-left: 12px;
      border-left: 3px solid var(--el-color-primary);

      .step-title {
        font-size: 14px;
        font-weight: 600;
      }

      .step-detail {
        margin-top: 4px;
        font-size: 13px;
        color: var(--art-text-color-secondary);
      }
    }
  }

  .cmd-box {
    margin-top: 14px;

    .cmd-head {
      display: flex;
      align-items: center;
      justify-content: space-between;

      .cmd-label {
        font-size: 13px;
        font-weight: 600;
        color: var(--el-color-primary);
      }
    }

    .cmd-text {
      padding: 10px 12px;
      margin: 6px 0 0;
      overflow-x: auto;
      font-family: monospace;
      font-size: 12.5px;
      line-height: 1.6;
      background: var(--art-bg-color, #f5f7fa);
      border-radius: 6px;
    }
  }

  .notes-alert {
    margin-top: 14px;

    .notes-list {
      padding-left: 18px;
      margin: 4px 0;
      font-size: 12.5px;

      li {
        margin-bottom: 4px;
      }
    }
  }
</style>
