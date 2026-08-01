<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import {
  checkUpdate,
  getVersions,
  runUpdate,
  getUpdateLog,
} from '@/api/update'
import type { UpdateCheck, VersionInfo, UpdateResult, UpdateLog } from '@/api/update'

defineOptions({ name: 'UpdateIndex' })

const { t } = useI18n()

// 当前状态
const checking = ref(false)
const checkResult = ref<UpdateCheck | null>(null)

// 版本历史
const versions = ref<VersionInfo[]>([])
const versionsLoading = ref(false)

// 更新执行
const updating = ref(false)
const updateLog = ref<UpdateLog | null>(null)
const successVisible = ref(false)
const successResult = ref<UpdateResult | null>(null)
const failedVisible = ref(false)
const failedResult = ref<{ message: string; log: string } | null>(null)

// 日期格式化
const formatDate = (iso?: string) => {
  if (!iso) return '-'
  const d = new Date(iso)
  if (isNaN(d.getTime())) return iso
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
}

const handleCheck = async () => {
  checking.value = true
  try {
    checkResult.value = await checkUpdate()
    if (!checkResult.value.has_update) {
      ElMessage.info(t('zcard.update.noUpdate'))
    }
  } catch (e: any) {
    checkResult.value = null
    ElMessage.error(e?.message || t('zcard.update.checkFailed'))
  } finally {
    checking.value = false
  }
}

const handleRunUpdate = () => {
  if (!checkResult.value?.has_update) return
  ElMessageBox.confirm(t('zcard.update.updateConfirmTip'), t('zcard.update.updateConfirm'), {
    type: 'warning',
    confirmButtonText: t('zcard.update.runUpdate'),
    cancelButtonText: t('zcard.common.cancel'),
    confirmButtonClass: 'el-button--danger',
  })
    .then(async () => {
      await performUpdate()
    })
    .catch(() => {})
}

const performUpdate = async () => {
  updating.value = true
  failedVisible.value = false
  // 后台可能正在写入日志,这里异步拉取一次以备用
  try {
    updateLog.value = await getUpdateLog()
  } catch {
    /* 忽略日志拉取失败 */
  }

  try {
    const result = await runUpdate()
    successResult.value = result
    updating.value = false
    successVisible.value = true
  } catch (e: any) {
    const message = e?.message || t('zcard.update.updateFailed')
    let log = ''
    try {
      const lg = await getUpdateLog()
      log = lg?.log || ''
    } catch {
      /* ignore */
    }
    failedResult.value = { message, log }
    updating.value = false
    failedVisible.value = true
  }
}

const handleRefresh = () => {
  window.location.reload()
}

const loadVersions = async () => {
  versionsLoading.value = true
  try {
    versions.value = (await getVersions()) || []
  } catch {
    versions.value = []
  } finally {
    versionsLoading.value = false
  }
}

const handleRetry = () => {
  failedVisible.value = false
  handleCheck()
}

onMounted(() => {
  // 进入页面自动检查一次,并加载版本历史
  handleCheck()
  loadVersions()
})
</script>

