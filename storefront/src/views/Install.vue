<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { getInstallStatus, testDbConnection, runInstall } from '@/api/install'
import type { InstallStatus } from '@/api/install'
import AppIcon from '@/components/AppIcon.vue'

const router = useRouter()

// 步骤: 0=环境检查 1=数据库配置 2=管理员账号 3=安装中 4=完成
const step = ref(0)
const loading = ref(false)
const error = ref('')
const installed = ref(false)
const status = ref<InstallStatus | null>(null)

// 数据库表单
const dbForm = ref({
  host: '127.0.0.1',
  port: 3306,
  database: 'zcard',
  username: 'root',
  password: '',
})

// 管理员表单
const adminForm = ref({
  email: 'admin@zcard.local',
  password: '',
  confirmPassword: '',
})

const dbTesting = ref(false)
const dbTestResult = ref<{ success: boolean; message: string } | null>(null)

const envAllPassed = computed(() => status.value?.all_passed ?? false)

const loadStatus = async () => {
  loading.value = true
  try {
    status.value = await getInstallStatus()
    if (status.value.installed) {
      installed.value = true
    }
  } catch (e: any) {
    error.value = e?.message || '无法获取安装状态'
  } finally {
    loading.value = false
  }
}

const handleTestDb = async () => {
  dbTesting.value = true
  dbTestResult.value = null
  try {
    dbTestResult.value = await testDbConnection({
      host: dbForm.value.host,
      port: Number(dbForm.value.port),
      database: dbForm.value.database,
      username: dbForm.value.username,
      password: dbForm.value.password,
    })
  } catch (e: any) {
    dbTestResult.value = { success: false, message: e?.response?.data?.message || e?.message || '测试失败' }
  } finally {
    dbTesting.value = false
  }
}

const handleInstall = async () => {
  if (adminForm.value.password !== adminForm.value.confirmPassword) {
    error.value = '两次输入的密码不一致'
    return
  }
  if (adminForm.value.password.length < 10) {
    error.value = '管理员密码至少 10 位'
    return
  }

  step.value = 3 // 安装中
  error.value = ''

  try {
    const result = await runInstall({
      db_host: dbForm.value.host,
      db_port: Number(dbForm.value.port),
      db_database: dbForm.value.database,
      db_username: dbForm.value.username,
      db_password: dbForm.value.password,
      admin_email: adminForm.value.email,
      admin_password: adminForm.value.password,
    })

    if (result.success) {
      step.value = 4 // 完成
    } else {
      error.value = result.message
      step.value = 2 // 回到管理员步骤
    }
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.message || '安装失败'
    step.value = 2
  }
}

const goAdmin = () => {
  window.location.href = '/admin'
}

const goHome = () => {
  window.location.href = '/'
}

onMounted(() => {
  loadStatus()
})
</script>

