<!-- 用户列表 - 后台管理 -->
<template>
  <div class="user-page art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <!-- 搜索栏 -->
      <div class="search-bar">
        <ElForm :inline="true" :model="searchForm" @submit.prevent>
          <ElFormItem label="关键词">
            <ElInput
              v-model="searchForm.keyword"
              placeholder="用户名 / 邮箱"
              clearable
              style="width: 240px"
              @keyup.enter="handleSearch"
            />
          </ElFormItem>
          <ElFormItem>
            <ElButton type="primary" @click="handleSearch">搜索</ElButton>
            <ElButton @click="handleReset">重置</ElButton>
          </ElFormItem>
        </ElForm>
      </div>

      <!-- 操作栏 -->
      <div class="table-header">
        <ElButton type="primary" @click="openCreate">新增用户</ElButton>
      </div>

      <!-- 表格 -->
      <ElTable v-loading="loading" :data="tableData" border stripe style="width: 100%">
        <ElTableColumn prop="id" label="ID" width="80" />
        <ElTableColumn prop="username" label="用户名" min-width="140" show-overflow-tooltip />
        <ElTableColumn prop="email" label="邮箱" min-width="200" show-overflow-tooltip />
        <ElTableColumn label="余额" width="120" align="right">
          <template #default="{ row }">¥{{ formatPrice(row.balance) }}</template>
        </ElTableColumn>
        <ElTableColumn label="角色" width="180" align="center">
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
        <ElTableColumn label="状态" width="100" align="center">
          <template #default="{ row }">
            <ElTag :type="row.status ? 'success' : 'danger'" effect="light">
              {{ row.status ? '正常' : '禁用' }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="注册时间" width="170" align="center">
          <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
        </ElTableColumn>
        <ElTableColumn label="操作" width="160" fixed="right" align="center">
          <template #default="{ row }">
            <ElButton type="primary" link @click="openEdit(row)">编辑</ElButton>
            <ElButton type="danger" link @click="handleDelete(row)">删除</ElButton>
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
        <ElFormItem label="用户名" prop="username">
          <ElInput v-model="formData.username" placeholder="请输入用户名" maxlength="60" />
        </ElFormItem>
        <ElFormItem label="邮箱" prop="email">
          <ElInput v-model="formData.email" placeholder="请输入邮箱" maxlength="150" />
        </ElFormItem>
        <ElFormItem label="密码" :prop="dialogType === 'create' ? 'password' : undefined">
          <ElInput
            v-model="formData.password"
            type="password"
            show-password
            :placeholder="dialogType === 'create' ? '请输入密码' : '留空则不修改密码'"
          />
        </ElFormItem>
        <ElFormItem label="角色" prop="roles">
          <ElSelect
            v-model="formData.roles"
            multiple
            placeholder="请选择角色"
            style="width: 100%"
          >
            <ElOption label="超级管理员" value="super_admin" />
            <ElOption label="商户" value="merchant" />
            <ElOption label="用户" value="user" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem label="状态">
          <ElSwitch
            v-model="formData.status"
            :active-value="1"
            :inactive-value="0"
            active-text="正常"
            inactive-text="禁用"
          />
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="dialogVisible = false">取消</ElButton>
        <ElButton type="primary" :loading="submitting" @click="handleSubmit">确定</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import type { FormInstance, FormRules } from 'element-plus'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import {
    getUsers,
    createUser,
    updateUser,
    deleteUser,
    type User
  } from '@/api/users'

  defineOptions({ name: 'UserList' })

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
      super_admin: '超级管理员',
      merchant: '商户',
      user: '用户'
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

  const dialogTitle = computed(() => (dialogType.value === 'create' ? '新增用户' : '编辑用户'))

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

  const formRules: FormRules = {
    username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
    email: [
      { required: true, message: '请输入邮箱', trigger: 'blur' },
      { type: 'email', message: '邮箱格式不正确', trigger: 'blur' }
    ],
    password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
    roles: [{ required: true, message: '请选择角色', trigger: 'change' }]
  }

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
        ElMessage.success('新增成功')
      } else if (editId.value !== null) {
        await updateUser(editId.value, payload)
        ElMessage.success('更新成功')
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
    ElMessageBox.confirm(`确定要删除用户「${row.username}」吗？`, '删除用户', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning'
    })
      .then(async () => {
        try {
          await deleteUser(row.id)
          ElMessage.success('删除成功')
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
