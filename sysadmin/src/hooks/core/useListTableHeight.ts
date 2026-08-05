/**
 * useListTableHeight - 列表页原生 ElTable 高度自适应
 *
 * 解决:数据满页时表格内容被内容高度撑高,超出 .art-table-card 容器,
 * 被 el-card 的 overflow:hidden 裁掉分页栏,且页面被 art-full-height
 * 锁定无法滚动(表现为"看不到分页 / 无法下拉")。
 *
 * 用法(页面模板):
 *   <ElCard ref="cardRef" class="art-table-card" ...>
 *     ... 搜索栏/按钮区 ...
 *     <ElTable ref="tableRef" :height="tableHeight" ...>
 *     <div ref="paginationRef" class="pagination-bar">...</div>
 *   </ElCard>
 *   const { cardRef, tableRef, paginationRef, tableHeight } = useListTableHeight()
 *
 * 原理:表格高度 = 卡片高度 - 表格上方占用 - 分页栏高度 - 底部余量。
 * 表格高度固定后 ElTable 内部滚动,分页栏保持在卡片可视区内。
 *
 * @module hooks/core/useListTableHeight
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { unrefElement, useElementSize } from '@vueuse/core'

export function useListTableHeight() {
  const cardRef = ref<HTMLElement>()
  const tableRef = ref<HTMLElement>()
  const paginationRef = ref<HTMLElement>()

  const { height: cardHeight } = useElementSize(cardRef)
  const { height: paginationHeight } = useElementSize(paginationRef)

  /** 表格上方占用:卡片顶部到表格顶部的垂直距离(搜索栏+按钮区+内边距) */
  const tableGap = ref(0)

  const measureTableGap = () => {
    // ElTable 组件 ref 需经 unrefElement 取 $el,直接调用 getBoundingClientRect 会失败
    const card = unrefElement(cardRef)
    const table = unrefElement(tableRef)
    if (!card || !table) return
    tableGap.value = Math.max(0, table.getBoundingClientRect().top - card.getBoundingClientRect().top)
  }

  onMounted(() => nextTick(measureTableGap))

  // 卡片/分页栏尺寸变化(窗口缩放、搜索栏换行等)时重新测量
  watch([cardHeight, paginationHeight], () => nextTick(measureTableGap), { flush: 'post' })

  /** 表格高度:卡片高 - 上方占用 - 分页栏(含 margin) - 底部内边距余量 */
  const tableHeight = computed(() => {
    if (!cardHeight.value) return undefined
    return Math.max(200, cardHeight.value - tableGap.value - (paginationHeight.value || 48) - 88)
  })

  return { cardRef, tableRef, paginationRef, tableHeight }
}
