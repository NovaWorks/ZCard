<script setup lang="ts">
import { ref, onActivated } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import { getUserGroups, createUserGroup, updateUserGroup, deleteUserGroup } from '@/api/userGroup'
import type { UserGroup } from '@/api/userGroup'
import { getSettings, updateSettings } from '@/api/settings'

const { t } = useI18n()
const loading = ref(false)
const list = ref<UserGroup[]>([])
const dialogVisible = ref(false)
const isEdit = ref(false)
const submitting = ref(false)

const formData = ref({
  id: 0,
  name: '',
  discount: 100,
  min_recharge: 0,
  min_consumption: 0,
  sort: 0,
  status: true
})

/** 升级依据:recharge=累计充值,consumption=累计消费 */
const upgradeBasis = ref('recharge')
const basisLoading = ref(false)

const loadBasis = async () => {
  try {
    const settings = await getSettings()
    upgradeBasis.value = (settings.member_upgrade_basis as string) || 'recharge'
  } catch {
    /* 取不到则用默认值 */
  }
}

const changeBasis = async (val: string | number | boolean | undefined) => {
  basisLoading.value = true
  try {
    await updateSettings({ member_upgrade_basis: val as string })
    ElMessage.success(t('zcard.common.saveSuccess'))
  } catch {
    ElMessage.error(t('zcard.common.operationFailed'))
  } finally {
    basisLoading.value = false
  }
}

const discountHint = ref('')

const updateDiscountHint = () => {
  const d = formData.value.discount
  if (d >= 100) discountHint.value = t('zcard.member.noDiscount')
  else discountHint.value = t('zcard.member.discountExample', { d: (d / 10).toFixed(1) })
}

const loadData = async () => {
  loading.value = true
  try {
    list.value = await getUserGroups()
  } finally {
    loading.value = false
  }
}

const handleAdd = () => {
  isEdit.value = false
  formData.value = { id: 0, name: '', discount: 100, min_recharge: 0, min_consumption: 0, sort: list.value.length + 1, status: true }
  updateDiscountHint()
  dialogVisible.value = true
}

const handleEdit = (row: UserGroup) => {
  isEdit.value = true
  formData.value = {
    id: row.id || 0,
    name: row.name,
    discount: Number(row.discount),
    // 后端返回分,表单显示元(/100)
    min_recharge: Number(row.min_recharge) / 100,
    min_consumption: Number(row.min_consumption ?? 0) / 100,
    sort: row.sort,
    status: row.status
  }
  updateDiscountHint()
  dialogVisible.value = true
}

const handleDelete = (row: UserGroup) => {
  ElMessageBox.confirm(
    t('zcard.member.deleteConfirm', { name: row.name }),
    t('zcard.common.tips'),
    { type: 'warning' }
  ).then(async () => {
    await deleteUserGroup(row.id!)
    ElMessage.success(t('zcard.common.deleteSuccess'))
    loadData()
  }).catch(() => {})
}

const handleSubmit = async () => {
  if (!formData.value.name.trim()) {
    ElMessage.warning(t('zcard.member.nameRequired'))
    return
  }
  submitting.value = true
  try {
    if (isEdit.value) {
      await updateUserGroup(formData.value.id, formData.value)
      ElMessage.success(t('zcard.common.saveSuccess'))
    } else {
      await createUserGroup(formData.value)
      ElMessage.success(t('zcard.common.operationSuccess'))
    }
    dialogVisible.value = false
    loadData()
  } catch {
    ElMessage.error(t('zcard.common.operationFailed'))
  } finally {
    submitting.value = false
  }
}

onActivated(() => {
  loadData()
  loadBasis()
})
</script>

