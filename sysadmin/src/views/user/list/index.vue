<!-- 用户列表 - 后台管理 -->
<template>
  <div class="user-page art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <!-- 搜索栏 -->
      <div class="search-bar">
        <ElForm :inline="true" :model="searchForm" @submit.prevent>
          <ElFormItem :label="t('zcard.user.searchKeyword')">
            <ElInput
              v-model="searchForm.keyword"
              :placeholder="t('zcard.user.searchPlaceholder')"
              clearable
              style="width: 240px"
              @keyup.enter="handleSearch"
            />
          </ElFormItem>
          <ElFormItem>
            <ElButton type="primary" @click="handleSearch">{{ t('zcard.common.search') }}</ElButton>
            <ElButton @click="handleReset">{{ t('zcard.common.reset') }}</ElButton>
          </ElFormItem>
        </ElForm>
      </div>

      <!-- 操作栏 -->
      <div class="table-header">
        <ElButton type="primary" @click="openCreate">{{ t('zcard.user.add') }}</ElButton>
      </div>

      <!-- 表格 -->
      <ElTable v-loading="loading" :data="tableData" border stripe style="width: 100%">
        <ElTableColumn prop="id" :label="t('zcard.common.id')" width="80" />
        <ElTableColumn prop="username" :label="t('zcard.user.username')" min-width="140" show-overflow-tooltip />
        <ElTableColumn prop="email" :label="t('zcard.user.email')" min-width="200" show-overflow-tooltip />
        <ElTableColumn :label="t('zcard.user.balance')" width="120" align="right">
          <template #default="{ row }">¥{{ formatPrice(row.balance) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.user.roles')" width="180" align="center">
          <template #default="{ row }">
            <ElTag
              v-for="r in row.roles || []"
              :key="r"
              :type="roleTagType(r)"
              effect="plain"
              class="role-tag"
            >
              {{ roleLabel(r) }}
            </ElTag>
            <span v-if="!row.roles || row.roles.length === 0" class="text-muted">-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.user.status')" width="100" align="center">
          <template #default="{ row }">
            <ElTag :type="row.status ? 'success' : 'danger'" effect="light">
              {{ row.status ? t('zcard.user.statusNormal') : t('zcard.user.statusDisabled') }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.user.registerTime')" width="170" align="center">
          <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.common.actions')" width="160" fixed="right" align="center">
          <template #default="{ row }">
            <ElButton type="primary" link @click="openEdit(row)">{{ t('zcard.common.edit') }}</ElButton>
            <ElButton type="danger" link @click="handleDelete(row)">{{ t('zcard.common.delete') }}</ElButton>
          </template>
        </ElTableColumn>
      </ElTable>

      <!-- 分页 -->
      <div class="pagination-bar">
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

    <!-- 新增/编辑弹窗 -->
    <ElDialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="520px"
      destroy-on-close
      @closed="resetForm"
    >
      <ElForm ref="formRef" :model="formData" :rules="formRules" label-width="90px">
        <ElFormItem :label="t('zcard.user.username')" prop="username">
          <ElInput v-model="formData.username" :placeholder="t('zcard.user.usernameRequired')" maxlength="60" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.user.email')" prop="email">
          <ElInput v-model="formData.email" :placeholder="t('zcard.user.emailRequired')" maxlength="150" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.user.password')" :prop="dialogType === 'create' ? 'password' : undefined">
          <ElInput
            v-model="formData.password"
            type="password"
            show-password
            :placeholder="dialogType === 'create' ? t('zcard.user.passwordCreatePlaceholder') : t('zcard.user.passwordEditPlaceholder')"
          />
        </ElFormItem>
        <ElFormItem :label="t('zcard.user.roles')" prop="roles">
          <ElSelect
            v-model="formData.roles"
            multiple
            :placeholder="t('zcard.user.rolesRequired')"
            style="width: 100%"
          >
            <ElOption :label="t('zcard.user.roleSuperAdmin')" value="super_admin" />
            <ElOption :label="t('zcard.user.roleMerchant')" value="merchant" />
            <ElOption :label="t('zcard.user.roleUser')" value="user" />
          </ElSelect>
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
        <ElButton @click="dialogVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="submitting" @click="handleSubmit">{{ t('zcard.common.ok') }}</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import type { FormInstance, FormRules } from 'element-plus'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import {
    getUsers,
    createUser,
    updateUser,
    deleteUser,
    type User
  } from '@/api/users'

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

  const roleLabel = (r: string): string => {
    const map: Record<string, string> = {
      super_admin: t('zcard.user.roleSuperAdmin'),
      merchant: t('zcard.user.roleMerchant'),
      user: t('zcard.user.roleUser')
    }
    return map[r] || r
  }

  const roleTagType = (r: string): 'danger' | 'warning' | 'info' => {
    if (r === 'super_admin') return 'danger'
    if (r === 'merchant') return 'warning'
    return 'info'
  }

  /** 列表状态 */
  const loading = ref(false)
  const tableData = ref<User[]>([])
  const pagination = reactive({
    page: 1,
    pageSize: 15,
    total: 0
  })

  const searchForm = reactive<{ keyword?: string }>({ keyword: undefined })

  const fetchData = async () => {
    loading.value = true
    try {
      const res = await getUsers({
        page: pagination.page,
        pageSize: pagination.pageSize,
        keyword: searchForm.keyword
      })
      tableData.value = res.data || []
      pagination.total = res.total || 0
    } catch (e) {
      tableData.value = []
      pagination.total = 0
    } finally {
      loading.value = false
    }
  }

  const handleSearch = () => {
    pagination.page = 1
    fetchData()
  }

  const handleReset = () => {
    searchForm.keyword = undefined
    pagination.page = 1
    fetchData()
  }

  /** 弹窗 */
  const dialogVisible = ref(false)
  const dialogType = ref<'create' | 'edit'>('create')
  const submitting = ref(false)
  const editId = ref<number | null>(null)
  const formRef = ref<FormInstance>()

  const dialogTitle = computed(() => (dialogType.value === 'create' ? t('zcard.user.add') : t('zcard.user.edit')))

  interface UserFormState {
    username: string
    email: string
    password: string
    roles: string[]
    status: number
  }

  const createEmptyForm = (): UserFormState => ({
    username: '',
    email: '',
    password: '',
    roles: [],
    status: 1
  })

  const formData = reactive<UserFormState>(createEmptyForm())

  const formRules = computed<FormRules>(() => ({
    username: [{ required: true, message: t('zcard.user.usernameRequired'), trigger: 'blur' }],
    email: [
      { required: true, message: t('zcard.user.emailRequired'), trigger: 'blur' },
      { type: 'email', message: t('zcard.user.emailInvalid'), trigger: 'blur' }
    ],
    password: [{ required: true, message: t('zcard.user.passwordRequired'), trigger: 'blur' }],
    roles: [{ required: true, message: t('zcard.user.rolesRequired'), trigger: 'change' }]
  }))

  const openCreate = () => {
    dialogType.value = 'create'
    editId.value = null
    Object.assign(formData, createEmptyForm())
    dialogVisible.value = true
  }

  const openEdit = (row: User) => {
    dialogType.value = 'edit'
    editId.value = row.id
    Object.assign(formData, createEmptyForm(), {
      username: row.username,
      email: row.email,
      password: '',
      roles: [...(row.roles || [])],
      status: row.status ?? 1
    })
    dialogVisible.value = true
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
      roles: formData.roles,
      status: formData.status
    }
    // 密码：新增必传，编辑留空不传
    if (dialogType.value === 'create' || formData.password) {
      payload.password = formData.password
    }

    submitting.value = true
    try {
      if (dialogType.value === 'create') {
        await createUser(payload)
        ElMessage.success(t('zcard.user.created'))
      } else if (editId.value !== null) {
        await updateUser(editId.value, payload)
        ElMessage.success(t('zcard.user.modified'))
      }
      dialogVisible.value = false
      fetchData()
    } catch (e) {
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
        } catch (e) {
          // 拦截器处理
        }
      })
      .catch(() => {
        // 取消
      })
  }

  onMounted(() => {
    fetchData()
  })
</script>

<style lang="scss" scoped>
  .user-page {
    display: flex;
    flex-direction: column;
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

  .role-tag {
    margin-right: 4px;
  }

  .text-muted {
    color: var(--el-text-color-placeholder);
  }
</style>
