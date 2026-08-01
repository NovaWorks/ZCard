<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import {
  checkUpdate,
  getVersions,
  runUpdate,
} from '@/api/update'
import type { UpdateCheck, VersionInfo, UpdateResult } from '@/api/update'

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
const successVisible = ref(false)
const successResult = ref<UpdateResult | null>(null)
const failedVisible = ref(false)
const failedResult = ref<{ message: string; log: string } | null>(null)

// 版本详情弹窗
const versionDetailVisible = ref(false)
const versionDetailData = ref<VersionInfo | null>(null)

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

    // 有新版本 → 弹出更新确认对话框(大厂交互:自动弹窗 + 明确的更新引导)
    if (checkResult.value.has_update) {
      const msgHtml = `
        <div style="line-height:1.8;font-size:14px;">
          <p style="margin:0 0 12px;">
            <strong>${t('zcard.update.currentVersion')}:</strong>
            <span style="font-family:monospace;font-size:16px;color:var(--el-text-color-secondary);">v${checkResult.value.current_version}</span>
          </p>
          <p style="margin:0 0 12px;">
            <strong>${t('zcard.update.latestVersion')}:</strong>
            <span style="font-family:monospace;font-size:18px;color:var(--el-color-success);font-weight:700;">v${checkResult.value.latest_version}</span>
          </p>
          <div style="margin:12px 0;padding:12px;background:var(--el-fill-color-light);border-radius:8px;max-height:200px;overflow:auto;">
            <pre style="margin:0;font-size:12px;white-space:pre-wrap;word-break:break-word;font-family:inherit;line-height:1.6;">${checkResult.value.release_notes || ''}</pre>
          </div>
        </div>
      `
      ElMessageBox({
        title: `🚀 ${t('zcard.update.hasUpdate')}`,
        message: msgHtml,
        dangerouslyUseHTMLString: true,
        confirmButtonText: `⬆️ ${t('zcard.update.runUpdate')}`,
        cancelButtonText: t('zcard.common.cancel'),
        confirmButtonClass: 'el-button--success',
        type: 'success',
        showCancelButton: true,
        closeOnClickModal: false,
      })
        .then(() => {
          performUpdate()
        })
        .catch(() => {
          ElMessage.info(t('zcard.update.updateCancelled'))
        })
    } else {
      ElMessage.success(t('zcard.update.noUpdate'))
    }
  } catch (e: any) {
    checkResult.value = null
    ElMessage.error(e?.message || t('zcard.update.checkFailed'))
  } finally {
    checking.value = false
  }
}

const performUpdate = async () => {
  updating.value = true
  failedVisible.value = false

  try {
    const result = await runUpdate()
    successResult.value = result
    updating.value = false
    successVisible.value = true
  } catch (e: any) {
    const message = e?.response?.data?.message || e?.message || t('zcard.update.updateFailed')
    const log = e?.response?.data?.log || ''
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

// 点击版本行 → 弹出完整详情(大厂交互:可点击查看完整信息)
const showVersionDetail = (row: VersionInfo) => {
  versionDetailData.value = row
  versionDetailVisible.value = true
}

onMounted(() => {
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
            ✅ {{ t('zcard.update.noUpdate') }}
          </ElTag>
          <span v-if="checkResult.published_at" class="published-at">
            {{ t('zcard.update.releasedAt') }}: {{ formatDate(checkResult.published_at) }}
          </span>
          <ElButton
            v-if="checkResult.has_update"
            type="success"
            :loading="updating"
            @click="performUpdate"
          >⬆️ {{ t('zcard.update.runUpdate') }}</ElButton>
          <ElButton
            v-if="checkResult.release_url"
            tag="a"
            :href="checkResult.release_url"
            target="_blank"
            rel="noopener"
          >🔗 GitHub</ElButton>
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

    <!-- Section 2: 版本历史 -->
    <ElCard class="art-table-card" shadow="never">
      <template #header>
        <div class="card-header">
          <span class="header-title">{{ t('zcard.update.versionHistory') }}</span>
          <ElButton text type="primary" :loading="versionsLoading" @click="loadVersions">
            🔄 {{ t('zcard.update.refreshHistory') }}
          </ElButton>
        </div>
      </template>

      <ElTable
        v-loading="versionsLoading"
        :data="versions"
        border
        stripe
        @row-click="showVersionDetail"
        :row-style="{ cursor: 'pointer' }"
      >
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
            <div class="notes-truncate">{{ row.notes }}</div>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.common.actions')" width="120" align="center">
          <template #default="{ row }">
            <ElButton text type="primary" size="small" @click.stop="showVersionDetail(row)">
              📋 {{ t('zcard.update.viewDetail') }}
            </ElButton>
          </template>
        </ElTableColumn>
      </ElTable>
    </ElCard>

    <!-- 更新执行:全屏遮罩 -->
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

    <!-- 版本详情弹窗(点击版本行或查看详情按钮) -->
    <ElDialog
      v-model="versionDetailVisible"
      :title="versionDetailData ? `v${versionDetailData.version}` : ''"
      width="720px"
      align-center
      append-to-body
    >
      <div v-if="versionDetailData" class="version-detail-body">
        <div class="detail-meta">
          <ElTag type="primary" size="large">v{{ versionDetailData.version }}</ElTag>
          <ElTag v-if="versionDetailData.prerelease" type="warning">
            {{ t('zcard.update.prerelease') }}
          </ElTag>
          <span class="detail-date">{{ formatDate(versionDetailData.published_at) }}</span>
          <ElButton
            v-if="versionDetailData.url"
            tag="a"
            :href="versionDetailData.url"
            target="_blank"
            rel="noopener"
            size="small"
          >🔗 GitHub</ElButton>
        </div>
        <div class="detail-notes">
          <div class="notes-label">{{ t('zcard.update.releaseNotes') }}</div>
          <pre class="notes-content">{{ versionDetailData.notes || '-' }}</pre>
        </div>
      </div>
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
    max-height: 400px;
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

  // 版本详情弹窗
  .version-detail-body {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }
  .detail-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .detail-date {
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }
  .detail-notes {
    .notes-content {
      max-height: 480px;
    }
  }
</style>
