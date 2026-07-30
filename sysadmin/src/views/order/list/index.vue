<!-- 订单列表 - 后台管理 -->
<template>
  <div class="order-page art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <!-- 搜索栏 -->
      <div class="search-bar">
        <ElForm :inline="true" :model="searchForm" @submit.prevent>
          <ElFormItem label="订单号">
            <ElInput
              v-model="searchForm.keyword"
              placeholder="请输入订单号"
              clearable
              style="width: 240px"
              @keyup.enter="handleSearch"
            />
          </ElFormItem>
          <ElFormItem label="状态">
            <ElSelect
              v-model="searchForm.status"
              placeholder="全部"
              clearable
              style="width: 140px"
            >
              <ElOption label="待支付" value="pending" />
              <ElOption label="已支付" value="paid" />
              <ElOption label="已关闭" value="closed" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <ElButton type="primary" @click="handleSearch">搜索</ElButton>
            <ElButton @click="handleReset">重置</ElButton>
          </ElFormItem>
        </ElForm>
      </div>

      <!-- 表格 -->
      <ElTable
        v-loading="loading"
        :data="tableData"
        border
        stripe
        style="width: 100%"
        @row-click="openDetail"
      >
        <ElTableColumn label="订单号" min-width="200">
          <template #default="{ row }">
            <span class="order-no">{{ row.order_no }}</span>
            <ElIcon class="copy-icon" title="复制" @click.stop="copyOrderNo(row.order_no)">
              <CopyDocument />
            </ElIcon>
          </template>
        </ElTableColumn>
        <ElTableColumn label="商品名" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.product?.name || `#${row.product_id}` }}
          </template>
        </ElTableColumn>
        <ElTableColumn prop="quantity" label="数量" width="80" align="center" />
        <ElTableColumn label="金额" width="120" align="right">
          <template #default="{ row }">¥{{ formatPrice(row.amount) }}</template>
        </ElTableColumn>
        <ElTableColumn label="状态" width="100" align="center">
          <template #default="{ row }">
            <ElTag :type="statusTagType(row.status)" effect="light">
              {{ statusLabel(row.status) }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn label="联系方式" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.contact || row.email || '-' }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="时间" width="170" align="center">
          <template #default="{ row }">
            {{ formatTime(row.created_at) }}
          </template>
        </ElTableColumn>
        <ElTableColumn label="操作" width="120" fixed="right" align="center">
          <template #default="{ row }">
            <ElButton
              v-if="row.status === 'pending'"
              type="danger"
              link
              @click.stop="handleClose(row)"
            >
              关闭订单
            </ElButton>
            <span v-else class="text-muted">-</span>
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

    <!-- 订单详情弹窗 -->
    <ElDialog v-model="detailVisible" title="订单详情" width="640px" destroy-on-close>
      <ElDescriptions v-if="detail" :column="2" border>
        <ElDescriptionsItem label="订单号">{{ detail.order_no }}</ElDescriptionsItem>
        <ElDescriptionsItem label="状态">
          <ElTag :type="statusTagType(detail.status)" effect="light">
            {{ statusLabel(detail.status) }}
          </ElTag>
        </ElDescriptionsItem>
        <ElDescriptionsItem label="商品">
          {{ detail.product?.name || `#${detail.product_id}` }}
        </ElDescriptionsItem>
        <ElDescriptionsItem label="数量">{{ detail.quantity }}</ElDescriptionsItem>
        <ElDescriptionsItem label="金额">¥{{ formatPrice(detail.amount) }}</ElDescriptionsItem>
        <ElDescriptionsItem label="联系方式">
          {{ detail.contact || detail.email || '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem label="创建时间">{{ formatTime(detail.created_at) }}</ElDescriptionsItem>
        <ElDescriptionsItem label="支付时间">
          {{ detail.paid_at ? formatTime(detail.paid_at) : '-' }}
        </ElDescriptionsItem>
      </ElDescriptions>

      <template v-if="detail && detail.status === 'paid'">
        <div class="detail-section-title">卡密发货</div>
        <ElTable
          :data="detail.deliveries || []"
          border
          stripe
          size="small"
          empty-text="暂无发货记录"
        >
          <ElTableColumn type="index" label="#" width="50" align="center" />
          <ElTableColumn prop="content" label="卡密内容" min-width="280" show-overflow-tooltip />
          <ElTableColumn label="发货时间" width="170" align="center">
            <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
          </ElTableColumn>
        </ElTable>
      </template>

      <template #footer>
        <ElButton @click="detailVisible = false">关闭</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { CopyDocument } from '@element-plus/icons-vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import {
    getOrders,
    getOrder,
    closeOrder,
    type Order,
    type OrderStatus
  } from '@/api/orders'

  defineOptions({ name: 'OrderList' })

  /** 金额分 -> 元(两位小数) */
  const formatPrice = (fen: number): string => ((Number(fen) || 0) / 100).toFixed(2)

  /** 时间格式化 */
  const formatTime = (iso: string | null): string => {
    if (!iso) return '-'
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return iso
    const pad = (n: number) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(
      d.getHours()
    )}:${pad(d.getMinutes())}`
  }

  /** 状态文案 */
  const statusLabel = (s: OrderStatus): string => {
    const map: Record<OrderStatus, string> = {
      pending: '待支付',
      paid: '已支付',
      closed: '已关闭'
    }
    return map[s] || s
  }

  /** 状态标签颜色 */
  const statusTagType = (
    s: OrderStatus
  ): 'warning' | 'success' | 'info' | 'danger' => {
    const map: Record<OrderStatus, 'warning' | 'success' | 'info' | 'danger'> = {
      pending: 'warning',
      paid: 'success',
      closed: 'info'
    }
    return map[s] || 'info'
  }

  /** 列表/分页状态 */
  const loading = ref(false)
  const tableData = ref<Order[]>([])
  const pagination = reactive({
    page: 1,
    pageSize: 15,
    total: 0
  })

  /** 搜索表单 */
  const searchForm = reactive<{ keyword?: string; status?: OrderStatus }>({
    keyword: undefined,
    status: undefined
  })

  /** 拉取列表 */
  const fetchData = async () => {
    loading.value = true
    try {
      const res = await getOrders({
        page: pagination.page,
        pageSize: pagination.pageSize,
        keyword: searchForm.keyword,
        status: searchForm.status
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
    searchForm.status = undefined
    pagination.page = 1
    fetchData()
  }

  /** 复制订单号 */
  const copyOrderNo = async (orderNo: string) => {
    try {
      await navigator.clipboard.writeText(orderNo)
      ElMessage.success('已复制订单号')
    } catch (e) {
      ElMessage.warning('复制失败，请手动复制')
    }
  }

  /** 关闭订单 */
  const handleClose = (row: Order) => {
    ElMessageBox.confirm(`确定要关闭订单「${row.order_no}」吗？`, '关闭订单', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning'
    })
      .then(async () => {
        try {
          await closeOrder(row.id)
          ElMessage.success('订单已关闭')
          fetchData()
        } catch (e) {
          // 错误提示由拦截器统一处理
        }
      })
      .catch(() => {
        // 用户取消
      })
  }

  /** 详情弹窗 */
  const detailVisible = ref(false)
  const detail = ref<Order | null>(null)

  const openDetail = async (row: Order) => {
    detail.value = null
    detailVisible.value = true
    try {
      detail.value = await getOrder(row.id)
    } catch (e) {
      detail.value = row
    }
  }

  onMounted(() => {
    fetchData()
  })
</script>

<style lang="scss" scoped>
  .order-page {
    display: flex;
    flex-direction: column;
  }

  .search-bar {
    margin-bottom: 16px;
  }

  .pagination-bar {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }

  .order-no {
    font-family: 'JetBrains Mono', Menlo, Consolas, monospace;
  }

  .copy-icon {
    margin-left: 6px;
    color: var(--el-color-primary);
    cursor: pointer;
    vertical-align: middle;
  }

  .text-muted {
    color: var(--el-text-color-placeholder);
  }

  .detail-section-title {
    margin: 20px 0 12px;
    font-size: 15px;
    font-weight: 600;
  }
</style>
