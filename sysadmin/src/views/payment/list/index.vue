<!-- 支付渠道 - 后台管理（卡片式 + 添加渠道弹窗） -->
<template>
  <div class="payment-page art-full-height">
    <!-- 顶部操作栏 -->
    <div class="page-toolbar">
      <ElButton type="primary" :icon="Plus" @click="openAddDialog">
        {{ t('zcard.payment.addChannel') }}
      </ElButton>
    </div>

    <!-- 已添加的渠道卡片 -->
    <div v-loading="loading" class="channel-grid">
      <ElCard
        v-for="channel in channels"
        :key="channel.id"
        class="channel-card"
        shadow="hover"
      >
        <div class="channel-head">
          <div class="channel-icon">
            <img v-if="channel.icon" :src="channel.icon" :alt="channel.name" />
            <ElIcon v-else :size="32"><Wallet /></ElIcon>
          </div>
          <div class="channel-meta">
            <div class="channel-name">{{ channel.name }}</div>
            <div class="channel-code">{{ channel.code }}</div>
          </div>
          <ElTag :type="isConfigured(channel) ? 'success' : 'warning'" effect="light">
            {{ isConfigured(channel) ? t('zcard.payment.configured') : t('zcard.payment.notConfigured') }}
          </ElTag>
        </div>

        <div class="channel-actions">
          <ElButton type="primary" plain @click="openConfig(channel)">{{ t('zcard.payment.config') }}</ElButton>
          <ElButton type="danger" text :icon="Delete" @click="handleDelete(channel)" />
        </div>
      </ElCard>

      <div v-if="!loading && channels.length === 0" class="empty-state">{{ t('zcard.payment.empty') }}</div>
    </div>

    <!-- 添加支付渠道弹窗(勾选式) -->
    <ElDialog
      v-model="addVisible"
      :title="t('zcard.payment.addChannelTitle')"
      width="520px"
      destroy-on-close
    >
      <div class="add-tip">{{ t('zcard.payment.addChannelTip') }}</div>
      <div v-loading="driversLoading" class="driver-list">
        <ElCheckbox
          v-for="d in availableDrivers"
          :key="d.code"
          v-model="addChecked[d.code]"
          class="driver-item"
        >
          <span class="driver-icon">{{ d.icon }}</span>
          <span class="driver-name">{{ d.name }}</span>
        </ElCheckbox>
      </div>
      <template #footer>
        <ElButton @click="addVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="adding" @click="handleAddSave">{{ t('zcard.common.confirm') }}</ElButton>
      </template>
    </ElDialog>

    <!-- 配置弹窗 -->
    <ElDialog
      v-model="configVisible"
      :title="currentChannel ? t('zcard.payment.configTitle', { name: currentChannel.name }) : t('zcard.payment.config')"
      width="560px"
      destroy-on-close
    >
      <ElForm
        v-loading="fieldsLoading"
        :model="configForm"
        label-width="auto"
        class="config-form"
      >
        <ElFormItem :label="t('zcard.payment.enabledLabel')">
          <ElSwitch v-model="configForm.enabled" :active-text="t('zcard.payment.enable')" :inactive-text="t('zcard.payment.disable')" />
        </ElFormItem>

        <!-- 回调地址提示 -->
        <el-alert
          v-if="callbackUrl"
          type="info"
          :closable="false"
          show-icon
          class="callback-alert"
        >
          <template #title>{{ t('zcard.payment.callbackTitle') }}</template>
          <div class="callback-url-row">
            <code>{{ callbackUrl }}</code>
            <el-button text size="small" @click="copyText(callbackUrl)">{{ t('zcard.payment.copy') }}</el-button>
          </div>
          <div class="callback-tip">{{ t('zcard.payment.callbackTip') }}</div>
        </el-alert>

        <ElFormItem
          v-for="field in configFields"
          :key="field.key"
          :label="field.label"
          :required="field.required && !(isSensitive(field.key) && currentChannel?.config?.[field.key])"
        >
          <ElSwitch
            v-if="field.type === 'switch'"
            v-model="configForm.values[field.key]"
          />
          <ElInputNumber
            v-else-if="field.type === 'number'"
            v-model="configForm.values[field.key]"
            controls-position="right"
            style="width: 100%"
          />
          <ElSelect
            v-else-if="field.type === 'select'"
            v-model="configForm.values[field.key]"
            :placeholder="t('zcard.payment.selectPlaceholder')"
            style="width: 100%"
          >
            <ElOption
              v-for="opt in field.options || []"
              :key="String(opt.value)"
              :label="opt.label"
              :value="opt.value"
            />
          </ElSelect>
          <ElSelect
            v-else-if="field.type === 'multiselect'"
            v-model="configForm.values[field.key]"
            multiple
            collapse-tags
            collapse-tags-tooltip
            :placeholder="t('zcard.payment.selectPlaceholder')"
            style="width: 100%"
          >
            <ElOption
              v-for="opt in field.options || []"
              :key="String(opt.value)"
              :label="opt.label"
              :value="opt.value"
            />
          </ElSelect>
          <ElInput
            v-else-if="field.type === 'textarea'"
            v-model="configForm.values[field.key]"
            type="textarea"
            :rows="3"
            :placeholder="isSensitive(field.key) ? t('zcard.payment.sensitivePlaceholder') : field.placeholder"
          />
          <ElInput
            v-else
            v-model="configForm.values[field.key]"
            :type="isSensitive(field.key) ? 'password' : 'text'"
            show-password
            :placeholder="isSensitive(field.key) ? t('zcard.payment.sensitivePlaceholder') : field.placeholder"
          />
          <div v-if="field.help" class="field-help">{{ field.help }}</div>
          <div v-else-if="isSensitive(field.key)" class="field-help">{{ t('zcard.payment.sensitiveTip') }}</div>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="configVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="saving" @click="handleSave">{{ t('zcard.payment.save') }}</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { Wallet, Plus, Delete } from '@element-plus/icons-vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import {
    getChannels,
    updateChannel,
    getConfigFields,
    getDrivers,
    createChannel,
    deleteChannel,
    type PaymentChannel,
    type ConfigField,
    type AvailableDriver
  } from '@/api/payment'

  defineOptions({ name: 'PaymentList' })

  const { t } = useI18n()

  /** 渠道列表 */
  const loading = ref(false)
  const channels = ref<PaymentChannel[]>([])

  const fetchChannels = async () => {
    loading.value = true
    try {
      const res = await getChannels()
      channels.value = Array.isArray(res) ? res : []
    } catch (e) {
      channels.value = []
    } finally {
      loading.value = false
    }
  }

  /** 判断渠道是否已配置(有 config 且非空) */
  const isConfigured = (channel: PaymentChannel): boolean => {
    const cfg = channel.config
    if (!cfg || typeof cfg !== 'object') return false
    // 至少有一个非空值才算已配置
    return Object.values(cfg).some((v) => v !== '' && v !== null && v !== undefined)
  }

  /** ===== 添加支付渠道弹窗(勾选式) ===== */
  const addVisible = ref(false)
  const driversLoading = ref(false)
  const adding = ref(false)
  const availableDrivers = ref<AvailableDriver[]>([])
  /** code → 是否勾选(基于 added 状态初始化) */
  const addChecked = reactive<Record<string, boolean>>({})

  /** 打开添加弹窗:拉取全部驱动 + 标记已添加的为勾选 */
  const openAddDialog = async () => {
    addVisible.value = true
    driversLoading.value = true
    try {
      const res = await getDrivers()
      availableDrivers.value = Array.isArray(res) ? res : []
      // 已添加的预勾选
      Object.keys(addChecked).forEach((k) => delete addChecked[k])
      availableDrivers.value.forEach((d) => {
        addChecked[d.code] = !!d.added
      })
    } catch {
      availableDrivers.value = []
    } finally {
      driversLoading.value = false
    }
  }

  /** 保存勾选变更:新增被勾选的、删除取消勾选的 */
  const handleAddSave = async () => {
    adding.value = true
    try {
      // 当前已存在渠道的 code 集合
      const existingCodes = new Set(channels.value.map((c) => c.code))
      // 需新增的:勾选了但数据库还没有的
      const toAdd = availableDrivers.value.filter(
        (d) => addChecked[d.code] && !existingCodes.has(d.code)
      )
      // 需删除的:数据库有但取消勾选的
      const toRemove = channels.value.filter((c) => !addChecked[c.code])

      await Promise.all([
        ...toAdd.map((d) => createChannel(d.code)),
        ...toRemove.map((c) => deleteChannel(c.id)),
      ])

      const changed = toAdd.length + toRemove.length
      if (changed > 0) {
        ElMessage.success(t('zcard.payment.addSuccess'))
      } else {
        ElMessage.info(t('zcard.payment.noChange'))
      }
      addVisible.value = false
      await fetchChannels()
    } catch {
      // 拦截器处理错误
    } finally {
      adding.value = false
    }
  }

  /** 删除渠道(卡片上的删除按钮) */
  const handleDelete = async (channel: PaymentChannel) => {
    try {
      await ElMessageBox.confirm(
        t('zcard.payment.deleteConfirm'),
        t('zcard.payment.deleteChannel'),
        { type: 'warning', confirmButtonText: t('zcard.common.confirm'), cancelButtonText: t('zcard.common.cancel') }
      )
    } catch {
      return // 用户取消
    }
    try {
      await deleteChannel(channel.id)
      ElMessage.success(t('zcard.payment.deleteSuccess'))
      await fetchChannels()
    } catch {
      // 拦截器处理
    }
  }

  /** 配置弹窗 */
  const configVisible = ref(false)
  const fieldsLoading = ref(false)
  const saving = ref(false)
  const currentChannel = ref<PaymentChannel | null>(null)
  const configFields = ref<ConfigField[]>([])
  /** 该通道的异步回调地址 */
  const callbackUrl = ref('')

  const configForm = reactive<{ enabled: boolean; values: Record<string, any> }>({
    enabled: false,
    values: {}
  })

  /** 敏感字段名识别(key/secret/token/password/private 等不回显) */
  const isSensitive = (key: string) =>
    /(key|secret|token|password|passwd|private|credential|cert)/i.test(key)

  /** 打开配置弹窗：拉取动态字段 + 当前配置 */
  const openConfig = async (channel: PaymentChannel) => {
    currentChannel.value = channel
    configForm.enabled = !!channel.enabled
    configFields.value = []
    callbackUrl.value = ''
    configVisible.value = true

    fieldsLoading.value = true
    try {
      const result = await getConfigFields(channel.id)
      const fields = result?.fields ?? []
      configFields.value = Array.isArray(fields) ? fields : []
      callbackUrl.value = result?.callback_url ?? ''

      // 回填当前配置;敏感字段不回显(置空,留空=保留旧值)
      const saved = channel.config || {}
      configForm.values = {}
      configFields.value.forEach((f) => {
        if (isSensitive(f.key)) {
          configForm.values[f.key] = '' // 不回显,留空表示保留
        } else if (f.type === 'multiselect') {
          // 多选字段值必须是数组:优先已保存值,其次默认值,最后空数组
          const val = saved[f.key] ?? f.default ?? []
          configForm.values[f.key] = Array.isArray(val) ? [...val] : (val ? [val] : [])
        } else if (saved[f.key] !== undefined) {
          configForm.values[f.key] = saved[f.key]
        } else if (f.default !== undefined) {
          configForm.values[f.key] = f.default
        } else {
          configForm.values[f.key] = ''
        }
      })
    } catch (e) {
      configFields.value = []
    } finally {
      fieldsLoading.value = false
    }
  }

  /** 复制文本 */
  const copyText = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text)
      ElMessage.success(t('zcard.payment.copied'))
    } catch {
      ElMessage.warning(text)
    }
  }

  /** 保存配置 */
  const handleSave = async () => {
    if (!currentChannel.value) return
    // 敏感字段为空时,不回传(保留后端已存值);非空才更新
    const values: Record<string, any> = {}
    configFields.value.forEach((f) => {
      const val = configForm.values[f.key]
      if (isSensitive(f.key) && (val === '' || val === null || val === undefined)) {
        return // 留空 = 保留旧值,不传
      }
      values[f.key] = val
    })
    saving.value = true
    try {
      await updateChannel(currentChannel.value.id, {
        enabled: configForm.enabled,
        config: values
      })
      ElMessage.success(t('zcard.payment.saved'))
      configVisible.value = false
      fetchChannels()
    } catch (e) {
      // 拦截器处理
    } finally {
      saving.value = false
    }
  }

  onActivated(() => {
    fetchChannels()
  })
