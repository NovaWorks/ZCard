<!-- 用户列表 - 后台管理 -->
<template>
  <div class="user-page art-full-height">
    <!-- 统计卡片 -->
    <ElRow :gutter="16" class="stats-row">
      <ElCol v-for="card in statCards" :key="card.key" :xs="12" :sm="12" :md="6">
        <div class="stat-card" :class="card.cls">
          <div class="stat-icon">
            <ElIcon :size="28">
              <component :is="card.icon" />
            </ElIcon>
          </div>
          <div class="stat-body">
            <div class="stat-number">{{ card.value }}</div>
            <div class="stat-label">{{ card.label }}</div>
          </div>
        </div>
      </ElCol>
    </ElRow>

    <ElCard ref="cardRef" class="art-table-card" shadow="never">
      <!-- 搜索栏：自动触发搜索 -->
      <div class="search-bar">
        <ElForm :inline="true" :model="searchForm" @submit.prevent>
          <ElFormItem>
            <ElInput
              v-model="searchForm.keyword"
              :placeholder="t('zcard.user.searchHint')"
              clearable
              style="width: 240px"
              @input="debouncedSearch"
              @clear="debouncedSearch"
            />
          </ElFormItem>
          <ElFormItem>
            <ElSelect
              v-model="searchForm.group_id"
              :placeholder="t('zcard.user.groupLevel')"
              clearable
              style="width: 180px"
              @change="handleSearch"
            >
              <ElOption
                v-for="g in groupOptions"
                :key="g.id"
                :label="g.name"
                :value="g.id as number"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <ElSelect
              v-model="searchForm.status"
              :placeholder="t('zcard.user.status')"
              clearable
              style="width: 140px"
              @change="handleSearch"
            >
              <ElOption :label="t('zcard.user.statusEnabled')" :value="1" />
              <ElOption :label="t('zcard.user.statusDisabled')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <ElButton @click="handleReset">{{ t('zcard.common.reset') }}</ElButton>
          </ElFormItem>
        </ElForm>
      </div>

      <!-- 操作栏 -->
      <div class="table-header">
        <ElButton type="primary" @click="openCreate">{{ t('zcard.user.add') }}</ElButton>
      </div>

      <!-- 表格 -->
      <ElTable
        ref="tableRef"
        v-loading="loading"
        :data="tableData"
        :height="tableHeight"
        border
        stripe
        style="width: 100%"
        @selection-change="handleSelectionChange"
      >
        <ElTableColumn type="selection" width="44" />
        <ElTableColumn prop="id" :label="t('zcard.common.id')" width="70" />
        <ElTableColumn :label="t('zcard.user.username')" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">
            <div class="user-cell">
              <ElAvatar v-if="row.avatar" :src="row.avatar" :size="28" />
              <span class="user-cell-name">{{ row.username }}</span>
            </div>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="email" :label="t('zcard.user.email')" min-width="190" show-overflow-tooltip />
        <ElTableColumn prop="phone" :label="t('zcard.user.phone')" width="130" show-overflow-tooltip>
          <template #default="{ row }">{{ row.phone || '-' }}</template>
        </ElTableColumn>
        <ElTableColumn prop="qq" :label="t('zcard.user.qq')" width="110" align="center">
          <template #default="{ row }">{{ row.qq || '-' }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.user.balance')" width="110" align="right">
          <template #default="{ row }">¥{{ formatPrice(row.balance) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.user.points')" width="90" align="right">
          <template #default="{ row }">{{ Number(row.points) || 0 }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.user.groupLevel')" width="120" align="center">
          <template #default="{ row }">
            <ElTag v-if="row.userGroup" type="warning" effect="light" size="small">
              {{ row.userGroup.name }}
            </ElTag>
            <span v-else class="text-muted">-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.user.parentUser')" width="130" align="center">
          <template #default="{ row }">
            <span v-if="row.pid && row.parent">{{ row.parent.username }}</span>
            <span v-else-if="row.pid">#{{ row.pid }}</span>
            <span v-else class="text-muted">-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.user.status')" width="90" align="center">
          <template #default="{ row }">
            <ElSwitch
              :model-value="!!row.status"
              :loading="row._statusLoading"
              @change="(val: any) => handleStatusToggle(row, !!val)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.user.registerTime')" width="160" align="center">
          <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.common.actions')" width="140" fixed="right" align="center">
          <template #default="{ row }">
            <ElButton type="primary" link @click="openEdit(row)">{{ t('zcard.common.edit') }}</ElButton>
            <ElButton type="danger" link @click="handleDelete(row)">{{ t('zcard.common.delete') }}</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <!-- 分页 -->
      <div ref="paginationRef" class="pagination-bar">
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

    <!-- 新增/编辑抽屉 -->
    <ElDrawer
      v-model="drawerVisible"
      :title="drawerTitle"
      size="480px"
      destroy-on-close
      @closed="resetForm"
    >
      <ElForm ref="formRef" :model="formData" :rules="formRules" label-width="100px">
        <ElFormItem :label="t('zcard.user.username')" prop="username">
          <ElInput v-model="formData.username" :placeholder="t('zcard.user.usernameRequired')" maxlength="60" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.user.email')" prop="email">
          <ElInput v-model="formData.email" :placeholder="t('zcard.user.emailRequired')" maxlength="150" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.user.phone')" prop="phone">
          <ElInput v-model="formData.phone" :placeholder="t('zcard.user.phone')" maxlength="30" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.user.qq')" prop="qq">
          <ElInput v-model="formData.qq" :placeholder="t('zcard.user.qq')" maxlength="20" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.user.groupLevel')" prop="group_id">
          <ElSelect v-model="formData.group_id" clearable :placeholder="t('zcard.user.groupPlaceholder')" style="width: 100%">
            <ElOption
              v-for="g in groupOptions"
              :key="g.id"
              :label="g.name"
              :value="g.id as number"
            />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="t('zcard.user.balance')" prop="balance">
          <ElInputNumber v-model="formData.balance" :min="0" :step="100" style="width: 100%" />
          <div class="form-hint">¥{{ formatPrice(formData.balance || 0) }}</div>
        </ElFormItem>
        <ElFormItem :label="t('zcard.user.points')" prop="points">
          <ElInputNumber v-model="formData.points" :min="0" :step="1" style="width: 100%" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.user.parentId')" prop="pid">
          <ElInputNumber v-model="formData.pid" :min="0" :step="1" style="width: 100%" />
          <div class="form-hint">{{ t('zcard.user.pidHint') }}</div>
        </ElFormItem>
        <ElFormItem :label="t('zcard.user.password')" :prop="passwordProp">
          <ElInput
            v-model="formData.password"
            type="password"
            show-password
            :placeholder="drawerType === 'create' ? t('zcard.user.passwordCreatePlaceholder') : t('zcard.user.passwordEditPlaceholder')"
          />
        </ElFormItem>
        <ElFormItem :label="t('zcard.user.status')">
          <ElSwitch
            v-model="formData.status"
            :active-value="1"
            :inactive-value="0"
            :active-text="t('zcard.user.statusActiveText')"
            :inactive-text="t('zcard.user.statusInactiveText')"
          />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="drawerVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="submitting" @click="handleSubmit">{{ t('zcard.common.ok') }}</ElButton>
      </template>
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import type { FormInstance, FormRules } from 'element-plus'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { User as UserIcon, CircleCheck, CircleClose, TrendCharts } from '@element-plus/icons-vue'
  import { useDebounceFn } from '@vueuse/core'
  import { useI18n } from 'vue-i18n'
  import { useListTableHeight } from '@/hooks'
  import {
    getUsers,
    getUserStats,
    createUser,
    updateUser,
    deleteUser,
    type User,
    type UserStats
  } from '@/api/users'
  import { getUserGroups, type UserGroup } from '@/api/userGroup'

  defineOptions({ name: 'UserList' })

  const { t } = useI18n()

  const formatPrice = (fen: number): string => ((Number(fen) || 0) / 100).toFixed(2)

  const formatTime = (iso: string | null): string => {
    if (!iso) return '-'
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return iso
    const pad = (n: number) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(
      d.getHours()
    )}:${pad(d.getMinutes())}`
  }

  /** 列表状态 */
  const loading = ref(false)
  const tableData = ref<(User & { _statusLoading?: boolean })[]>([])
  const pagination = reactive({
    page: 1,
    pageSize: 15,
    total: 0
  })
  // 表格高度自适应:数据满页时表格内容撑高会被卡片裁掉分页栏,固定表格高度使其内部滚动
  const { cardRef, tableRef, paginationRef, tableHeight } = useListTableHeight()

  /** 会员等级下拉 */
  const groupOptions = ref<UserGroup[]>([])

  /** 统计数据 */
  const stats = ref<UserStats>({ total: 0, active: 0, disabled: 0, todayNew: 0 })

  const statCards = computed(() => [
    {
      key: 'total',
      label: t('zcard.user.statsTotal'),
      value: stats.value.total,
      icon: UserIcon,
      cls: 'stat-total'
    },
    {
      key: 'active',
      label: t('zcard.user.statsActive'),
      value: stats.value.active,
      icon: CircleCheck,
      cls: 'stat-active'
    },
    {
      key: 'disabled',
      label: t('zcard.user.statsDisabled'),
      value: stats.value.disabled,
      icon: CircleClose,
      cls: 'stat-disabled'
    },
    {
      key: 'todayNew',
      label: t('zcard.user.statsTodayNew'),
      value: stats.value.todayNew,
      icon: TrendCharts,
      cls: 'stat-today'
    }
  ])

  /** 搜索表单 */
  const searchForm = reactive<{
    keyword?: string
    status?: number
    group_id?: number
  }>({ keyword: undefined, status: undefined, group_id: undefined })

  const fetchStats = async () => {
    try {
      stats.value = await getUserStats()
    } catch {
      // 拦截器处理
    }
  }

  const fetchData = async () => {
    loading.value = true
    try {
      const res = await getUsers({
        page: pagination.page,
        pageSize: pagination.pageSize,
        keyword: searchForm.keyword,
        status: searchForm.status,
        group_id: searchForm.group_id
      })
      tableData.value = (res.data || []) as (User & { _statusLoading?: boolean })[]
      pagination.total = res.total || 0
    } catch {
      tableData.value = []
      pagination.total = 0
    } finally {
      loading.value = false
    }
  }

  const loadGroups = async () => {
    try {
      groupOptions.value = (await getUserGroups()) || []
    } catch {
      groupOptions.value = []
    }
  }

  const handleSearch = () => {
    pagination.page = 1
    fetchData()
  }

  // 输入防抖自动搜索
  const debouncedSearch = useDebounceFn(() => {
    handleSearch()
  }, 400)

  const handleReset = () => {
    searchForm.keyword = undefined
    searchForm.status = undefined
    searchForm.group_id = undefined
    pagination.page = 1
    fetchData()
  }

  /** 批量选择 */
  const selectedIds = ref<number[]>([])
  const handleSelectionChange = (rows: User[]) => {
    selectedIds.value = rows.map((r) => r.id)
  }

  /** 切换状态 */
  const handleStatusToggle = async (row: User & { _statusLoading?: boolean }, val: boolean) => {
    const next = val ? 1 : 0
    if (Number(row.status) === next) return
    row._statusLoading = true
    try {
      await updateUser(row.id, { status: next })
      row.status = next
      ElMessage.success(t('zcard.user.modified'))
      fetchStats()
    } catch {
      // 拦截器处理
    } finally {
      row._statusLoading = false
    }
  }

  /** 抽屉 */
  const drawerVisible = ref(false)
  const drawerType = ref<'create' | 'edit'>('create')
  const submitting = ref(false)
  const editId = ref<number | null>(null)
  const formRef = ref<FormInstance>()

  const drawerTitle = computed(() =>
    drawerType.value === 'create' ? t('zcard.user.add') : t('zcard.user.editUser')
  )

  /** 密码字段：新增必填，编辑可选(留空不修改) */
  const passwordProp = computed(() => (drawerType.value === 'create' ? 'password' : undefined))

  interface UserFormState {
    username: string
    email: string
    password: string
    phone: string
    qq: string
    group_id: number | undefined
    balance: number
    points: number
    pid: number
    status: number
  }

  const createEmptyForm = (): UserFormState => ({
    username: '',
    email: '',
    password: '',
    phone: '',
    qq: '',
    group_id: undefined,
    balance: 0,
    points: 0,
    pid: 0,
    status: 1
  })

  const formData = reactive<UserFormState>(createEmptyForm())

  const formRules = computed<FormRules>(() => ({
    username: [{ required: true, message: t('zcard.user.usernameRequired'), trigger: 'blur' }],
    email: [
      { required: true, message: t('zcard.user.emailRequired'), trigger: 'blur' },
      { type: 'email', message: t('zcard.user.emailInvalid'), trigger: 'blur' }
    ],
    password: [{ required: true, message: t('zcard.user.passwordRequired'), trigger: 'blur' }]
  }))

  const openCreate = () => {
    drawerType.value = 'create'
    editId.value = null
    Object.assign(formData, createEmptyForm())
    drawerVisible.value = true
  }

  const openEdit = (row: User) => {
    drawerType.value = 'edit'
    editId.value = row.id
    Object.assign(formData, createEmptyForm(), {
      username: row.username,
      email: row.email,
      password: '',
      phone: row.phone || '',
      qq: row.qq || '',
      group_id: row.group_id || undefined,
      balance: Number(row.balance) || 0,
      points: Number(row.points) || 0,
      pid: Number(row.pid) || 0,
      status: row.status ?? 1
    })
    drawerVisible.value = true
  }

  const resetForm = () => {
    formRef.value?.resetFields()
    Object.assign(formData, createEmptyForm())
    editId.value = null
  }

  const handleSubmit = async () => {
    if (!formRef.value) return
    try {
      await formRef.value.validate()
    } catch {
      return
    }

    const payload: any = {
      username: formData.username,
      email: formData.email,
      phone: formData.phone || null,
      qq: formData.qq || null,
      group_id: formData.group_id ?? 0,
      balance: Number(formData.balance) || 0,
      points: Number(formData.points) || 0,
      pid: Number(formData.pid) || 0,
      status: formData.status
    }
    // 密码：新增必传，编辑留空不传
    if (drawerType.value === 'create' || formData.password) {
      payload.password = formData.password
    }

    submitting.value = true
    try {
      if (drawerType.value === 'create') {
        await createUser(payload)
        ElMessage.success(t('zcard.user.created'))
      } else if (editId.value !== null) {
        await updateUser(editId.value, payload)
        ElMessage.success(t('zcard.user.modified'))
      }
      drawerVisible.value = false
      fetchData()
      fetchStats()
    } catch {
      // 拦截器处理
    } finally {
      submitting.value = false
    }
  }

  const handleDelete = (row: User) => {
    ElMessageBox.confirm(t('zcard.user.deleteConfirm', { name: row.username }), t('zcard.user.deleteTitle'), {
      confirmButtonText: t('zcard.common.ok'),
      cancelButtonText: t('zcard.common.cancel'),
      type: 'warning'
    })
      .then(async () => {
        try {
          await deleteUser(row.id)
          ElMessage.success(t('zcard.common.deleteSuccess'))
          fetchData()
          fetchStats()
        } catch {
          // 拦截器处理
        }
      })
      .catch(() => {
        // 取消
      })
  }

  onActivated(() => {
    loadGroups()
    fetchData()
    fetchStats()
  })
</script>

<style lang="scss" scoped>
  .user-page {
    display: flex;
    flex-direction: column;
  }

  .stats-row {
    margin-bottom: 16px;
  }

  .stat-card {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 18px;
    border-radius: 10px;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color-lighter);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
    height: 100%;
    transition:
      transform 0.2s,
      box-shadow 0.2s;

    &:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    }

    .stat-icon {
      width: 52px;
      height: 52px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .stat-number {
      font-size: 24px;
      font-weight: 700;
      line-height: 1.2;
      color: var(--el-text-color-primary);
    }

    .stat-label {
      font-size: 13px;
      color: var(--el-text-color-secondary);
      margin-top: 2px;
    }
  }

  .stat-total .stat-icon {
    background: rgba(64, 158, 255, 0.12);
    color: #409eff;
  }

  .stat-total .stat-number {
    color: #409eff;
  }

  .stat-active .stat-icon {
    background: rgba(103, 194, 58, 0.12);
    color: #67c23a;
  }

  .stat-active .stat-number {
    color: #67c23a;
  }

  .stat-disabled .stat-icon {
    background: rgba(245, 108, 108, 0.12);
    color: #f56c6c;
  }

  .stat-disabled .stat-number {
    color: #f56c6c;
  }

  .stat-today .stat-icon {
    background: rgba(230, 162, 60, 0.12);
    color: #e6a23c;
  }

  .stat-today .stat-number {
    color: #e6a23c;
  }

  .search-bar {
    margin-bottom: 16px;
  }

  .table-header {
    display: flex;
    align-items: center;
    margin-bottom: 16px;
  }

  .pagination-bar {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }

  .user-cell {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .user-cell-name {
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .form-hint {
    font-size: 12px;
    color: var(--el-text-color-secondary);
    line-height: 1.4;
    margin-top: 4px;
  }

  .text-muted {
    color: var(--el-text-color-placeholder);
  }
</style>
