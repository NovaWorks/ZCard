<!-- 供货账号管理 - 对外供货:为下游生成对接 key、充值预存、查账本 -->
<template>
  <div class="supplier-account-page art-full-height">
    <ElCard ref="cardRef" class="art-table-card" shadow="never">
      <div class="toolbar">
        <div class="toolbar-left">
          <ElSelect v-model="filterStatus" :placeholder="t('zcard.supplierAccount.filterStatus')" clearable style="width: 160px" @change="fetchData">
            <ElOption :label="t('zcard.supplierAccount.statusActive')" value="active" />
            <ElOption :label="t('zcard.supplierAccount.statusDisabled')" value="disabled" />
          </ElSelect>
          <ElSelect v-model="filterApproved" :placeholder="t('zcard.supplierAccount.filterApproved')" clearable style="width: 160px" @change="fetchData">
            <ElOption :label="t('zcard.supplierAccount.approvedNo')" :value="0" />
            <ElOption :label="t('zcard.supplierAccount.approvedYes')" :value="1" />
          </ElSelect>
          <ElButton @click="fetchData">{{ t('zcard.common.reset') }}</ElButton>
        </div>
        <div class="toolbar-right">
          <ElButton type="primary" :icon="Plus" @click="openAdd">{{ t('zcard.supplierAccount.add') }}</ElButton>
        </div>
      </div>

      <ElTable ref="tableRef" v-loading="loading" :data="tableData" :height="tableHeight" row-key="id" border stripe>
        <ElTableColumn :label="t('zcard.common.id')" prop="id" width="60" />
        <ElTableColumn :label="t('zcard.supplierAccount.name')" prop="name" min-width="120" show-overflow-tooltip />
        <ElTableColumn :label="t('zcard.supplierAccount.apiKey')" prop="api_key" min-width="200" show-overflow-tooltip />
        <ElTableColumn :label="t('zcard.supplierAccount.balance')" width="130">
          <template #default="{ row }">
            <span :class="{ 'balance-low': row.balance < lowThreshold }">{{ formatFen(row.balance) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.supplierAccount.status')" width="100">
          <template #default="{ row }">
            <ElTag :type="row.status === 'active' ? 'success' : 'info'">
              {{ row.status === 'active' ? t('zcard.supplierAccount.statusActive') : t('zcard.supplierAccount.statusDisabled') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.supplierAccount.approved')" width="110">
          <template #default="{ row }">
            <ElTag :type="row.approved ? 'success' : 'warning'">
              {{ row.approved ? t('zcard.supplierAccount.approvedYes') : t('zcard.supplierAccount.approvedNo') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.supplierAccount.contact')" prop="contact" min-width="120" show-overflow-tooltip>
          <template #default="{ row }"><span v-if="row.contact">{{ row.contact }}</span><span v-else class="text-muted">—</span></template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.common.actions')" width="400" fixed="right">
          <template #default="{ row }">
            <ElButton v-if="!row.approved" text type="success" @click="handleApprove(row, true)">
              {{ t('zcard.supplierAccount.approve') }}
            </ElButton>
            <ElButton v-else text type="info" @click="handleApprove(row, false)">
              {{ t('zcard.supplierAccount.revokeApprove') }}
            </ElButton>
            <ElButton text type="primary" @click="openRecharge(row)">{{ t('zcard.supplierAccount.recharge') }}</ElButton>
            <ElButton text type="primary" @click="openLedger(row)">{{ t('zcard.supplierAccount.ledger') }}</ElButton>
            <ElButton text type="warning" @click="handleResetSecret(row)">{{ t('zcard.supplierAccount.resetSecret') }}</ElButton>
            <ElButton text type="primary" @click="openEdit(row)">{{ t('zcard.common.edit') }}</ElButton>
            <ElButton text type="danger" @click="handleDelete(row)">{{ t('zcard.common.delete') }}</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <div ref="paginationRef" class="pagination-wrap">
        <ElPagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.pageSize"
          :total="pagination.total"
          :page-sizes="[10, 15, 20, 50]"
          layout="total, sizes, prev, pager, next, jumper"
          background
          @size-change="fetchData"
          @current-change="fetchData"
        />
      </div>
    </ElCard>

    <!-- 新建/编辑账号弹窗 -->
    <ElDialog v-model="dialogVisible" :title="isEdit ? t('zcard.supplierAccount.editTitle') : t('zcard.supplierAccount.addTitle')" width="500px" destroy-on-close>
      <ElForm ref="formRef" :model="formData" :rules="formRules" label-width="100px">
        <ElFormItem :label="t('zcard.supplierAccount.name')" prop="name">
          <ElInput v-model="formData.name" :placeholder="t('zcard.supplierAccount.namePlaceholder')" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.supplierAccount.contact')" prop="contact">
          <ElInput v-model="formData.contact" :placeholder="t('zcard.supplierAccount.contactPlaceholder')" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.supplierAccount.status')" v-if="isEdit">
          <ElSelect v-model="formData.status" style="width: 100%">
            <ElOption :label="t('zcard.supplierAccount.statusActive')" value="active" />
            <ElOption :label="t('zcard.supplierAccount.statusDisabled')" value="disabled" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="t('zcard.supplierAccount.approved')" v-if="isEdit">
          <ElSwitch v-model="formData.approved" />
          <span class="form-tip">{{ t('zcard.supplierAccount.approvedTip') }}</span>
        </ElFormItem>
        <ElFormItem :label="t('zcard.supplierAccount.remark')" prop="remark">
          <ElInput v-model="formData.remark" type="textarea" :rows="2" :placeholder="t('zcard.supplierAccount.remarkPlaceholder')" />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="dialogVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="saving" @click="handleSubmit">{{ t('zcard.common.ok') }}</ElButton>
      </template>
    </ElDialog>

    <!-- 密钥一次性展示弹窗(新建/重置后) -->
    <ElDialog v-model="secretVisible" :title="t('zcard.supplierAccount.secretTitle')" width="560px" :close-on-click-modal="false" :show-close="false">
      <ElAlert :title="secretWarning" type="warning" :closable="false" show-icon style="margin-bottom: 16px" />
      <ElForm label-width="100px">
        <ElFormItem label="API Key">
          <div class="secret-row">
            <ElInput :model-value="secretData.api_key" readonly />
            <ElButton text type="primary" @click="copy(secretData.api_key)">{{ t('zcard.supplierAccount.copy') }}</ElButton>
          </div>
        </ElFormItem>
        <ElFormItem label="API Secret">
          <div class="secret-row">
            <ElInput :model-value="secretData.api_secret" readonly type="password" show-password />
            <ElButton text type="primary" @click="copy(secretData.api_secret)">{{ t('zcard.supplierAccount.copy') }}</ElButton>
          </div>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton type="primary" @click="closeSecret">{{ t('zcard.supplierAccount.iHaveSaved') }}</ElButton>
      </template>
    </ElDialog>

    <!-- 充值弹窗 -->
    <ElDialog v-model="rechargeVisible" :title="t('zcard.supplierAccount.rechargeTitle', { name: rechargeTarget?.name })" width="440px" destroy-on-close>
      <ElForm label-width="100px">
        <ElFormItem :label="t('zcard.supplierAccount.currentBalance')">
          <span class="balance-text">{{ rechargeTarget ? formatFen(rechargeTarget.balance) : '' }}</span>
        </ElFormItem>
        <ElFormItem :label="t('zcard.supplierAccount.rechargeAmount')">
          <ElInputNumber v-model="rechargeAmount" :min="1" :precision="2" :step="100" controls-position="right" style="width: 200px" />
          <span class="unit">{{ t('zcard.supplierAccount.yuan') }}</span>
        </ElFormItem>
        <ElFormItem :label="t('zcard.supplierAccount.remark')">
          <ElInput v-model="rechargeRemark" :placeholder="t('zcard.supplierAccount.rechargeRemarkPlaceholder')" />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="rechargeVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="recharging" @click="handleRecharge">{{ t('zcard.common.ok') }}</ElButton>
      </template>
    </ElDialog>

    <!-- 账本流水弹窗 -->
    <ElDialog v-model="ledgerVisible" :title="t('zcard.supplierAccount.ledgerTitle', { name: ledgerTarget?.name })" width="760px" destroy-on-close>
      <ElTable :data="ledgerData" border stripe max-height="440" v-loading="ledgerLoading">
        <ElTableColumn :label="t('zcard.supplierAccount.ledgerTime')" prop="created_at" width="170">
          <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.supplierAccount.ledgerType')" width="100">
          <template #default="{ row }">
            <ElTag :type="ledgerTypeTag(row.type)">{{ ledgerTypeLabel(row.type) }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.supplierAccount.ledgerAmount')" width="120">
          <template #default="{ row }">
            <span :class="row.amount >= 0 ? 'amount-in' : 'amount-out'">{{ row.amount >= 0 ? '+' : '' }}{{ formatFen(row.amount) }}</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.supplierAccount.ledgerBalanceAfter')" prop="balance_after" width="130">
          <template #default="{ row }">{{ formatFen(row.balance_after) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.supplierAccount.remark')" prop="remark" min-width="160" show-overflow-tooltip />
      </ElTable>
      <div class="pagination-wrap">
        <ElPagination
          v-model:current-page="ledgerPage.page"
          :total="ledgerPage.total"
          :page-size="20"
          layout="total, prev, pager, next"
          background
          @current-change="fetchLedger"
        />
      </div>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { Plus } from '@element-plus/icons-vue'
  import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
  import { useI18n } from 'vue-i18n'
import { useListTableHeight } from '@/hooks'
  import {
    getSupplierAccounts,
    createSupplierAccount,
    updateSupplierAccount,
    deleteSupplierAccount,
    resetSupplierSecret,
    rechargeSupplierAccount,
    getSupplierLedger,
    type SupplierAccount,
    type SupplierSecretResponse,
    type SupplierLedgerEntry,
  } from '@/api/supplierAccounts'

  defineOptions({ name: 'SupplierAccountList' })

  const { t } = useI18n()

  /** 列表 + 分页 */
  const loading = ref(false)
  const tableData = ref<SupplierAccount[]>([])
  const filterStatus = ref('')
  const filterApproved = ref<number | ''>('')
  const pagination = reactive({ page: 1, pageSize: 15, total: 0 })
  // 表格高度自适应:数据满页时表格内容撑高会被卡片裁掉分页栏,固定表格高度使其内部滚动
  const { cardRef, tableRef, paginationRef, tableHeight } = useListTableHeight()
  /** 余额低于此值(分)飘红 */
  const lowThreshold = 1000

  const fetchData = async () => {
    loading.value = true
    try {
      const res = await getSupplierAccounts({
        page: pagination.page,
        per_page: pagination.pageSize,
        status: filterStatus.value || undefined,
        approved: filterApproved.value === '' ? undefined : filterApproved.value === 1,
      })
      tableData.value = res.data || []
      pagination.total = res.total || 0
    } catch (e) {
      tableData.value = []
    } finally {
      loading.value = false
    }
  }

  /** 新建/编辑 */
  const dialogVisible = ref(false)
  const saving = ref(false)
  const isEdit = ref(false)
  const editingId = ref<number | null>(null)
  const formRef = ref<FormInstance>()
  const defaultForm = () => ({ name: '', contact: '', remark: '', status: 'active' as 'active' | 'disabled', approved: true })
  const formData = reactive(defaultForm())
  const formRules = computed<FormRules>(() => ({
    name: [{ required: true, message: t('zcard.supplierAccount.nameRequired'), trigger: 'blur' }],
  }))

  const openAdd = () => {
    isEdit.value = false
    editingId.value = null
    Object.assign(formData, defaultForm())
    dialogVisible.value = true
    nextTick(() => formRef.value?.clearValidate())
  }

  const openEdit = (row: SupplierAccount) => {
    isEdit.value = true
    editingId.value = row.id
    formData.name = row.name
    formData.contact = row.contact || ''
    formData.remark = row.remark || ''
    formData.status = row.status
    formData.approved = !!row.approved
    dialogVisible.value = true
    nextTick(() => formRef.value?.clearValidate())
  }

  const handleSubmit = async () => {
    if (!formRef.value) return
    try {
      await formRef.value.validate()
    } catch {
      return
    }
    saving.value = true
    try {
      if (isEdit.value && editingId.value !== null) {
        await updateSupplierAccount(editingId.value, {
          name: formData.name,
          contact: formData.contact || undefined,
          remark: formData.remark || undefined,
          status: formData.status,
          approved: formData.approved,
        })
        ElMessage.success(t('zcard.supplierAccount.modified'))
        dialogVisible.value = false
        fetchData()
      } else {
        // 新建:后端返回明文 secret(仅此一次)
        const res = await createSupplierAccount({
          name: formData.name,
          contact: formData.contact || undefined,
          remark: formData.remark || undefined,
        })
        dialogVisible.value = false
        showSecret(res)
        fetchData()
      }
    } catch (e: any) {
      // 拦截器已提示
    } finally {
      saving.value = false
    }
  }

  /** 审核通过/撤销(自助开通的账号在通过前无法调用供货 API) */
  const handleApprove = (row: SupplierAccount, approved: boolean) => {
    ElMessageBox.confirm(
      approved
        ? t('zcard.supplierAccount.approveConfirm', { name: row.name })
        : t('zcard.supplierAccount.revokeConfirm', { name: row.name }),
      t('zcard.common.tips'),
      { type: 'warning' },
    )
      .then(async () => {
        try {
          await updateSupplierAccount(row.id, { approved })
          ElMessage.success(approved ? t('zcard.supplierAccount.approveSuccess') : t('zcard.supplierAccount.revokeSuccess'))
          fetchData()
        } catch (e: any) {
          // 拦截器已提示
        }
      })
      .catch(() => {})
  }

  const handleDelete = (row: SupplierAccount) => {
    ElMessageBox.confirm(t('zcard.supplierAccount.deleteConfirm', { name: row.name }), t('zcard.common.tips'), { type: 'warning' })
      .then(async () => {
        try {
          await deleteSupplierAccount(row.id)
          ElMessage.success(t('zcard.common.deleteSuccess'))
          fetchData()
        } catch (e: any) {
          // 拦截器已提示
        }
      })
      .catch(() => {})
  }

  /** 密钥一次性展示(新建/重置后) */
  const secretVisible = ref(false)
  const secretData = reactive({ api_key: '', api_secret: '' })
  const secretWarning = ref('')

  const showSecret = (res: SupplierSecretResponse) => {
    secretData.api_key = res.api_key
    secretData.api_secret = res.api_secret
    secretWarning.value = res.warning || t('zcard.supplierAccount.secretWarning')
    secretVisible.value = true
  }

  const closeSecret = () => {
    secretVisible.value = false
    secretData.api_key = ''
    secretData.api_secret = ''
  }

  const copy = async (text: string) => {
    try {
      await navigator.clipboard.writeText(text)
      ElMessage.success(t('zcard.supplierAccount.copySuccess'))
    } catch {
      ElMessage.warning(t('zcard.supplierAccount.copyFailed'))
    }
  }

  /** 重置 secret */
  const handleResetSecret = (row: SupplierAccount) => {
    ElMessageBox.confirm(t('zcard.supplierAccount.resetConfirm', { name: row.name }), t('zcard.common.tips'), { type: 'warning' })
      .then(async () => {
        try {
          const res = await resetSupplierSecret(row.id)
          showSecret(res)
          ElMessage.success(t('zcard.supplierAccount.resetSuccess'))
        } catch (e: any) {
          // 拦截器已提示
        }
      })
      .catch(() => {})
  }

  /** 充值 */
  const rechargeVisible = ref(false)
  const rechargeTarget = ref<SupplierAccount | null>(null)
  const rechargeAmount = ref(100)
  const rechargeRemark = ref('')
  const recharging = ref(false)

  const openRecharge = (row: SupplierAccount) => {
    rechargeTarget.value = row
    rechargeAmount.value = 100
    rechargeRemark.value = ''
    rechargeVisible.value = true
  }

  const handleRecharge = async () => {
    if (!rechargeTarget.value || rechargeAmount.value <= 0) return
    recharging.value = true
    try {
      // 元转分
      const amountFen = Math.round(rechargeAmount.value * 100)
      await rechargeSupplierAccount(rechargeTarget.value.id, {
        amount: amountFen,
        remark: rechargeRemark.value || undefined,
      })
      ElMessage.success(t('zcard.supplierAccount.rechargeSuccess'))
      rechargeVisible.value = false
      fetchData()
    } catch (e: any) {
      // 拦截器已提示
    } finally {
      recharging.value = false
    }
  }

  /** 账本流水 */
  const ledgerVisible = ref(false)
  const ledgerTarget = ref<SupplierAccount | null>(null)
  const ledgerData = ref<SupplierLedgerEntry[]>([])
  const ledgerLoading = ref(false)
  const ledgerPage = reactive({ page: 1, total: 0 })

  const openLedger = (row: SupplierAccount) => {
    ledgerTarget.value = row
    ledgerPage.page = 1
    ledgerVisible.value = true
    fetchLedger()
  }

  const fetchLedger = async () => {
    if (!ledgerTarget.value) return
    ledgerLoading.value = true
    try {
      const res = await getSupplierLedger(ledgerTarget.value.id, { page: ledgerPage.page, per_page: 20 })
      ledgerData.value = res.data || []
      ledgerPage.total = res.total || 0
    } catch (e) {
      ledgerData.value = []
    } finally {
      ledgerLoading.value = false
    }
  }

  const ledgerTypeLabel = (type: string) => {
    const map: Record<string, string> = {
      recharge: t('zcard.supplierAccount.typeRecharge'),
      order: t('zcard.supplierAccount.typeOrder'),
      refund: t('zcard.supplierAccount.typeRefund'),
      adjust: t('zcard.supplierAccount.typeAdjust'),
    }
    return map[type] || type
  }
  const ledgerTypeTag = (type: string): 'success' | 'danger' | 'warning' | 'info' => {
    if (type === 'recharge') return 'success'
    if (type === 'order') return 'danger'
    if (type === 'refund') return 'warning'
    return 'info'
  }

  /** 工具:分转元展示 */
  const formatFen = (fen: number | null | undefined) => {
    if (fen === null || fen === undefined) return '—'
    return (fen / 100).toFixed(2)
  }
  const formatTime = (iso: string | null) => {
    if (!iso) return '—'
    const d = new Date(iso.replace(' ', 'T'))
    if (isNaN(d.getTime())) return iso
    return d.toLocaleString()
  }

  onMounted(fetchData)
</script>

<style scoped>
  .supplier-account-page {
    padding: 0;
  }
  .toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
  }
  .toolbar-left {
    display: flex;
    gap: 8px;
    align-items: center;
  }
  .pagination-wrap {
    margin-top: 16px;
    display: flex;
    justify-content: flex-end;
  }
  .text-muted {
    color: var(--el-text-color-placeholder);
  }
  .balance-low {
    color: var(--el-color-danger);
    font-weight: 600;
  }
  .balance-text {
    font-size: 16px;
    font-weight: 600;
  }
  .secret-row {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
  }
  .amount-in {
    color: var(--el-color-success);
  }
  .amount-out {
    color: var(--el-color-danger);
  }
  .unit {
    margin-left: 6px;
    color: var(--el-text-color-secondary);
  }
</style>