<template>
  <div class="min-h-screen bg-gradient-to-br from-primary-light to-surface-subtle flex items-center justify-center p-4">
    <div class="w-full max-w-2xl">
      <!-- Logo + 标题 -->
      <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-primary to-primary-hover rounded-2xl text-white text-2xl font-extrabold shadow-lg mb-4">
          Z
        </div>
        <h1 class="text-2xl font-extrabold text-ink">ZCard 安装向导</h1>
        <p class="text-sm text-ink-muted mt-2">现代化虚拟商品自动发卡系统</p>
      </div>

      <!-- 已安装 -->
      <div v-if="installed" class="bg-white rounded-card border border-border p-8 text-center shadow-sm">
        <div class="text-5xl mb-4"><AppIcon name="ri:checkbox-circle-line" class="w-12 h-12 text-green-500" /></div>
        <h2 class="text-lg font-bold text-ink mb-2">系统已安装</h2>
        <p class="text-sm text-ink-muted mb-6">如需重新安装,请删除 <code class="bg-surface-subtle px-2 py-1 rounded text-xs">storage/app/installed</code> 文件</p>
        <div class="flex gap-3 justify-center">
          <button @click="goHome" class="px-6 py-2.5 bg-primary text-white rounded-field hover:bg-primary-hover transition font-medium text-sm">前往前台</button>
          <button @click="goAdmin" class="px-6 py-2.5 border border-primary text-primary rounded-field hover:bg-primary-light transition font-medium text-sm">前往后台</button>
        </div>
      </div>

      <!-- 安装向导 -->
      <div v-else class="bg-white rounded-card border border-border shadow-sm overflow-hidden">
        <!-- 步骤条 -->
        <div class="flex border-b border-border">
          <div v-for="(label, idx) in ['环境检查', '数据库', '管理员', '安装']" :key="idx"
            class="flex-1 py-3 text-center text-xs font-medium transition-colors"
            :class="step >= idx ? 'text-primary bg-primary-light' : 'text-ink-muted'">
            <div class="flex items-center justify-center gap-1.5">
              <span class="w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold"
                :class="step >= idx ? 'bg-primary text-white' : 'bg-surface-subtle text-ink-muted'">
                {{ step > idx ? '✓' : idx + 1 }}
              </span>
              {{ label }}
            </div>
          </div>
        </div>

        <div class="p-6 sm:p-8">
          <!-- 错误提示 -->
          <div v-if="error" class="mb-4 px-4 py-3 bg-red-50 border border-red-200 rounded-field text-sm text-danger">
            <span class="inline-flex items-center gap-1"><AppIcon name="ri:alert-line" class="w-4 h-4" /> {{ error }}</span>
          </div>

          <!-- Step 0: 环境检查 -->
          <div v-if="step === 0" v-loading="loading">
            <div class="space-y-2 mb-6">
              <div class="text-sm font-medium text-ink mb-3 inline-flex items-center gap-1"><AppIcon name="ri:clipboard-line" class="w-4 h-4" /> PHP 环境检查</div>
              <div v-for="check in status?.checks" :key="check.name"
                class="flex items-center justify-between py-2 px-3 rounded-field"
                :class="check.passed ? 'bg-green-50' : (check.optional ? 'bg-yellow-50' : 'bg-red-50')">
                <span class="text-sm text-ink">{{ check.name }}</span>
                <span class="text-sm font-bold" :class="check.passed ? 'text-success' : (check.optional ? 'text-warning' : 'text-danger')">
                  {{ check.passed ? '✓' : (check.optional ? '⚠ 跳过' : '✘ 未安装') }}
                </span>
              </div>
            </div>

            <div class="space-y-2 mb-6">
              <div class="text-sm font-medium text-ink mb-3 inline-flex items-center gap-1"><AppIcon name="ri:folder-open-line" class="w-4 h-4" /> 目录权限</div>
              <div v-for="w in status?.writable" :key="w.name"
                class="flex items-center justify-between py-2 px-3 rounded-field"
                :class="w.passed ? 'bg-green-50' : 'bg-red-50'">
                <span class="text-sm text-ink">{{ w.name }}</span>
                <span class="text-sm font-bold" :class="w.passed ? 'text-success' : 'text-danger'">
                  {{ w.passed ? '✓ 可写' : '✘ 不可写' }}
                </span>
              </div>
            </div>

            <button
              :disabled="!envAllPassed"
              @click="step = 1"
              class="w-full py-3 bg-primary text-white rounded-field font-medium text-sm hover:bg-primary-hover transition disabled:opacity-50 disabled:cursor-not-allowed">
              {{ envAllPassed ? '下一步 →' : '环境检查未通过' }}
            </button>
          </div>

          <!-- Step 1: 数据库配置 -->
          <div v-else-if="step === 1">
            <div class="space-y-4 mb-6">
              <div>
                <label class="block text-sm font-medium text-ink mb-1.5">数据库主机</label>
                <input v-model="dbForm.host" type="text"
                  class="w-full px-4 py-2.5 border border-border rounded-field text-sm focus:border-primary focus:outline-none">
              </div>
              <div class="grid grid-cols-3 gap-3">
                <div class="col-span-1">
                  <label class="block text-sm font-medium text-ink mb-1.5">端口</label>
                  <input v-model="dbForm.port" type="number"
                    class="w-full px-4 py-2.5 border border-border rounded-field text-sm focus:border-primary focus:outline-none">
                </div>
                <div class="col-span-2">
                  <label class="block text-sm font-medium text-ink mb-1.5">数据库名</label>
                  <input v-model="dbForm.database" type="text"
                    class="w-full px-4 py-2.5 border border-border rounded-field text-sm focus:border-primary focus:outline-none">
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-ink mb-1.5">用户名</label>
                <input v-model="dbForm.username" type="text"
                  class="w-full px-4 py-2.5 border border-border rounded-field text-sm focus:border-primary focus:outline-none">
              </div>
              <div>
                <label class="block text-sm font-medium text-ink mb-1.5">密码</label>
                <input v-model="dbForm.password" type="password"
                  class="w-full px-4 py-2.5 border border-border rounded-field text-sm focus:border-primary focus:outline-none">
              </div>
            </div>

            <!-- 测试结果 -->
            <div v-if="dbTestResult" class="mb-4 px-4 py-2.5 rounded-field text-sm"
              :class="dbTestResult.success ? 'bg-green-50 text-success' : 'bg-red-50 text-danger'">
              {{ dbTestResult.success ? '✓' : '✘' }} {{ dbTestResult.message }}
            </div>

            <div class="flex gap-3">
              <button @click="step = 0" class="px-4 py-2.5 border border-border rounded-field text-sm text-ink-soft hover:bg-surface-subtle transition">← 返回</button>
              <button @click="handleTestDb" :disabled="dbTesting"
                class="flex-1 py-2.5 border border-primary text-primary rounded-field text-sm font-medium hover:bg-primary-light transition disabled:opacity-50 inline-flex items-center justify-center gap-1.5">
                <AppIcon v-if="!dbTesting" name="ri:plug-line" class="w-4 h-4" />
                {{ dbTesting ? '测试中...' : '测试连接' }}
              </button>
              <button @click="step = 2" :disabled="!dbTestResult?.success"
                class="flex-1 py-2.5 bg-primary text-white rounded-field text-sm font-medium hover:bg-primary-hover transition disabled:opacity-50 disabled:cursor-not-allowed">
                下一步 →
              </button>
            </div>
          </div>

          <!-- Step 2: 管理员账号 -->
          <div v-else-if="step === 2">
            <div class="space-y-4 mb-6">
              <div>
                <label class="block text-sm font-medium text-ink mb-1.5">管理员邮箱</label>
                <input v-model="adminForm.email" type="email"
                  class="w-full px-4 py-2.5 border border-border rounded-field text-sm focus:border-primary focus:outline-none">
              </div>
              <div>
                <label class="block text-sm font-medium text-ink mb-1.5">管理员密码</label>
                <input v-model="adminForm.password" type="password" placeholder="至少 10 位"
                  class="w-full px-4 py-2.5 border border-border rounded-field text-sm focus:border-primary focus:outline-none">
              </div>
              <div>
                <label class="block text-sm font-medium text-ink mb-1.5">确认密码</label>
                <input v-model="adminForm.confirmPassword" type="password"
                  class="w-full px-4 py-2.5 border border-border rounded-field text-sm focus:border-primary focus:outline-none">
              </div>
            </div>

            <div class="flex gap-3">
              <button @click="step = 1" class="px-4 py-2.5 border border-border rounded-field text-sm text-ink-soft hover:bg-surface-subtle transition">← 返回</button>
              <button @click="handleInstall"
                class="flex-1 py-2.5 bg-success text-white rounded-field text-sm font-medium hover:opacity-90 transition">
                <span class="inline-flex items-center gap-1.5"><AppIcon name="ri:rocket-line" class="w-4 h-4" /> 开始安装</span>
              </button>
            </div>
          </div>

          <!-- Step 3: 安装中 -->
          <div v-else-if="step === 3" class="py-12 text-center">
            <div class="inline-block w-12 h-12 border-4 border-primary border-t-transparent rounded-full animate-spin mb-4"></div>
            <p class="text-sm font-medium text-ink">正在安装,请稍候...</p>
            <p class="text-xs text-ink-muted mt-1">创建数据库表 · 初始化角色权限 · 创建管理员账号</p>
          </div>

          <!-- Step 4: 完成 -->
          <div v-else-if="step === 4" class="py-8 text-center">
            <div class="text-5xl mb-4"><AppIcon name="ri:emotion-happy-line" class="w-12 h-12 text-primary" /></div>
            <h2 class="text-lg font-bold text-ink mb-2">安装完成!</h2>
            <p class="text-sm text-ink-muted mb-6">ZCard 已成功安装,请使用刚才创建的管理员账号登录后台</p>
            <div class="flex gap-3 justify-center">
              <button @click="goHome" class="px-6 py-2.5 border border-border rounded-field text-sm text-ink-soft hover:bg-surface-subtle transition">前往前台</button>
              <button @click="goAdmin" class="px-6 py-2.5 bg-primary text-white rounded-field text-sm font-medium hover:bg-primary-hover transition">前往后台 →</button>
            </div>
          </div>
        </div>
      </div>

      <!-- 底部信息 -->
      <div class="text-center mt-6 text-xs text-ink-muted">
        ZCard v1.0.0 · MIT License · 仅供学习研究
      </div>
    </div>
  </div>
</template>