<template>
  <div class="app-container">
    <!-- 说明卡片 -->
    <ElAlert
      :title="t('zcard.member.introTitle')"
      :description="t('zcard.member.introDesc')"
      type="info"
      :closable="false"
      show-icon
      class="mb-4"
    />

    <!-- 升级依据全局设置 -->
    <ElCard shadow="never" class="mb-4">
      <div class="flex items-center">
        <span class="text-sm font-medium text-gray-700 mr-3">{{ t('zcard.member.upgradeBasis') }}</span>
        <ElRadioGroup v-model="upgradeBasis" :loading="basisLoading" @change="changeBasis">
          <ElRadio value="recharge">{{ t('zcard.member.upgradeBasisRecharge') }}</ElRadio>
          <ElRadio value="consumption">{{ t('zcard.member.upgradeBasisConsumption') }}</ElRadio>
        </ElRadioGroup>
      </div>
    </ElCard>

    <!-- 操作栏 -->
    <div class="mb-4 flex items-center justify-between">
      <h3 class="text-base font-bold text-gray-800">
        <ElIcon class="mr-1"><User /></ElIcon>
        {{ t('zcard.member.title') }}
      </h3>
      <ElButton type="primary" @click="handleAdd">
        <ElIcon class="mr-1"><Plus /></ElIcon>
        {{ t('zcard.member.add') }}
      </ElButton>
    </div>

    <!-- 等级列表(表头不换行,列宽自适应) -->
    <ElTable v-loading="loading" :data="list" border row-key="id" :header-cell-style="{ whiteSpace: 'nowrap' }">
      <ElTableColumn prop="id" :label="t('zcard.common.id')" min-width="60" align="center" />
      <ElTableColumn prop="name" :label="t('zcard.member.name')" min-width="120" show-overflow-tooltip>
        <template #default="{ row }">
          <ElTag :type="row.id === 1 ? 'info' : 'warning'" size="small" effect="light">
            {{ row.name }}
          </ElTag>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="t('zcard.member.discount')" min-width="120" align="center">
        <template #default="{ row }">
          <span :class="row.discount < 100 ? 'text-green-600 font-bold' : 'text-gray-500'">
            {{ row.discount }}%
          </span>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="t('zcard.member.minRecharge')" min-width="140" align="center" show-overflow-tooltip>
        <template #default="{ row }">
          ¥{{ (Number(row.min_recharge) / 100).toFixed(2) }}
        </template>
      </ElTableColumn>
      <ElTableColumn :label="t('zcard.member.minConsumption')" min-width="140" align="center" show-overflow-tooltip>
        <template #default="{ row }">
          ¥{{ (Number(row.min_consumption ?? 0) / 100).toFixed(2) }}
        </template>
      </ElTableColumn>
      <ElTableColumn prop="sort" :label="t('zcard.member.sort')" min-width="80" align="center" />
      <ElTableColumn :label="t('zcard.member.status')" min-width="100" align="center">
        <template #default="{ row }">
          <ElTag :type="row.status ? 'success' : 'info'" size="small">
            {{ row.status ? t('zcard.member.enabled') : t('zcard.member.disabled') }}
          </ElTag>
        </template>
      </ElTableColumn>
      <ElTableColumn :label="t('zcard.common.actions')" width="150" align="center" fixed="right">
        <template #default="{ row }">
          <ElButton type="primary" link size="small" @click="handleEdit(row)">
            {{ t('zcard.common.edit') }}
          </ElButton>
          <ElButton v-if="row.id !== 1" type="danger" link size="small" @click="handleDelete(row)">
            {{ t('zcard.common.delete') }}
          </ElButton>
        </template>
      </ElTableColumn>
    </ElTable>

    <!-- 新增/编辑对话框 -->
    <ElDialog
      v-model="dialogVisible"
      :title="isEdit ? t('zcard.member.edit') : t('zcard.member.add')"
      width="520px"
    >
      <ElForm :model="formData" label-width="130px">
        <ElFormItem :label="t('zcard.member.name')" required>
          <ElInput v-model="formData.name" :placeholder="t('zcard.member.namePlaceholder')" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.member.discount')">
          <ElInputNumber v-model="formData.discount" :min="1" :max="100" :step="5" style="width: 160px" @change="updateDiscountHint" />
          <span class="ml-2 text-xs text-gray-400">%</span>
          <div class="text-xs text-blue-500 mt-1">{{ discountHint }}</div>
        </ElFormItem>
        <ElFormItem :label="t('zcard.member.minRecharge')">
          <ElInputNumber v-model="formData.min_recharge" :min="0" :step="10" :precision="2" style="width: 160px" />
          <span class="ml-2 text-xs text-gray-400">¥</span>
          <div class="text-xs text-gray-400 mt-1">{{ t('zcard.member.minRechargeHint') }}</div>
        </ElFormItem>
        <ElFormItem :label="t('zcard.member.minConsumption')">
          <ElInputNumber v-model="formData.min_consumption" :min="0" :step="10" :precision="2" style="width: 160px" />
          <span class="ml-2 text-xs text-gray-400">¥</span>
          <div class="text-xs text-gray-400 mt-1">{{ t('zcard.member.minConsumptionHint') }}</div>
        </ElFormItem>
        <ElFormItem :label="t('zcard.member.sort')">
          <ElInputNumber v-model="formData.sort" :min="0" style="width: 160px" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.member.status')">
          <ElSwitch v-model="formData.status" />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="dialogVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="submitting" @click="handleSubmit">
          {{ t('zcard.common.confirm') }}
        </ElButton>
      </template>
    </ElDialog>
  </div>
</template>
