<!-- 订单列表 - 后台管理 -->
<template>
  <div class="order-page art-full-height">
    <ElCard class="art-table-card" shadow="never">
      <!-- 搜索栏 -->
      <div class="search-bar">
        <ElForm :inline="true" :model="searchForm" @submit.prevent>
          <ElFormItem :label="t('zcard.order.orderNo')">
            <ElInput
              v-model="searchForm.keyword"
              :placeholder="t('zcard.order.searchPlaceholder')"
              clearable
              style="width: 240px"
              @keyup.enter="handleSearch"
            />
          </ElFormItem>
          <ElFormItem :label="t('zcard.card.status')">
            <ElSelect
              v-model="searchForm.status"
              :placeholder="t('zcard.order.allStatus')"
              clearable
              style="width: 140px"
            >
              <ElOption :label="t('zcard.order.statusPending')" value="pending" />
              <ElOption :label="t('zcard.order.statusPaid')" value="paid" />
              <ElOption :label="t('zcard.order.statusClosed')" value="closed" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <ElButton type="primary" @click="handleSearch">{{ t('zcard.common.search') }}</ElButton>
            <ElButton @click="handleReset">{{ t('zcard.common.reset') }}</ElButton>
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
        <ElTableColumn :label="t('zcard.order.orderNo')" min-width="200">
          <template #default="{ row }">
            <span class="order-no">{{ row.order_no }}</span>
            <ElIcon class="copy-icon" :title="t('zcard.order.copy')" @click.stop="copyOrderNo(row.order_no)">
              <CopyDocument />
            </ElIcon>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.order.productName')" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.product?.name || `#${row.product_id}` }}
          </template>
        </ElTableColumn>
        <ElTableColumn prop="quantity" :label="t('zcard.order.quantity')" width="80" align="center" />
        <ElTableColumn :label="t('zcard.order.amount')" width="120" align="right">
          <template #default="{ row }">¥{{ formatPrice(row.amount) }}</template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.card.status')" width="100" align="center">
          <template #default="{ row }">
            <ElTag :type="statusTagType(row.status)" effect="light">
              {{ statusLabel(row.status) }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.order.contact')" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.contact || row.email || '-' }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.order.time')" width="170" align="center">
          <template #default="{ row }">
            {{ formatTime(row.created_at) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.common.actions')" width="120" fixed="right" align="center">
          <template #default="{ row }">
            <ElButton
              v-if="row.status === 'pending'"
              type="danger"
              link
              @click.stop="handleClose(row)"
            >
              {{ t('zcard.order.close') }}
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
    <ElDialog v-model="detailVisible" :title="t('zcard.order.detail')" width="640px" destroy-on-close>
      <ElDescriptions v-if="detail" :column="2" border>
        <ElDescriptionsItem :label="t('zcard.order.orderNo')">{{ detail.order_no }}</ElDescriptionsItem>
        <ElDescriptionsItem :label="t('zcard.card.status')">
          <ElTag :type="statusTagType(detail.status)" effect="light">
            {{ statusLabel(detail.status) }}
          </ElTag>
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="t('zcard.order.product')">
          {{ detail.product?.name || `#${detail.product_id}` }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="t('zcard.order.quantity')">{{ detail.quantity }}</ElDescriptionsItem>
        <ElDescriptionsItem :label="t('zcard.order.amount')">¥{{ formatPrice(detail.amount) }}</ElDescriptionsItem>
        <ElDescriptionsItem :label="t('zcard.order.contact')">
          {{ detail.contact || detail.email || '-' }}
        </ElDescriptionsItem>
        <ElDescriptionsItem :label="t('zcard.order.createTime')">{{ formatTime(detail.created_at) }}</ElDescriptionsItem>
        <ElDescriptionsItem :label="t('zcard.order.paidTime')">
          {{ detail.paid_at ? formatTime(detail.paid_at) : '-' }}
        </ElDescriptionsItem>
      </ElDescriptions>

      <template v-if="detail && detail.status === 'paid'">
        <div class="detail-section-title">{{ t('zcard.order.cards') }}</div>
        <ElTable
          :data="detail.deliveries || []"
          border
          stripe
          size="small"
          :empty-text="t('zcard.order.noDelivery')"
        >
          <ElTableColumn type="index" label="#" width="50" align="center" />
          <ElTableColumn prop="content" :label="t('zcard.order.cardContent')" min-width="280" show-overflow-tooltip />
          <ElTableColumn :label="t('zcard.order.deliveryTime')" width="170" align="center">
            <template #default="{ row }">{{ formatTime(row.created_at) }}</template>
          </ElTableColumn>
        </ElTable>
      </template>

      <template #footer>
        <ElButton @click="detailVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { CopyDocument } from '@element-plus/icons-vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import {
    getOrders,
    getOrder,
    closeOrder,
    type Order,
    type OrderStatus
  } from '@/api/orders'

  defineOptions({ name: 'OrderList' })

  const { t } = useI18n()

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
      pending: t('zcard.order.statusPending'),
      paid: t('zcard.order.statusPaid'),
      closed: t('zcard.order.statusClosed')
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
      ElMessage.success(t('zcard.order.copied'))
    } catch (e) {
      ElMessage.warning(t('zcard.order.copyFailed'))
    }
  }

  /** 关闭订单 */
  const handleClose = (row: Order) => {
    ElMessageBox.confirm(t('zcard.order.closeOrderConfirm', { no: row.order_no }), t('zcard.order.closeTitle'), {
      confirmButtonText: t('zcard.common.ok'),
      cancelButtonText: t('zcard.common.cancel'),
      type: 'warning'
    })
      .then(async () => {
        try {
          await closeOrder(row.id)
          ElMessage.success(t('zcard.order.closed'))
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
