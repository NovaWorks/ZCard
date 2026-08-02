<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import { marked } from 'marked'
import {
  checkUpdate,
  getVersions,
  runUpdate,
  rollbackUpdate,
} from '@/api/update'
import type { UpdateCheck, VersionInfo, UpdateResult } from '@/api/update'

/** Markdown → HTML(marked 已对 XSS 做基本转义;release notes 来自自己的 GitHub) */
const renderMd = (md?: string): string => {
  if (!md) return ''
  return marked.parse(md, { async: false }) as string
}

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

// 弹窗状态
const latestNotesVisible = ref(false)
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

const openVersionDetail = (ver: VersionInfo) => {
  versionDetailData.value = ver
  versionDetailVisible.value = true
}

const handleCheck = async () => {
  checking.value = true
  try {
    checkResult.value = await checkUpdate()

    if (checkResult.value.has_update) {
      const msgHtml = `
        <div style="line-height:1.8;font-size:14px;">
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
            <span style="font-size:13px;color:var(--el-text-color-secondary);">${t('zcard.update.currentVersion')}</span>
            <span style="font-family:monospace;font-size:18px;font-weight:600;color:var(--el-text-color-regular);">v${checkResult.value.current_version}</span>
            <span style="font-size:18px;color:var(--el-text-color-placeholder);">→</span>
            <span style="font-family:monospace;font-size:22px;font-weight:800;color:var(--el-color-success);">v${checkResult.value.latest_version}</span>
          </div>
          ${checkResult.value.release_notes ? `
          <div style="margin:8px 0;padding:14px 16px;background:var(--el-fill-color-light);border-radius:8px;max-height:240px;overflow:auto;border:1px solid var(--el-border-color-lighter);">
            <div style="font-size:12px;font-weight:600;color:var(--el-text-color-secondary);margin-bottom:8px;">${t('zcard.update.releaseNotes')}</div>
            <div class="markdown-body" style="margin:0;font-size:13px;line-height:1.7;color:var(--el-text-color-regular);">${renderMd(checkResult.value.release_notes)}</div>
          </div>` : ''}
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
        .then(() => performUpdate())
        .catch(() => ElMessage.info(t('zcard.update.updateCancelled')))
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

const handleRefresh = () => window.location.reload()

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

// 回退到上一个版本
const rollingBack = ref(false)
const handleRollback = () => {
  ElMessageBox.confirm(
    t('zcard.update.rollbackConfirmTip'),
    t('zcard.update.rollbackConfirm'),
    { type: 'warning', confirmButtonText: t('zcard.update.rollback'), cancelButtonText: t('zcard.common.cancel') }
  )
    .then(async () => {
      failedVisible.value = false
      updating.value = true
      rollingBack.value = true
      try {
        await rollbackUpdate()
        ElMessage.success(t('zcard.update.rollbackSuccess'))
        setTimeout(() => window.location.reload(), 1500)
      } catch (e: any) {
        ElMessage.error(e?.response?.data?.message || t('zcard.update.rollbackFailed'))
      } finally {
        updating.value = false
        rollingBack.value = false
      }
    })
    .catch(() => {})
}

onMounted(() => {
  handleCheck()
  loadVersions()
})
</script>

<template>
  <div class="update-page art-full-height">
    <!-- ========== Section 1: 当前版本状态卡 ========== -->
    <div class="status-hero" v-loading="checking">
      <div class="hero-bg-icon">📦</div>
      <div class="hero-content">
        <div class="hero-version-row">
          <div class="hero-version-box">
            <div class="hero-version-label">{{ t('zcard.update.currentVersion') }}</div>
            <div class="hero-version-num current">v{{ checkResult?.current_version || '...' }}</div>
          </div>
          <div class="hero-arrow" v-if="checkResult?.has_update">→</div>
          <div class="hero-version-box" v-if="checkResult?.has_update">
            <div class="hero-version-label">{{ t('zcard.update.latestVersion') }}</div>
            <div class="hero-version-num latest">v{{ checkResult?.latest_version }}</div>
          </div>
        </div>

        <div class="hero-actions">
          <ElTag v-if="checkResult && !checkResult.has_update" type="success" size="large" effect="dark" round>
            ✅ {{ t('zcard.update.noUpdate') }}
          </ElTag>
          <ElTag v-if="checkResult?.has_update" type="warning" size="large" effect="dark" round>
            🆕 {{ t('zcard.update.hasUpdate') }}
          </ElTag>

          <ElButton type="primary" plain :loading="checking" @click="handleCheck" round>
            🔄 {{ t('zcard.update.checkUpdate') }}
          </ElButton>
          <ElButton v-if="checkResult?.has_update" type="success" :loading="updating" @click="performUpdate" round>
            ⬆️ {{ t('zcard.update.runUpdate') }}
          </ElButton>
          <ElButton v-if="checkResult?.release_url" tag="a" :href="checkResult.release_url" target="_blank" round>
            🔗 GitHub
          </ElButton>
        </div>

        <div class="hero-meta" v-if="checkResult?.published_at">
          {{ t('zcard.update.releasedAt') }}: {{ formatDate(checkResult.published_at) }}
        </div>
      </div>
    </div>

    <!-- ========== Section 2: 最新版本更新日志(仅版本号,点击弹窗看详情) ========== -->
    <ElCard v-if="checkResult" class="art-table-card" shadow="never">
      <template #header>
        <div class="card-header">
          <span class="header-title">📋 {{ t('zcard.update.releaseNotes') }}</span>
        </div>
      </template>
      <div class="changelog-trigger" @click="latestNotesVisible = true">
        <div class="changelog-trigger-left">
          <span class="version-badge large">v{{ checkResult.latest_version }}</span>
          <ElTag v-if="checkResult.has_update" type="warning" size="small" effect="plain">🆕</ElTag>
        </div>
        <div class="changelog-trigger-right">
          <span class="changelog-hint">{{ t('zcard.update.viewDetail') }}</span>
          <ElIcon><ArrowRight /></ElIcon>
        </div>
      </div>
    </ElCard>

    <!-- ========== Section 3: 版本历史(时间线式,仅版本号,点击弹窗) ========== -->
    <ElCard class="art-table-card" shadow="never">
      <template #header>
        <div class="card-header">
          <span class="header-title">📜 {{ t('zcard.update.versionHistory') }}</span>
          <ElButton text type="primary" :loading="versionsLoading" @click="loadVersions">
            🔄 {{ t('zcard.update.refreshHistory') }}
          </ElButton>
        </div>
      </template>

      <div v-loading="versionsLoading" class="timeline">
        <div v-for="(ver, idx) in versions" :key="ver.version" class="timeline-item">
          <div class="timeline-dot" :class="{ 'is-latest': idx === 0 }"></div>

          <div class="timeline-card" :class="{ 'is-latest': idx === 0 }" @click="openVersionDetail(ver)">
            <div class="timeline-card-header">
              <div class="version-badge-row">
                <span class="version-badge" :class="{ 'latest-badge': idx === 0 }">v{{ ver.version }}</span>
                <ElTag v-if="ver.prerelease" type="warning" size="small" effect="plain">Pre-release</ElTag>
                <ElTag v-if="idx === 0" type="success" size="small" effect="dark">Latest</ElTag>
              </div>
              <span class="timeline-date">{{ formatDate(ver.published_at) }}</span>
            </div>

            <div class="timeline-hint-row">
              <span class="timeline-hint-text">{{ t('zcard.update.viewDetail') }}</span>
              <ElIcon class="timeline-hint-icon"><ArrowRight /></ElIcon>
            </div>
          </div>
        </div>

        <ElEmpty v-if="!versionsLoading && versions.length === 0" description="暂无版本历史" />
      </div>
    </ElCard>

    <!-- ========== 版本详情弹窗(通用,最新日志 + 历史版本共用) ========== -->
    <ElDialog
      v-model="latestNotesVisible"
      :title="checkResult ? `v${checkResult.latest_version} ${t('zcard.update.releaseNotes')}` : ''"
      width="720px"
      align-center
      append-to-body
      class="notes-dialog"
    >
      <div v-if="checkResult" class="detail-dialog-body">
        <div class="detail-dialog-meta">
          <span class="version-badge large">v{{ checkResult.latest_version }}</span>
          <ElTag v-if="checkResult.has_update" type="warning" effect="plain">🆕 New</ElTag>
          <span class="detail-date">{{ formatDate(checkResult.published_at) }}</span>
        </div>
        <div v-if="checkResult.release_notes" class="markdown-body detail-notes-full" v-html="renderMd(checkResult.release_notes)"></div>
        <pre v-else class="detail-notes-full">(无更新说明)</pre>
      </div>
    </ElDialog>

    <ElDialog
      v-model="versionDetailVisible"
      :title="versionDetailData ? `v${versionDetailData.version} ${t('zcard.update.releaseNotes')}` : ''"
      width="720px"
      align-center
      append-to-body
      class="notes-dialog"
    >
      <div v-if="versionDetailData" class="detail-dialog-body">
        <div class="detail-dialog-meta">
          <span class="version-badge large">v{{ versionDetailData.version }}</span>
          <ElTag v-if="versionDetailData.prerelease" type="warning" effect="plain">Pre-release</ElTag>
          <span class="detail-date">{{ formatDate(versionDetailData.published_at) }}</span>
          <ElButton v-if="versionDetailData.url" tag="a" :href="versionDetailData.url" target="_blank" size="small" round>
            🔗 GitHub
          </ElButton>
        </div>
        <div v-if="versionDetailData.notes" class="markdown-body detail-notes-full" v-html="renderMd(versionDetailData.notes)"></div>
        <pre v-else class="detail-notes-full">(无更新说明)</pre>
      </div>
    </ElDialog>

    <!-- ========== 更新执行遮罩 ========== -->
    <ElDialog
      v-model="updating"
      :show-close="false"
      :close-on-click-modal="false"
      :close-on-press-escape="false"
      width="440px"
      align-center
      append-to-body
    >
      <div class="updating-body">
        <div class="spinner-wrap">
          <ElIcon class="is-loading" :size="48"><Loading /></ElIcon>
        </div>
        <div class="updating-title">{{ t('zcard.update.updating') }}</div>
        <div class="updating-tip">{{ t('zcard.update.updatingTip') }}</div>
        <ElProgress :percentage="100" :indeterminate="true" :show-text="false" color="var(--el-color-primary)" />
      </div>
    </ElDialog>

    <!-- ========== 成功对话框 ========== -->
    <ElDialog v-model="successVisible" :title="t('zcard.update.updateSuccess')" width="600px" :close-on-click-modal="false" align-center append-to-body>
      <div class="result-body">
        <div class="result-icon-wrap success"><ElIcon :size="48"><CircleCheckFilled /></ElIcon></div>
        <div v-if="successResult" class="version-change">
          <span class="ver old">v{{ successResult.old_version || '-' }}</span>
          <span class="arrow">→</span>
          <span class="ver new">v{{ successResult.new_version || '-' }}</span>
        </div>
        <div v-if="successResult?.message" class="result-message">{{ successResult.message }}</div>
        <details v-if="successResult?.log" class="log-details">
          <summary>📋 {{ t('zcard.update.viewLog') }}</summary>
          <pre class="log-content">{{ successResult.log }}</pre>
        </details>
      </div>
      <template #footer>
        <ElButton type="primary" @click="handleRefresh" round>🔄 {{ t('zcard.update.refresh') }}</ElButton>
      </template>
    </ElDialog>

    <!-- ========== 失败对话框 ========== -->
    <ElDialog v-model="failedVisible" :title="t('zcard.update.updateFailed')" width="600px" align-center append-to-body>
      <div class="result-body">
        <div class="result-icon-wrap failed"><ElIcon :size="48"><CircleCloseFilled /></ElIcon></div>
        <div v-if="failedResult?.message" class="result-message failed-msg">{{ failedResult.message }}</div>
        <ElAlert :title="t('zcard.update.rollbackHint')" type="warning" :closable="false" show-icon />
        <details v-if="failedResult?.log" class="log-details" open>
          <summary>📋 {{ t('zcard.update.viewLog') }}</summary>
          <pre class="log-content">{{ failedResult.log }}</pre>
        </details>
      </div>
      <template #footer>
        <ElButton @click="failedVisible = false" round>{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="warning" @click="handleRollback" :loading="rollingBack" round>⏪ {{ t('zcard.update.rollback') }}</ElButton>
        <ElButton type="primary" @click="handleRetry" round>🔄 {{ t('zcard.update.checkUpdate') }}</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script lang="ts">
import { Loading, CircleCheckFilled, CircleCloseFilled, ArrowRight } from '@element-plus/icons-vue'
export default { components: { Loading, CircleCheckFilled, CircleCloseFilled, ArrowRight } }
</script>

<style lang="scss" scoped>
  .update-page {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }

  /* ========== Hero 状态卡 ========== */
  .status-hero {
    display: flex;
    align-items: flex-start;
    gap: 20px;
    padding: 28px 32px;
    background: linear-gradient(135deg, var(--el-color-primary-light-9), var(--el-fill-color-extra-light));
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 12px;
    position: relative;
    overflow: hidden;
  }
  .hero-bg-icon {
    position: absolute;
    right: 24px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 120px;
    opacity: 0.06;
    pointer-events: none;
  }
  .hero-content {
    flex: 1;
    position: relative;
    z-index: 1;
  }
  .hero-version-row {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 20px;
    flex-wrap: wrap;
  }
  .hero-version-box {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }
  .hero-version-label {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }
  .hero-version-num {
    font-size: 32px;
    font-weight: 800;
    font-family: 'SF Mono', 'Fira Code', monospace;
    line-height: 1.2;
    &.current { color: var(--el-color-primary); }
    &.latest { color: var(--el-color-success); }
  }
  .hero-arrow {
    font-size: 28px;
    color: var(--el-text-color-placeholder);
    font-weight: 700;
    margin-top: 8px;
  }
  .hero-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .hero-meta {
    margin-top: 12px;
    font-size: 12px;
    color: var(--el-text-color-placeholder);
  }

  /* ========== 卡片通用 ========== */
  .card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .header-title {
    font-size: 16px;
    font-weight: 600;
  }

  /* ========== 更新日志触发行 ========== */
  .changelog-trigger {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 4px;
    cursor: pointer;
    border-radius: 8px;
    transition: background 0.2s;
    &:hover { background: var(--el-fill-color-light); }
  }
  .changelog-trigger-left {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .changelog-trigger-right {
    display: flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }
  .changelog-hint { font-size: 13px; }

  /* ========== 版本号 badge ========== */
  .version-badge {
    font-size: 15px;
    font-weight: 700;
    font-family: 'SF Mono', monospace;
    color: var(--el-text-color-regular);
    &.large { font-size: 18px; }
    &.latest-badge { color: var(--el-color-success); }
  }

  /* ========== 时间线(简化:仅版本号+点击) ========== */
  .timeline {
    position: relative;
    padding-left: 8px;
    /* 版本历史可能很长,允许内部滚动(art-table-card 的 body 是 overflow:hidden) */
    max-height: calc(100vh - 420px);
    overflow-y: auto;
    padding-right: 8px;
  }
  .timeline-item {
    position: relative;
    padding-left: 28px;
    padding-bottom: 16px;
    &:last-child { padding-bottom: 0; }
    &::before {
      content: '';
      position: absolute;
      left: 5px;
      top: 20px;
      bottom: 0;
      width: 2px;
      background: var(--el-border-color-lighter);
    }
    &:last-child::before { display: none; }
  }
  .timeline-dot {
    position: absolute;
    left: 0;
    top: 6px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--el-color-info-light-5);
    border: 2px solid var(--el-bg-color);
    z-index: 1;
    &.is-latest {
      background: var(--el-color-success);
      box-shadow: 0 0 0 4px var(--el-color-success-light-9);
    }
  }
  .timeline-card {
    background: var(--el-fill-color-blank);
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 10px;
    padding: 12px 16px;
    cursor: pointer;
    transition: border-color 0.2s, box-shadow 0.2s;
    &:hover {
      border-color: var(--el-color-primary-light-5);
      box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
    }
    &.is-latest {
      border-color: var(--el-color-success-light-5);
      background: var(--el-color-success-light-9);
    }
  }
  .timeline-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
  }
  .version-badge-row {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
  }
  .timeline-date {
    font-size: 12px;
    color: var(--el-text-color-placeholder);
  }
  .timeline-hint-row {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 4px;
    margin-top: 6px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  /* ========== 详情弹窗 ========== */
  .detail-dialog-body {
    display: flex;
    flex-direction: column;
    gap: 16px;
  }
  .detail-dialog-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .detail-date {
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }
  .detail-notes-full {
    background: var(--el-fill-color-light);
    border-radius: 8px;
    padding: 16px;
    font-family: 'SF Mono', monospace;
    font-size: 13px;
    line-height: 1.7;
    color: var(--el-text-color-regular);
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 520px;
    overflow: auto;
    margin: 0;
    border: 1px solid var(--el-border-color-lighter);
  }

  /* ========== 更新执行遮罩 ========== */
  .updating-body {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    padding: 20px 8px 8px;
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

  /* ========== 结果对话框 ========== */
  .result-body {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
    padding: 8px 0;
  }
  .result-icon-wrap {
    &.success { color: var(--el-color-success); }
    &.failed { color: var(--el-color-danger); }
  }
  .version-change {
    display: flex;
    align-items: center;
    gap: 16px;
    font-size: 24px;
    font-weight: 700;
    font-family: 'SF Mono', monospace;
    .ver.old { color: var(--el-text-color-secondary); }
    .ver.new { color: var(--el-color-success); }
    .arrow { color: var(--el-text-color-placeholder); }
  }
  .result-message {
    font-size: 14px;
    text-align: center;
    color: var(--el-text-color-regular);
    &.failed-msg { color: var(--el-color-danger); font-weight: 600; }
  }
  .log-details {
    width: 100%;
    background: var(--el-fill-color-light);
    border-radius: 8px;
    padding: 10px 14px;
    summary {
      cursor: pointer;
      font-size: 13px;
      color: var(--el-text-color-secondary);
      user-select: none;
    }
  }
  .log-content {
    margin: 8px 0 0;
    font-family: 'SF Mono', monospace;
    font-size: 12px;
    line-height: 1.6;
    color: var(--el-text-color-regular);
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 280px;
    overflow: auto;
  }
</style>