</script>

<style lang="scss" scoped>
  .payment-page {
    display: flex;
    flex-direction: column;
  }

  .page-toolbar {
    margin-bottom: 16px;
  }

  .add-tip {
    margin-bottom: 12px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .driver-list {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
  }

  .driver-item {
    display: flex;
    align-items: center;
    height: 40px;
    margin-right: 0;
    padding: 0 12px;
    border: 1px solid var(--el-border-color);
    border-radius: 6px;

    .driver-icon {
      margin-right: 8px;
      font-size: 18px;
    }

    .driver-name {
      font-size: 13px;
    }
  }

  .channel-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 16px;
  }

  .channel-card {
    display: flex;
    flex-direction: column;
  }

  .channel-head {
    display: flex;
    align-items: center;
    gap: 12px;
  }

  .channel-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    overflow: hidden;
    background: var(--el-fill-color-light);
    border-radius: 8px;

    img {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }
  }

  .channel-meta {
    flex: 1;
    min-width: 0;
  }

  .channel-name {
    font-size: 16px;
    font-weight: 600;
    line-height: 1.4;
  }

  .channel-code {
    margin-top: 2px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .channel-actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }

  .empty-state {
    grid-column: 1 / -1;
    padding: 48px 0;
    font-size: 14px;
    color: var(--el-text-color-secondary);
    text-align: center;
  }

  .field-help {
    margin-top: 4px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .callback-alert {
    margin-bottom: 18px;
  }

  .callback-url-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 4px;

    code {
      flex: 1;
      padding: 4px 8px;
      font-size: 12px;
      word-break: break-all;
      background: var(--el-fill-color-light);
      border-radius: 4px;
    }
  }

  .callback-tip {
    margin-top: 4px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }
</style>