<template>
  <div class="update-page art-full-height">
    <!-- Section 1: 当前状态 -->
    <ElCard class="art-table-card" shadow="never">
      <template #header>
        <div class="card-header">
          <span class="header-title">{{ t('zcard.update.currentStatus') }}</span>
          <ElButton type="primary" :loading="checking" @click="handleCheck">
            🔄 {{ t('zcard.update.checkUpdate') }}
          </ElButton>
        </div>
      </template>

      <div v-loading="checking" class="status-block">
        <div v-if="checkResult" class="version-row">
          <div class="version-box">
            <div class="version-label">{{ t('zcard.update.currentVersion') }}</div>
            <div class="version-num current">v{{ checkResult.current_version }}</div>
          </div>
          <div class="arrow">→</div>
          <div class="version-box">
            <div class="version-label">{{ t('zcard.update.latestVersion') }}</div>
            <div class="version-num latest">v{{ checkResult.latest_version }}</div>
          </div>
        </div>

        <div v-if="checkResult" class="status-row">
          <ElTag
            v-if="checkResult.has_update"
            type="success"
            size="large"
            effect="dark"
          >✅ {{ t('zcard.update.hasUpdate') }}</ElTag>
          <ElTag v-else type="primary" size="large" effect="dark">
            {{ t('zcard.update.noUpdate') }}
          </ElTag>
          <span v-if="checkResult.published_at" class="published-at">
            {{ t('zcard.update.releasedAt') }}: {{ formatDate(checkResult.published_at) }}
          </span>
          <ElButton
            v-if="checkResult.has_update"
            type="success"
            :loading="updating"
            @click="handleRunUpdate"
          >⬆️ {{ t('zcard.update.runUpdate') }}</ElButton>
          <ElButton
            v-if="checkResult.release_url"
            tag="a"
            :href="checkResult.release_url"
            target="_blank"
            rel="noopener"
          >🔗 {{ t('zcard.update.viewRelease') }}</ElButton>
        </div>

        <div v-if="checkResult?.release_notes" class="release-notes">
          <div class="notes-label">{{ t('zcard.update.releaseNotes') }}</div>
          <pre class="notes-content">{{ checkResult.release_notes }}</pre>
        </div>

        <ElEmpty
          v-if="!checkResult && !checking"
          :description="t('zcard.update.checkTip')"
        />
      </div>
    </ElCard>

    <!-- Section 3: 版本历史 -->
    <ElCard class="art-table-card" shadow="never">
      <template #header>
        <div class="card-header">
          <span class="header-title">{{ t('zcard.update.versionHistory') }}</span>
          <ElButton text type="primary" :loading="versionsLoading" @click="loadVersions">
            🔄 {{ t('zcard.update.refreshHistory') }}
          </ElButton>
        </div>
      </template>

      <ElTable v-loading="versionsLoading" :data="versions" border stripe>
        <ElTableColumn :label="t('zcard.update.versionCol')" width="160">
          <template #default="{ row }">
            <div class="version-cell">
              <ElTag type="primary" effect="plain">v{{ row.version }}</ElTag>
              <ElTag v-if="row.prerelease" type="warning" size="small">
                {{ t('zcard.update.prerelease') }}
              </ElTag>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="t('zcard.update.releasedAt')"
          width="180"
          align="center"
        >
          <template #default="{ row }">
            {{ formatDate(row.published_at) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.update.releaseNotes')" min-width="320">
          <template #default="{ row }">
            <div class="notes-truncate" :title="row.notes">{{ row.notes }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.common.actions')" width="100" align="center">
          <template #default="{ row }">
            <ElButton
              v-if="row.url"
              tag="a"
              :href="row.url"
              target="_blank"
              rel="noopener"
              text
              type="primary"
              size="small"
            >🔗</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>

    <!-- 更新执行:全屏 Loading -->
    <ElDialog
      v-model="updating"
      :show-close="false"
      :close-on-click-modal="false"
      :close-on-press-escape="false"
      width="420px"
      class="updating-dialog"
      align-center
      append-to-body
    >
      <div class="updating-body">
        <div class="spinner">⏳</div>
        <div class="updating-title">{{ t('zcard.update.updating') }}</div>
        <div class="updating-tip">{{ t('zcard.update.updatingTip') }}</div>
        <ElProgress :percentage="100" :indeterminate="true" :show-text="false" />
        <pre v-if="updateLog?.log" class="log-preview">{{ updateLog.log }}</pre>
      </div>
    </ElDialog>

    <!-- 成功对话框 -->
    <ElDialog
      v-model="successVisible"
      :title="t('zcard.update.updateSuccess')"
      width="640px"
      :close-on-click-modal="false"
      align-center
      append-to-body
    >
      <div class="result-body">
        <div class="result-icon success">✅</div>
        <div v-if="successResult" class="version-change">
          <span class="ver old">v{{ successResult.old_version || '-' }}</span>
          <span class="arrow">→</span>
          <span class="ver new">v{{ successResult.new_version || '-' }}</span>
        </div>
        <div v-if="successResult?.message" class="result-message">
          {{ successResult.message }}
        </div>
        <details v-if="successResult?.log" class="log-details">
          <summary>{{ t('zcard.update.viewLog') }}</summary>
          <pre class="log-content">{{ successResult.log }}</pre>
        </details>
      </div>
      <template #footer>
        <ElButton type="primary" @click="handleRefresh">
          🔄 {{ t('zcard.update.refresh') }}
        </ElButton>
      </template>
    </ElDialog>

    <!-- 失败对话框 -->
    <ElDialog
      v-model="failedVisible"
      :title="t('zcard.update.updateFailed')"
      width="640px"
      align-center
      append-to-body
    >
      <div class="result-body">
        <div class="result-icon failed">❌</div>
        <div v-if="failedResult?.message" class="result-message failed-msg">
          {{ failedResult.message }}
        </div>
        <ElAlert
          :title="t('zcard.update.rollbackHint')"
          type="warning"
          :closable="false"
          show-icon
        />
        <details v-if="failedResult?.log" class="log-details" open>
          <summary>{{ t('zcard.update.viewLog') }}</summary>
          <pre class="log-content">{{ failedResult.log }}</pre>
        </details>
      </div>
      <template #footer>
        <ElButton @click="failedVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" @click="handleRetry">
          🔄 {{ t('zcard.update.checkUpdate') }}
        </ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<style lang="scss" scoped>
  .update-page {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }
  .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .header-title {
    font-size: 16px;
    font-weight: 600;
  }
  .status-block {
    min-height: 120px;
  }
  .version-row {
    display: flex;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
    margin-bottom: 20px;
  }
  .version-box {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .version-label {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }
  .version-num {
    font-size: 28px;
    font-weight: 700;
    font-family: monospace;
    &.current {
      color: var(--el-color-primary);
    }
    &.latest {
      color: var(--el-color-success);
    }
  }
  .arrow {
    font-size: 24px;
    color: var(--el-text-color-secondary);
    font-weight: 700;
  }
  .status-row {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 20px;
  }
  .published-at {
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }
  .release-notes {
    border-top: 1px dashed var(--el-border-color);
    padding-top: 16px;
  }
  .notes-label {
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--el-text-color-regular);
  }
  .notes-content {
    background: var(--el-fill-color-light);
    border-radius: 6px;
    padding: 12px;
    font-family: monospace;
    font-size: 13px;
    line-height: 1.6;
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 320px;
    overflow: auto;
    margin: 0;
    color: var(--el-text-color-regular);
  }
  .version-cell {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
  }
  .notes-truncate {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: pre-wrap;
    word-break: break-word;
    font-size: 13px;
    line-height: 1.5;
    color: var(--el-text-color-regular);
  }

  // 更新中遮罩
  .updating-body {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    padding: 16px 8px 8px;
  }
  .spinner {
    font-size: 48px;
    animation: pulse 1.4s ease-in-out infinite;
  }
  @keyframes pulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.6; transform: scale(0.92); }
  }
  .updating-title {
    font-size: 18px;
    font-weight: 700;
  }
  .updating-tip {
    font-size: 13px;
    color: var(--el-text-color-secondary);
    text-align: center;
  }
  .log-preview {
    width: 100%;
    max-height: 120px;
    overflow: auto;
    background: var(--el-fill-color-light);
    padding: 8px;
    border-radius: 4px;
    font-family: monospace;
    font-size: 11px;
    line-height: 1.4;
    color: var(--el-text-color-secondary);
    margin: 0;
    white-space: pre-wrap;
    word-break: break-word;
  }

  // 结果对话框
  .result-body {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
    padding: 8px 0;
  }
  .result-icon {
    font-size: 56px;
    line-height: 1;
  }
  .version-change {
    display: flex;
    align-items: center;
    gap: 16px;
    font-size: 22px;
    font-weight: 700;
    font-family: monospace;
    .ver.old { color: var(--el-text-color-secondary); }
    .ver.new { color: var(--el-color-success); }
    .arrow { color: var(--el-text-color-secondary); font-size: 22px; }
  }
  .result-message {
    font-size: 14px;
    color: var(--el-text-color-regular);
    text-align: center;
    &.failed-msg {
      color: var(--el-color-danger);
      font-weight: 600;
    }
  }
  .log-details {
    width: 100%;
    background: var(--el-fill-color-light);
    border-radius: 6px;
    padding: 8px 12px;
    summary {
      cursor: pointer;
      font-size: 13px;
      color: var(--el-text-color-secondary);
      user-select: none;
    }
  }
  .log-content {
    margin: 8px 0 0;
    font-family: monospace;
    font-size: 12px;
    line-height: 1.5;
    color: var(--el-text-color-regular);
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 240px;
    overflow: auto;
  }
</style>
