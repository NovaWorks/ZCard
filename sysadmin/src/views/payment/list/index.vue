<!-- 支付渠道 - 后台管理（卡片式） -->
<template>
  <div class="payment-page art-full-height">
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
          <ElTag :type="channel.enabled ? 'success' : 'info'" effect="light">
            {{ channel.enabled ? t('zcard.payment.enabled') : t('zcard.payment.disabled') }}
          </ElTag>
        </div>

        <div class="channel-actions">
          <ElButton type="primary" plain @click="openConfig(channel)">{{ t('zcard.payment.config') }}</ElButton>
        </div>
      </ElCard>

      <div v-if="!loading && channels.length === 0" class="empty-state">{{ t('zcard.payment.empty') }}</div>
    </div>

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
        label-width="140px"
        class="config-form"
      >
        <ElFormItem :label="t('zcard.payment.enabledLabel')">
          <ElSwitch v-model="configForm.enabled" :active-text="t('zcard.payment.enable')" :inactive-text="t('zcard.payment.disable')" />
        </ElFormItem>

        <ElFormItem
          v-for="field in configFields"
          :key="field.key"
          :label="field.label"
          :required="field.required"
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
          <ElInput
            v-else-if="field.type === 'textarea'"
            v-model="configForm.values[field.key]"
            type="textarea"
            :rows="3"
            :placeholder="field.placeholder"
          />
          <ElInput
            v-else
            v-model="configForm.values[field.key]"
            :type="field.type === 'password' ? 'password' : 'text'"
            show-password
            :placeholder="field.placeholder"
          />
          <div v-if="field.help" class="field-help">{{ field.help }}</div>
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
  import { Wallet } from '@element-plus/icons-vue'
  import { ElMessage } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import {
    getChannels,
    updateChannel,
    getConfigFields,
    type PaymentChannel,
    type ConfigField
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

  /** 配置弹窗 */
  const configVisible = ref(false)
  const fieldsLoading = ref(false)
  const saving = ref(false)
  const currentChannel = ref<PaymentChannel | null>(null)
  const configFields = ref<ConfigField[]>([])

  const configForm = reactive<{ enabled: boolean; values: Record<string, any> }>({
    enabled: false,
    values: {}
  })

  /** 打开配置弹窗：拉取动态字段 + 当前配置 */
  const openConfig = async (channel: PaymentChannel) => {
    currentChannel.value = channel
    configForm.enabled = !!channel.enabled
    configForm.values = { ...(channel.config || {}) }
    configFields.value = []
    configVisible.value = true

    fieldsLoading.value = true
    try {
      const fields = await getConfigFields(channel.id)
      configFields.value = Array.isArray(fields) ? fields : []
      // 用字段默认值补齐缺失项
      configFields.value.forEach((f) => {
        if (configForm.values[f.key] === undefined && f.default !== undefined) {
          configForm.values[f.key] = f.default
        }
      })
    } catch (e) {
      configFields.value = []
    } finally {
      fieldsLoading.value = false
    }
  }

  /** 保存配置 */
  const handleSave = async () => {
    if (!currentChannel.value) return
    saving.value = true
    try {
      await updateChannel(currentChannel.value.id, {
        enabled: configForm.enabled,
        config: { ...configForm.values }
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

  onMounted(() => {
    fetchChannels()
  })
</script>

<style lang="scss" scoped>
  .payment-page {
    display: flex;
    flex-direction: column;
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
</style>
