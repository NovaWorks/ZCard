<!-- 货源管理 - 对接上游供货系统(dujiao-next / acg-faka / ZCard) -->
<template>
  <div class="supply-page art-full-height">
    <ElCard ref="cardRef" class="art-table-card" shadow="never">
      <div class="toolbar">
        <div class="toolbar-left">
          <ElSelect
            v-model="filterStatus"
            :placeholder="t('zcard.supply.filterStatus')"
            clearable
            style="width: 160px"
            @change="fetchData"
          >
            <ElOption :label="t('zcard.supply.statusActive')" value="active" />
            <ElOption :label="t('zcard.supply.statusDisabled')" value="disabled" />
          </ElSelect>
          <ElButton @click="fetchData">{{ t('zcard.common.reset') }}</ElButton>
        </div>
        <div class="toolbar-right">
          <ElButton :icon="List" @click="openAllTasks">{{ t('zcard.supply.viewTasks') }}</ElButton>
          <ElButton type="primary" :icon="Plus" @click="openAdd">{{
            t('zcard.supply.add')
          }}</ElButton>
        </div>
      </div>

      <ElTable
        ref="tableRef"
        v-loading="loading"
        :data="tableData"
        :height="tableHeight"
        row-key="id"
        border
        stripe
      >
        <ElTableColumn :label="t('zcard.common.id')" prop="id" width="60" />
        <ElTableColumn
          :label="t('zcard.supply.name')"
          prop="name"
          min-width="120"
          show-overflow-tooltip
        />
        <ElTableColumn :label="t('zcard.supply.platform')" width="160">
          <template #default="{ row }">
            <ElTag :type="driverTagType(row.driver)">{{ driverLabel(row.driver) }}</ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn
          :label="t('zcard.supply.baseUrl')"
          prop="base_url"
          min-width="200"
          show-overflow-tooltip
        />
        <ElTableColumn :label="t('zcard.supply.balance')" width="120">
          <template #default="{ row }">
            <span v-if="row.balance_cache !== null">{{ formatFen(row.balance_cache) }}</span>
            <span v-else class="text-muted">—</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.supply.syncedAt')" width="170">
          <template #default="{ row }">
            <span v-if="row.last_synced_at">{{ formatTime(row.last_synced_at) }}</span>
            <span v-else class="text-muted">—</span>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.supply.status')" width="100">
          <template #default="{ row }">
            <ElTag :type="row.status === 'active' ? 'success' : 'info'">
              {{
                row.status === 'active'
                  ? t('zcard.supply.statusActive')
                  : t('zcard.supply.statusDisabled')
              }}
            </ElTag>
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.common.actions')" width="280" fixed="right">
          <template #default="{ row }">
            <ElButton text type="primary" :loading="testingId === row.id" @click="handleTest(row)">
              {{ t('zcard.supply.test') }}
            </ElButton>
            <ElButton text type="primary" :loading="syncingId === row.id" @click="handleSync(row)">
              {{ t('zcard.supply.sync') }}
            </ElButton>
            <ElButton
              text
              type="primary"
              :loading="previewingId === row.id"
              @click="openPreview(row)"
            >
              {{ t('zcard.supply.pullProducts') }}
            </ElButton>
            <ElButton text type="primary" @click="openEdit(row)">{{
              t('zcard.common.edit')
            }}</ElButton>
            <ElButton text type="danger" @click="handleDelete(row)">{{
              t('zcard.common.delete')
            }}</ElButton>
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

      <!-- 同步错误提示(若有) -->
      <ElAlert
        v-for="row in errorRows"
        :key="row.id"
        :title="`${row.name}: ${row.last_error}`"
        type="error"
        :closable="false"
        style="margin-top: 10px"
      />
    </ElCard>

    <!-- 新建/编辑货源弹窗 -->
    <ElDialog
      v-model="dialogVisible"
      :title="isEdit ? t('zcard.supply.editTitle') : t('zcard.supply.addTitle')"
      width="560px"
      destroy-on-close
    >
      <ElForm ref="formRef" :model="formData" :rules="formRules" label-width="110px">
        <ElFormItem :label="t('zcard.supply.name')" prop="name">
          <ElInput v-model="formData.name" :placeholder="t('zcard.supply.namePlaceholder')" />
        </ElFormItem>
        <ElFormItem :label="t('zcard.supply.platform')" prop="driver">
          <ElSelect
            v-model="formData.driver"
            :placeholder="t('zcard.supply.platformPlaceholder')"
            style="width: 100%"
            :disabled="isEdit"
            @change="onDriverChange"
          >
            <ElOption
              v-for="d in drivers"
              :key="d.driver"
              :label="`${d.icon || ''} ${d.name}`"
              :value="d.driver"
            />
          </ElSelect>
        </ElFormItem>

        <!-- 动态凭证字段(按所选驱动 config_schema 渲染) -->
        <template v-if="currentSchemaFields.length">
          <ElFormItem
            v-for="field in currentSchemaFields"
            :key="field.key"
            :label="field.label"
            :required="
              field.required && !(isSensitive(field.key) && isEdit && maskedSet.has(field.key))
            "
            :prop="`credentials.${field.key}`"
          >
            <ElInputNumber
              v-if="field.type === 'number'"
              v-model="formData.credentials[field.key]"
              controls-position="right"
              style="width: 100%"
            />
            <ElInput
              v-else
              v-model="formData.credentials[field.key]"
              :type="isSensitive(field.key) ? 'password' : 'text'"
              show-password
              :placeholder="credentialPlaceholder(field)"
            />
            <div v-if="field.help" class="field-help">{{ field.help }}</div>
            <div
              v-else-if="isSensitive(field.key) && isEdit && maskedSet.has(field.key)"
              class="field-help"
            >
              {{ t('zcard.supply.sensitiveKeepTip') }}
            </div>
            <div v-else-if="isSensitive(field.key)" class="field-help">{{
              t('zcard.supply.sensitiveTip')
            }}</div>
          </ElFormItem>
        </template>

        <ElFormItem :label="t('zcard.supply.status')">
          <ElSelect v-model="formData.status" style="width: 100%">
            <ElOption :label="t('zcard.supply.statusActive')" value="active" />
            <ElOption :label="t('zcard.supply.statusDisabled')" value="disabled" />
          </ElSelect>
        </ElFormItem>

        <!-- 货源设置(库存模式/发卡/失败处理/定价) -->
        <ElDivider content-position="left">{{ t('zcard.supply.settingsSection') }}</ElDivider>
        <ElFormItem :label="t('zcard.supply.stockMode')">
          <ElSelect v-model="formData.settings.stock_mode" style="width: 100%">
            <ElOption :label="t('zcard.supply.stockModeSynced')" value="synced" />
            <ElOption :label="t('zcard.supply.stockModeRealtime')" value="realtime" />
          </ElSelect>
          <div class="field-help">{{
            formData.settings.stock_mode === 'realtime'
              ? t('zcard.supply.stockModeRealtimeTip')
              : t('zcard.supply.stockModeSyncedTip')
          }}</div>
        </ElFormItem>
        <ElFormItem :label="t('zcard.supply.syncPublicDescription')">
          <ElSwitch v-model="formData.settings.sync_public_description" />
          <div class="field-help">{{ t('zcard.supply.syncPublicDescriptionTip') }}</div>
        </ElFormItem>
        <ElFormItem
          v-if="formData.driver === 'dujiao_next'"
          :label="t('zcard.supply.contentLocale')"
        >
          <ElSelect v-model="formData.settings.content_locale" style="width: 100%">
            <ElOption label="简体中文 (zh-CN)" value="zh-CN" />
            <ElOption label="繁體中文 (zh-TW)" value="zh-TW" />
            <ElOption label="English (en-US)" value="en-US" />
          </ElSelect>
          <div class="field-help">{{ t('zcard.supply.contentLocaleTip') }}</div>
        </ElFormItem>
        <ElFormItem :label="t('zcard.supply.fulfillmentMode')">
          <ElSelect v-model="formData.settings.fulfillment_mode" style="width: 100%">
            <ElOption :label="t('zcard.supply.fulfillmentSync')" value="sync" />
            <ElOption :label="t('zcard.supply.fulfillmentAsync')" value="async" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="t('zcard.supply.failureAction')">
          <ElSelect v-model="formData.settings.failure_action" style="width: 100%">
            <ElOption :label="t('zcard.supply.failureManual')" value="manual" />
            <ElOption :label="t('zcard.supply.failureAutoRefund')" value="auto_refund" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem :label="t('zcard.supply.pricingMode')">
          <ElSelect v-model="formData.settings.default_pricing_mode" style="width: 100%">
            <ElOption :label="t('zcard.supply.pricingPercent')" value="percent" />
            <ElOption :label="t('zcard.supply.pricingFixed')" value="fixed" />
            <ElOption :label="t('zcard.supply.pricingEqual')" value="equal" />
            <ElOption :label="t('zcard.supply.pricingPending')" value="pending" />
          </ElSelect>
        </ElFormItem>
        <ElFormItem
          v-if="formData.settings.default_pricing_mode === 'percent'"
          :label="t('zcard.supply.markupPercent')"
        >
          <div class="input-with-unit">
            <ElInputNumber
              v-model="formData.settings.default_markup_percent"
              :min="0"
              :precision="0"
              controls-position="right"
            />
            <span class="unit">%</span>
          </div>
        </ElFormItem>
        <ElFormItem
          v-if="formData.settings.default_pricing_mode === 'fixed'"
          :label="t('zcard.supply.markupAmount')"
        >
          <div class="input-with-unit">
            <ElInputNumber
              v-model="formData.settings.default_markup_amount"
              :min="0"
              :precision="2"
              controls-position="right"
            />
            <span class="unit">{{ t('zcard.supplierAccount.yuan') }}</span>
          </div>
        </ElFormItem>
        <ElFormItem :label="t('zcard.supply.autoList')">
          <ElSwitch v-model="formData.settings.auto_list" />
          <div class="field-help">{{ t('zcard.supply.autoListTip') }}</div>
        </ElFormItem>
        <ElFormItem :label="t('zcard.supply.autoSyncPrice')">
          <ElSwitch v-model="formData.settings.auto_sync_price" />
          <div class="field-help">{{ t('zcard.supply.autoSyncPriceTip') }}</div>
        </ElFormItem>
      </ElForm>
      <template #footer>
        <ElButton @click="dialogVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="saving" @click="handleSubmit">{{
          t('zcard.common.ok')
        }}</ElButton>
      </template>
    </ElDialog>

    <!-- 全部货源同步任务弹窗(含队列状态检测) -->
    <ElDialog
      v-model="allTasksVisible"
      :title="t('zcard.supply.viewTasksTitle')"
      width="720px"
      top="6vh"
      destroy-on-close
      @closed="stopAllTasksPolling"
    >
      <!-- 队列状态横幅 -->
      <div v-if="queueChecked" class="queue-banner" :class="queueHealthy ? 'queue-ok' : 'queue-down'">
        <template v-if="queueHealthy">
          <ArtSvgIcon icon="ri:checkbox-circle-line" class="queue-icon" />
          <span>{{ t('zcard.supply.queueOk', { conn: queueConnection }) }}</span>
        </template>
        <template v-else>
          <ArtSvgIcon icon="ri:alert-line" class="queue-icon" />
          <div class="queue-down-body">
            <div class="queue-down-title">{{ t('zcard.supply.queueDown') }}</div>
            <div class="queue-down-tip">{{ t('zcard.supply.queueDownTip') }}</div>
            <pre class="queue-cmd">php artisan queue:work</pre>
            <div class="queue-down-help">{{ t('zcard.supply.queueDownHelp') }}</div>
          </div>
        </template>
      </div>
      <div v-else class="queue-banner queue-checking">
        <span>{{ t('zcard.supply.queueChecking') }}</span>
      </div>

      <!-- 任务列表 -->
      <div v-loading="allTasksLoading" class="all-tasks">
        <div v-if="!allTasks.length" class="task-empty">{{ t('zcard.supply.taskEmpty') }}</div>
        <div v-for="task in allTasks" :key="task.id" class="all-task-row">
          <div class="all-task-main">
            <div class="all-task-source">{{ task.source_name }}</div>
            <ElTag :type="taskStatusTag(task.status)" size="small">{{ taskStatusText(task.status) }}</ElTag>
            <span class="task-mode">{{ task.mode === 'full' ? t('zcard.supply.taskModeFull') : t('zcard.supply.taskModeInc') }}</span>
          </div>
          <div class="all-task-progress" v-if="task.total_products > 0">
            <ElProgress :percentage="taskProgress(task)" :stroke-width="10" />
            <span class="all-task-counts">
              {{ t('zcard.supply.taskProcessed', { n: task.processed_products, total: task.total_products }) }}
              · +{{ task.created_count }} {{ t('zcard.supply.taskCreated') }}
            </span>
          </div>
          <div v-else-if="task.status === 'queued' || task.status === 'running'" class="all-task-waiting">
            <span class="all-task-counts">{{ t('zcard.supply.taskProcessed', { n: task.processed_products, total: task.total_products }) }}</span>
          </div>
          <div v-if="task.error" class="task-error">{{ task.error }}</div>
          <div class="all-task-meta">
            <span v-if="task.status === 'success'" class="task-done">{{ t('zcard.supply.taskDone', { t: formatTime(task.finished_at) }) }}</span>
            <span v-else-if="['failed', 'cancelled'].includes(task.status)" class="task-history-time">{{ formatTime(task.finished_at) }}</span>
            <span v-else class="task-history-time">{{ formatTime(task.created_at) }}</span>
          </div>
        </div>
      </div>
    </ElDialog>

    <!-- 同步任务弹窗(异步入库,轮询进度/支持取消/重新同步) -->
    <ElDialog
      v-model="taskDialogVisible"
      :title="t('zcard.supply.taskTitle')"
      width="620px"
      destroy-on-close
      @closed="stopTaskPolling"
    >
      <div v-if="taskSource">
        <div class="task-source-name">{{ taskSource.name }}</div>

        <!-- 当前/最近任务状态卡片 -->
        <div v-if="activeTask" class="task-card" :class="'task-' + activeTask.status">
          <div class="task-row">
            <span class="task-label">{{ t('zcard.supply.taskStatus') }}</span>
            <ElTag :type="taskStatusTag(activeTask.status)" size="small">{{ taskStatusText(activeTask.status) }}</ElTag>
            <span class="task-mode">{{ activeTask.mode === 'full' ? t('zcard.supply.taskModeFull') : t('zcard.supply.taskModeInc') }}</span>
          </div>

          <!-- 进度条 -->
          <div class="task-progress" v-if="activeTask.status === 'running' || activeTask.status === 'queued'">
            <ElProgress
              :percentage="taskProgress(activeTask)"
              :indeterminate="activeTask.total_products === 0"
              :stroke-width="14"
              :text-inside="true"
            />
            <div class="task-progress-text">
              {{ t('zcard.supply.taskProcessed', { n: activeTask.processed_products, total: activeTask.total_products }) }}
            </div>
          </div>

          <!-- 计数 -->
          <div class="task-counts" v-if="activeTask.processed_products > 0 || ['success', 'failed', 'cancelled'].includes(activeTask.status)">
            <span class="task-count created">+{{ activeTask.created_count }} {{ t('zcard.supply.taskCreated') }}</span>
            <span class="task-count updated">{{ activeTask.updated_count }} {{ t('zcard.supply.taskUpdated') }}</span>
            <span class="task-count hidden" v-if="activeTask.hidden_count">{{ activeTask.hidden_count }} {{ t('zcard.supply.taskHidden') }}</span>
          </div>

          <div v-if="activeTask.error" class="task-error">{{ activeTask.error }}</div>
          <div v-else-if="activeTask.status === 'success'" class="task-done">{{ t('zcard.supply.taskDone', { t: formatTime(activeTask.finished_at) }) }}</div>

          <!-- 操作按钮 -->
          <div class="task-actions">
            <ElButton v-if="['queued', 'running'].includes(activeTask.status)" size="small" type="danger" plain :loading="cancelling" @click="handleCancelTask">
              {{ t('zcard.supply.taskCancel') }}
            </ElButton>
            <ElButton v-if="['success', 'failed', 'cancelled'].includes(activeTask.status)" size="small" type="primary" :loading="syncingId === taskSource.id" @click="handleResync(taskSource)">
              {{ t('zcard.supply.taskResync') }}
            </ElButton>
          </div>
        </div>

        <div v-else class="task-empty">{{ t('zcard.supply.taskEmpty') }}</div>

        <!-- 历史任务 -->
        <div v-if="taskHistory.length" class="task-history">
          <div class="task-history-title">{{ t('zcard.supply.taskHistory') }}</div>
          <div v-for="task in taskHistory" :key="task.id" class="task-history-row">
            <ElTag :type="taskStatusTag(task.status)" size="small">{{ taskStatusText(task.status) }}</ElTag>
            <span class="task-history-info">
              {{ t('zcard.supply.taskProcessed', { n: task.processed_products, total: task.total_products }) }}
              · +{{ task.created_count }} {{ t('zcard.supply.taskCreated') }}
            </span>
            <span class="task-history-time">{{ formatTime(task.finished_at || task.created_at) }}</span>
          </div>
        </div>
      </div>
    </ElDialog>

    <!-- 拉取/勾选导入商品弹窗 -->
    <ElDialog
      v-model="previewVisible"
      :title="t('zcard.supply.previewTitle')"
      width="880px"
      top="4vh"
      destroy-on-close
    >
      <div v-loading="previewLoading" class="preview-wrap">
        <div v-if="previewError" class="preview-error">{{ previewError }}</div>
        <template v-else>
          <div class="preview-toolbar">
            <span class="preview-summary">
              {{
                t('zcard.supply.previewSummary', {
                  total: previewTotal,
                  selected: selectedCodes.size
                })
              }}
              <span class="summary-imported">{{
                t('zcard.supply.previewImported', { n: importedCount })
              }}</span>
            </span>
            <div class="toolbar-check">
              <ElCheckbox
                v-model="checkAll"
                :indeterminate="isIndeterminate"
                @change="handleCheckAll"
              >
                {{ t('zcard.supply.selectAll') }}
              </ElCheckbox>
              <ElButton
                v-if="newProductCodes.length > 0"
                type="primary"
                link
                :disabled="newProductCodes.length === 0"
                @click="handleSelectNew"
              >
                {{ t('zcard.supply.selectNewOnly', { n: newProductCodes.length }) }}
              </ElButton>
            </div>
          </div>

          <!-- 定价策略:实时计算导入售价 -->
          <div class="preview-pricing">
            <div class="pricing-row">
              <span class="pricing-label">{{ t('zcard.supply.pricingStrategy') }}</span>
              <ElRadioGroup v-model="pricingMode" class="pricing-modes">
                <ElRadio value="percent">{{ t('zcard.supply.pricingPercent') }}</ElRadio>
                <ElRadio value="fixed">{{ t('zcard.supply.pricingFixed') }}</ElRadio>
                <ElRadio value="equal">{{ t('zcard.supply.pricingEqual') }}</ElRadio>
                <ElRadio value="pending">{{ t('zcard.supply.pricingPending') }}</ElRadio>
              </ElRadioGroup>
              <template v-if="pricingMode === 'percent'">
                <div class="input-with-unit pricing-param">
                  <ElInputNumber
                    v-model="markupPercent"
                    :min="0"
                    :precision="0"
                    controls-position="right"
                    size="small"
                    style="width: 90px"
                  />
                  <span class="unit">%</span>
                </div>
              </template>
              <template v-if="pricingMode === 'fixed'">
                <div class="input-with-unit pricing-param">
                  <ElInputNumber
                    v-model="markupAmountYuan"
                    :min="0"
                    :precision="2"
                    :step="0.5"
                    controls-position="right"
                    size="small"
                    style="width: 110px"
                  />
                  <span class="unit">{{ t('zcard.supplierAccount.yuan') }}</span>
                </div>
              </template>
            </div>
            <div class="pricing-row pricing-actions">
              <ElCheckbox v-model="saveDefaultPricing" class="pricing-save">
                {{ t('zcard.supply.saveDefaultPricing') }}
              </ElCheckbox>
            </div>
          </div>

          <!-- 分类映射:上游分类 → 本地分类 -->
          <div class="preview-map">
            <div class="map-head">
              <span class="map-title">{{ t('zcard.supply.categoryMapTitle') }}</span>
              <ElButton
                v-if="unmappedCount > 0"
                type="primary"
                link
                :loading="creatingMappings"
                @click="createAllMissingCategories"
              >
                {{ t('zcard.supply.categoryMapCreateAll') }}
              </ElButton>
            </div>
            <div class="map-list">
              <div
                v-for="cat in previewCategories"
                :key="'map-' + (cat.category_code ?? '_')"
                class="map-row"
              >
                <span class="map-upstream">
                  {{ cat.category_name }}
                  <span class="map-count">({{ cat.products.length }})</span>
                </span>
                <span class="map-arrow">→</span>
                <div class="map-target">
                  <ElTreeSelect
                    v-model="categoryMapping[cat.category_code as string]"
                    :data="localCategories"
                    :props="{ label: 'name', value: 'id', children: 'children' }"
                    node-key="id"
                    check-strictly
                    clearable
                    filterable
                    :placeholder="t('zcard.supply.categoryMapPlaceholder')"
                    class="map-select"
                  />
                  <ElButton
                    v-if="cat.category_code"
                    link
                    type="primary"
                    @click="createCategoryForUpstream(cat)"
                  >
                    {{ t('zcard.supply.categoryMapCreate') }}
                  </ElButton>
                  <ElTag
                    v-if="categoryMapping[cat.category_code as string]"
                    type="success"
                    size="small"
                    effect="plain"
                    class="map-tag"
                  >
                    {{ mappedName(cat.category_code as string) }}
                  </ElTag>
                </div>
              </div>
              <div v-if="previewCategories.length === 0" class="map-empty">{{
                t('zcard.supply.noProducts')
              }}</div>
            </div>
            <div class="map-tip">{{ t('zcard.supply.categoryMapTip') }}</div>
          </div>

          <div class="preview-list">
            <div
              v-for="cat in previewCategories"
              :key="cat.category_code ?? '_'"
              class="preview-cat"
            >
              <div class="preview-cat-head" @click="toggleCategoryExpand(cat)">
                <ElCheckbox
                  :model-value="isCategoryAllChecked(cat)"
                  :indeterminate="isCategoryIndeterminate(cat)"
                  class="cat-check"
                  @change="(val: any) => toggleCategory(cat, !!val)"
                  @click.stop
                >
                  <span class="cat-title">{{ cat.category_name }}</span>
                </ElCheckbox>
                <div class="cat-right">
                  <span class="cat-count">{{
                    t('zcard.supply.catProductCount', { n: cat.products.length })
                  }}</span>
                  <ElIcon class="cat-arrow" :class="{ expanded: isCategoryExpanded(cat) }">
                    <ArrowDown />
                  </ElIcon>
                </div>
              </div>
              <ElCheckboxGroup
                v-show="isCategoryExpanded(cat)"
                v-model="previewChecked"
                class="preview-cat-body"
              >
                <div
                  v-for="p in cat.products"
                  :key="p.code"
                  class="preview-product"
                  :class="{ 'is-imported': p.already_imported }"
                >
                  <ElCheckbox :value="p.code">
                    <div class="pp-content">
                      <span class="pp-name">
                        <ElTag
                          v-if="p.already_imported"
                          size="small"
                          type="success"
                          effect="light"
                          round
                          class="pp-status"
                        >
                          {{ t('zcard.supply.imported') }}
                        </ElTag>
                        <ElTag
                          v-else
                          size="small"
                          type="primary"
                          effect="plain"
                          round
                          class="pp-status"
                        >
                          {{ t('zcard.supply.notImported') }}
                        </ElTag>
                        {{ p.name }}
                      </span>
                      <span class="pp-meta">
                        <span class="pp-base">¥{{ (upstreamPrice(p) / 100).toFixed(2) }}</span>
                        <span class="pp-arrow">→</span>
                        <span v-if="calcPrice(p) !== null" class="pp-price"
                          >¥{{ ((calcPrice(p) ?? 0) / 100).toFixed(2) }}</span
                        >
                        <span v-else class="pp-price pending">{{
                          t('zcard.supply.pricingPendingTip')
                        }}</span>
                      </span>
                    </div>
                  </ElCheckbox>
                </div>
              </ElCheckboxGroup>
            </div>
            <div v-if="previewCategories.length === 0" class="preview-empty">{{
              t('zcard.supply.noProducts')
            }}</div>
          </div>
        </template>
      </div>
      <template #footer>
        <ElButton @click="previewVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton
          type="primary"
          :loading="importing"
          :disabled="previewChecked.length === 0"
          @click="handleImport"
        >
          {{ t('zcard.supply.importSelected', { n: previewChecked.length }) }}
        </ElButton>
      </template>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { Plus, List, ArrowDown } from '@element-plus/icons-vue'
  import ArtSvgIcon from '@/components/core/base/art-svg-icon/index.vue'
  import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import { useListTableHeight } from '@/hooks'
  import { getAllCategories, createCategory, type Category } from '@/api/categories'
  import {
    getSupplyDrivers,
    getSupplySources,
    createSupplySource,
    updateSupplySource,
    deleteSupplySource,
    testSupplySource,
    syncSupplySource,
    getSupplySyncTasks,
    cancelSupplySync,
    getAllSyncTasks,
    probeSyncQueue,
    getSyncQueueStatus,
    type SupplySyncTaskWithSource,
    type SupplySyncTask,
    previewSupplyProducts,
    importSupplyProducts,
    type SupplySource,
    type SupplyDriver,
    type UpstreamCategory
  } from '@/api/supply'

  defineOptions({ name: 'SupplySourceList' })

  const { t } = useI18n()

  /** 列表 + 分页 */
  const loading = ref(false)
  const tableData = ref<SupplySource[]>([])
  const filterStatus = ref('')
  const pagination = reactive({ page: 1, pageSize: 15, total: 0 })
  // 表格高度自适应:数据满页时表格内容撑高会被卡片裁掉分页栏,固定表格高度使其内部滚动
  const { cardRef, tableRef, paginationRef, tableHeight } = useListTableHeight()
  /** 列表里有 last_error 的行(展示告警) */
  const errorRows = computed(() => tableData.value.filter((r) => r.last_error))

  const fetchData = async () => {
    loading.value = true
    try {
      const res = await getSupplySources({
        page: pagination.page,
        per_page: pagination.pageSize,
        status: filterStatus.value || undefined
      })
      tableData.value = res.data || []
      pagination.total = res.total || 0
    } catch {
      tableData.value = []
    } finally {
      loading.value = false
    }
  }

  /** 驱动元数据(选平台 + 动态凭证字段) */
  const drivers = ref<SupplyDriver[]>([])

  const fetchDrivers = async () => {
    try {
      const res = await getSupplyDrivers()
      drivers.value = res.drivers || []
    } catch {
      drivers.value = []
    }
  }

  const driverLabel = (key: string) => {
    const d = drivers.value.find((x) => x.driver === key)
    return d ? `${d.icon || ''} ${d.name}`.trim() : key
  }
  const driverTagType = (key: string): 'primary' | 'success' | 'warning' => {
    if (key === 'dujiao_next') return 'primary'
    if (key === 'acg_faka') return 'warning'
    return 'success'
  }

  /** 弹窗 + 表单 */
  const dialogVisible = ref(false)
  const saving = ref(false)
  const isEdit = ref(false)
  const editingId = ref<number | null>(null)
  const formRef = ref<FormInstance>()
  /** 编辑时哪些敏感字段已被设过(脱敏值,留空=不改) */
  const maskedSet = ref<Set<string>>(new Set())

  const defaultSettings = () => ({
    stock_mode: 'synced',
    fulfillment_mode: 'sync',
    failure_action: 'manual',
    default_pricing_mode: 'percent',
    default_markup_percent: 10,
    default_markup_amount: 0,
    auto_list: true,
    auto_sync_price: true,
    sync_public_description: true,
    content_locale: 'zh-CN'
  })
  const defaultForm = () => ({
    name: '',
    driver: '' as string,
    status: 'active' as 'active' | 'disabled',
    credentials: {} as Record<string, any>,
    settings: defaultSettings()
  })
  const formData = reactive(defaultForm())

  const formRules = computed<FormRules>(() => ({
    name: [{ required: true, message: t('zcard.supply.nameRequired'), trigger: 'blur' }],
    driver: [{ required: true, message: t('zcard.supply.platformRequired'), trigger: 'change' }]
  }))

  /** 当前所选驱动的凭证字段(从 config_schema 规范化成数组) */
  const currentSchemaFields = computed(() => {
    const d = drivers.value.find((x) => x.driver === formData.driver)
    if (!d) return []
    return Object.entries(d.config_schema || {}).map(([key, def]) => ({
      key,
      label: def.label || key,
      type: (def.type || 'text') as 'text' | 'number' | 'url' | 'secret',
      required: def.required ?? false,
      help: def.help
    }))
  })

  /** 敏感字段(不回显,留空=保持原值) */
  const isSensitive = (key: string) => /(secret|app_key|key|token|password)/i.test(key)

  const credentialPlaceholder = (field: { key: string; type: string }) => {
    if (isSensitive(field.key)) return t('zcard.supply.sensitivePlaceholder')
    return ''
  }

  /** 选平台切换时,重置凭证字段 */
  const onDriverChange = () => {
    const obj: Record<string, any> = {}
    currentSchemaFields.value.forEach((f) => {
      obj[f.key] = f.type === 'number' ? null : ''
    })
    formData.credentials = obj
    maskedSet.value = new Set()
  }

  /** 新建 */
  const openAdd = () => {
    isEdit.value = false
    editingId.value = null
    Object.assign(formData, defaultForm())
    maskedSet.value = new Set()
    dialogVisible.value = true
    nextTick(() => formRef.value?.clearValidate())
  }

  /** 编辑:回填(凭证脱敏值不回填,标记 maskedSet) */
  const openEdit = (row: SupplySource) => {
    isEdit.value = true
    editingId.value = row.id
    formData.name = row.name
    formData.driver = row.driver
    formData.status = row.status
    const obj: Record<string, any> = {}
    const masked = new Set<string>()
    currentSchemaFields.value.forEach((f) => {
      const v = row.credentials?.[f.key]
      // 脱敏值(以 •• 开头)视为已设过,留空不改
      if (typeof v === 'string' && v.startsWith('••••')) {
        obj[f.key] = ''
        if (isSensitive(f.key)) masked.add(f.key)
      } else {
        obj[f.key] = v ?? (f.type === 'number' ? null : '')
      }
    })
    formData.credentials = obj
    maskedSet.value = masked
    // 回填货源设置(合并默认值)
    const rs = row.settings || {}
    formData.settings = {
      stock_mode: rs.stock_mode || 'synced',
      fulfillment_mode: rs.fulfillment_mode || 'sync',
      failure_action: rs.failure_action || 'manual',
      default_pricing_mode: rs.default_pricing_mode || 'percent',
      default_markup_percent: rs.default_markup_percent ?? 10,
      auto_sync_price: rs.auto_sync_price ?? true,
      default_markup_amount: rs.default_markup_amount ?? 0,
      auto_list: rs.auto_list ?? true,
      sync_public_description: rs.sync_public_description ?? true,
      content_locale: rs.content_locale || 'zh-CN'
    }
    dialogVisible.value = true
    nextTick(() => formRef.value?.clearValidate())
  }

  /** 提交(新建/编辑) */
  const handleSubmit = async () => {
    if (!formRef.value) return
    try {
      await formRef.value.validate()
    } catch {
      return
    }
    // 构造 credentials:敏感字段留空的不传(编辑时保持原值)
    const creds: Record<string, any> = {}
    currentSchemaFields.value.forEach((f) => {
      const val = formData.credentials[f.key]
      if (isSensitive(f.key) && (val === '' || val === null || val === undefined)) return
      creds[f.key] = val
    })

    saving.value = true
    try {
      const payload = {
        name: formData.name,
        driver: formData.driver as SupplySource['driver'],
        base_url: creds.base_url || '',
        credentials: creds,
        status: formData.status,
        settings: formData.settings
      }
      if (isEdit.value && editingId.value !== null) {
        await updateSupplySource(editingId.value, payload)
        ElMessage.success(t('zcard.supply.modified'))
      } else {
        await createSupplySource(payload)
        ElMessage.success(t('zcard.supply.created'))
      }
      dialogVisible.value = false
      fetchData()
    } catch {
      // 拦截器已提示
    } finally {
      saving.value = false
    }
  }

  /** 删除 */
  const handleDelete = (row: SupplySource) => {
    ElMessageBox.confirm(
      t('zcard.supply.deleteConfirm', { name: row.name }),
      t('zcard.common.tips'),
      { type: 'warning' }
    )
      .then(async () => {
        try {
          await deleteSupplySource(row.id)
          ElMessage.success(t('zcard.common.deleteSuccess'))
          fetchData()
        } catch {
          // 拦截器已提示
        }
      })
      .catch(() => {})
  }

  /** 测试连通 */
  const testingId = ref<number | null>(null)
  const handleTest = async (row: SupplySource) => {
    testingId.value = row.id
    try {
      const res = await testSupplySource(row.id)
      if (res.connected) {
        ElMessage.success(
          t('zcard.supply.testSuccess', {
            balance:
              res.balance !== null && res.balance !== undefined ? formatFen(res.balance) : '—'
          })
        )
      } else {
        ElMessage.error(t('zcard.supply.testFailed', { error: res.error || '' }))
      }
      fetchData()
    } catch {
      // 拦截器已提示
    } finally {
      testingId.value = null
    }
  }

  /** 触发同步(异步任务,弹窗轮询进度) */
  const syncingId = ref<number | null>(null)
  const handleSync = async (row: SupplySource) => {
    syncingId.value = row.id
    try {
      await syncSupplySource(row.id, 'incremental')
      openTaskDialog(row)
      ElMessage.success(t('zcard.supply.syncDispatched'))
      fetchData()
    } catch {
      // 拦截器已提示
    } finally {
      syncingId.value = null
    }
  }

  /** ===== 同步任务弹窗(异步任务状态/进度/取消/重新同步) ===== */
  const taskDialogVisible = ref(false)
  const taskSource = ref<SupplySource | null>(null)
  const tasks = ref<SupplySyncTask[]>([])
  const cancelling = ref(false)
  let taskPollTimer: ReturnType<typeof setInterval> | null = null

  const activeTask = computed(() => tasks.value[0] || null)
  const taskHistory = computed(() => tasks.value.slice(1))

  const openTaskDialog = (row: SupplySource) => {
    taskSource.value = row
    taskDialogVisible.value = true
    loadTasks(row.id)
    startTaskPolling(row.id)
  }

  const loadTasks = async (id: number) => {
    try {
      const data = await getSupplySyncTasks(id)
      tasks.value = data.tasks || []
    } catch {
      // 拦截器已提示
    }
  }

  /** 轮询:仅进行中任务时持续拉取 */
  const startTaskPolling = (id: number) => {
    stopTaskPolling()
    taskPollTimer = setInterval(() => {
      const t = tasks.value[0]
      if (t && ['queued', 'running'].includes(t.status)) {
        loadTasks(id)
      } else if (taskPollTimer) {
        stopTaskPolling()
      }
    }, 3000)
  }

  const stopTaskPolling = () => {
    if (taskPollTimer) {
      clearInterval(taskPollTimer)
      taskPollTimer = null
    }
  }

  const handleCancelTask = async () => {
    if (!taskSource.value) return
    cancelling.value = true
    try {
      await cancelSupplySync(taskSource.value.id)
      await loadTasks(taskSource.value.id)
      startTaskPolling(taskSource.value.id)
      ElMessage.success(t('zcard.supply.taskCancelled'))
    } catch {
      // 拦截器已提示
    } finally {
      cancelling.value = false
    }
  }

  /** 重新同步(结束后按钮):换新任务并打开弹窗轮询 */
  const handleResync = async (row: SupplySource) => {
    await handleSync(row)
  }

  /** ===== 全部货源任务弹窗 + 队列状态检测 ===== */
  const allTasksVisible = ref(false)
  const allTasksLoading = ref(false)
  const allTasks = ref<SupplySyncTaskWithSource[]>([])
  const queueChecked = ref(false)
  const queueHealthy = ref(false)
  const queueConnection = ref('')
  let allTasksTimer: ReturnType<typeof setInterval> | null = null

  const openAllTasks = () => {
    allTasksVisible.value = true
    queueChecked.value = false
    loadAllTasks()
    checkQueue()
    startAllTasksPolling()
  }

  const loadAllTasks = async () => {
    allTasksLoading.value = true
    try {
      const data = await getAllSyncTasks({ limit: 50 })
      allTasks.value = data.tasks || []
    } catch {
      // 拦截器已提示
    } finally {
      allTasksLoading.value = false
    }
  }

  /** 队列检测:派发探针 → 拉取心跳;worker 正常时心跳在 20 秒内 */
  const checkQueue = async () => {
    try {
      await probeSyncQueue()
      const st = await getSyncQueueStatus()
      queueHealthy.value = !!st.healthy
      queueConnection.value = st.connection || ''
      queueChecked.value = true
    } catch {
      queueHealthy.value = false
      queueChecked.value = true
    }
  }

  const startAllTasksPolling = () => {
    stopAllTasksPolling()
    allTasksTimer = setInterval(() => {
      loadAllTasks()
      const running = allTasks.value.some((t) => ['queued', 'running'].includes(t.status))
      if (!running && queueChecked.value) {
        // 无进行中任务且已检测过队列 → 仍每 15 秒刷新队列状态,其余静默
      }
      // 队列心跳持续刷新(worker 正常时每次 probe 会更新)
      probeSyncQueue().then(() => getSyncQueueStatus()).then((st) => {
        queueHealthy.value = !!st.healthy
        queueChecked.value = true
      }).catch(() => {})
    }, 5000)
  }

  const stopAllTasksPolling = () => {
    if (allTasksTimer) {
      clearInterval(allTasksTimer)
      allTasksTimer = null
    }
  }

  const taskProgress = (t: SupplySyncTask): number => {
    if (!t.total_products) return 0
    return Math.min(100, Math.round((t.processed_products / t.total_products) * 100))
  }

  const taskStatusTag = (s: string): 'primary' | 'success' | 'danger' | 'warning' | 'info' => {
    if (s === 'success') return 'success'
    if (s === 'failed') return 'danger'
    if (s === 'running') return 'primary'
    if (s === 'cancelled') return 'warning'
    return 'info'
  }

  const taskStatusText = (s: string): string => {
    const map: Record<string, string> = {
      queued: t('zcard.supply.taskQueued'),
      running: t('zcard.supply.taskRunning'),
      success: t('zcard.supply.taskSuccess'),
      failed: t('zcard.supply.taskFailed'),
      cancelled: t('zcard.supply.taskCancelledStatus'),
    }
    return map[s] || s
  }

  /** ===== 拉取商品 + 勾选导入 ===== */
  const previewingId = ref<number | null>(null)
  const previewVisible = ref(false)
  const previewLoading = ref(false)
  const previewError = ref('')
  const previewCategories = ref<UpstreamCategory[]>([])
  const previewTotal = ref(0)
  /** 当前勾选的商品 code 列表(ElCheckboxGroup v-model) */
  const previewChecked = ref<string[]>([])
  const importing = ref(false)
  /** 当前操作的货源 id(导入时用) */
  const previewSourceId = ref<number | null>(null)

  /** 全部商品 code 集合(用于全选) */
  const allProductCodes = computed(() =>
    previewCategories.value.flatMap((c) => c.products.map((p) => p.code))
  )
  /** 新货源(未导入本地)的商品 code 集合 —— 二次导入时一键勾选 */
  const newProductCodes = computed(() =>
    previewCategories.value.flatMap((c) =>
      c.products.filter((p) => !p.already_imported).map((p) => p.code)
    )
  )
  /** 已勾选 + 全部 → 计算全选/半选态 */
  const checkAll = computed({
    get: () =>
      allProductCodes.value.length > 0 &&
      previewChecked.value.length === allProductCodes.value.length,
    set: () => {} // 由 handleCheckAll 处理
  })
  const isIndeterminate = computed(
    () =>
      previewChecked.value.length > 0 && previewChecked.value.length < allProductCodes.value.length
  )
  /** selectedCodes 别名(模板用) */
  const selectedCodes = computed(() => new Set(previewChecked.value))

  /** 已对接(已导入本地)的商品数 */
  const importedCount = computed(() =>
    previewCategories.value.reduce(
      (n, c) => n + c.products.filter((p) => p.already_imported).length,
      0
    )
  )

  const handleCheckAll = (val: any) => {
    previewChecked.value = val ? [...allProductCodes.value] : []
  }

  /** 一键全选新货源(未导入商品),已导入的保持不勾选 */
  const handleSelectNew = () => {
    previewChecked.value = [...newProductCodes.value]
  }

  /** ===== 定价策略(勾选导入时实时预览售价) ===== */
  const pricingMode = ref<'percent' | 'fixed' | 'equal' | 'pending'>('percent')
  const markupPercent = ref(10)
  const markupAmountYuan = ref(0)
  const saveDefaultPricing = ref(false)

  /** 上游参考售价(分):预览展示,与上游页面价格一致 */
  const upstreamPrice = (p: UpstreamCategory['products'][number]): number => p.price ?? 0

  /** 加价基准 = 上游售价优先(v1.12.71 起后端同为该口径),为 0 时回退成本价 */
  const costBase = (p: UpstreamCategory['products'][number]): number =>
    (p.price ?? 0) > 0 ? p.price : (p.factory_price ?? 0)

  /** 按所选策略实时计算售价(分);pending 返回 null(待审) */
  const calcPrice = (p: UpstreamCategory['products'][number]): number | null => {
    const base = costBase(p)
    if (pricingMode.value === 'percent') return Math.round(base * (1 + markupPercent.value / 100))
    if (pricingMode.value === 'fixed') return base + Math.round(markupAmountYuan.value * 100)
    if (pricingMode.value === 'equal') return base
    return null
  }

  /** 打开弹窗时从货源设置预填定价策略 */
  const initPricingFromSource = (row: SupplySource) => {
    const s = row.settings || {}
    pricingMode.value = (s.default_pricing_mode as typeof pricingMode.value) || 'percent'
    markupPercent.value = s.default_markup_percent ?? 10
    markupAmountYuan.value = Number(s.default_markup_amount) || 0
    saveDefaultPricing.value = false
  }

  /** ===== 分类映射:上游分类 → 本地分类 ===== */
  /** 本地分类树(打开弹窗时加载) */
  const localCategories = ref<Category[]>([])
  /** 上游分类 code → 本地分类 id(未映射为 null) */
  const categoryMapping = ref<Record<string, number | null>>({})
  /** 一键创建中 */
  const creatingMappings = ref(false)

  const loadLocalCategories = async () => {
    try {
      localCategories.value = (await getAllCategories()) || []
    } catch {
      localCategories.value = []
    }
  }

  /** 未映射的上游分类(供一键创建 + 统计) */
  const unmappedCategories = computed(() =>
    previewCategories.value.filter(
      (c) =>
        (c.category_code ?? '_uncategorized') !== '_uncategorized' &&
        !categoryMapping.value[c.category_code as string]
    )
  )
  const unmappedCount = computed(() => unmappedCategories.value.length)

  /** 单个上游分类:一键创建同名本地分类并映射 */
  const createCategoryForUpstream = async (cat: UpstreamCategory) => {
    const key = cat.category_code as string
    if (!key) return
    // 先查是否已有同名本地分类
    const existing = localCategories.value.find((c) => c.name === cat.category_name)
    if (existing) {
      categoryMapping.value = { ...categoryMapping.value, [key]: existing.id }
      ElMessage.success(t('zcard.supply.categoryMapped', { name: cat.category_name }))
      return
    }
    try {
      const created = await createCategory({ name: cat.category_name, status: true } as any)
      categoryMapping.value = { ...categoryMapping.value, [key]: created.id }
      await loadLocalCategories()
      ElMessage.success(t('zcard.supply.categoryCreated', { name: cat.category_name }))
    } catch {
      // 拦截器已提示
    }
  }

  /** 一键为所有未映射上游分类创建同名本地分类 */
  const createAllMissingCategories = async () => {
    if (unmappedCount.value === 0) return
    creatingMappings.value = true
    try {
      for (const cat of unmappedCategories.value) {
        await createCategoryForUpstream(cat)
      }
    } finally {
      creatingMappings.value = false
    }
  }

  /** 映射到的本地分类名(扁平化树查找) */
  const mappedName = (code: string): string => {
    const id = categoryMapping.value[code]
    if (!id) return ''
    const find = (nodes: Category[]): string => {
      for (const n of nodes || []) {
        if (n.id === id) return n.name
        const child = find(n.children || [])
        if (child) return child
      }
      return ''
    }
    return find(localCategories.value)
  }

  /** ===== 分类折叠 + 分类全选 ===== */
  /** 展开中的分类 code 集合(自定义折叠,不用 el-collapse 避免 header 插槽 memo 不刷新) */
  const expandedCategories = ref<string[]>([])

  const isCategoryExpanded = (cat: UpstreamCategory) =>
    expandedCategories.value.includes(cat.category_code ?? '_')

  const toggleCategoryExpand = (cat: UpstreamCategory) => {
    const key = cat.category_code ?? '_'
    expandedCategories.value = expandedCategories.value.includes(key)
      ? expandedCategories.value.filter((k) => k !== key)
      : [...expandedCategories.value, key]
  }

  const categoryCodes = (cat: UpstreamCategory) => cat.products.map((p) => p.code)

  /** 该分类是否全部勾选 */
  const isCategoryAllChecked = (cat: UpstreamCategory) => {
    const codes = categoryCodes(cat)
    return codes.length > 0 && codes.every((c) => previewChecked.value.includes(c))
  }

  /** 该分类是否半选 */
  const isCategoryIndeterminate = (cat: UpstreamCategory) => {
    const codes = categoryCodes(cat)
    const n = codes.filter((c) => previewChecked.value.includes(c)).length
    return n > 0 && n < codes.length
  }

  /** 勾选/取消勾选整个分类的产品 */
  const toggleCategory = (cat: UpstreamCategory, val: boolean) => {
    const codes = categoryCodes(cat)
    previewChecked.value = val
      ? [...new Set([...previewChecked.value, ...codes])]
      : previewChecked.value.filter((c) => !codes.includes(c))
  }

  /** 打开预览弹窗:实时拉取上游商品 */
  const openPreview = async (row: SupplySource) => {
    previewingId.value = row.id
    previewSourceId.value = row.id
    previewVisible.value = true
    previewLoading.value = true
    previewError.value = ''
    previewCategories.value = []
    previewChecked.value = []
    previewTotal.value = 0
    categoryMapping.value = {}
    initPricingFromSource(row)
    loadLocalCategories()
    try {
      const res = await previewSupplyProducts(row.id)
      if (res.ok) {
        previewCategories.value = res.categories || []
        previewTotal.value = res.total || 0
        // 默认展开所有分类
        expandedCategories.value = previewCategories.value.map((c) => c.category_code ?? '_')
      } else {
        previewError.value = res.error || t('zcard.supply.previewFailed')
      }
    } catch (e: any) {
      previewError.value = e?.message || t('zcard.supply.previewFailed')
    } finally {
      previewLoading.value = false
      previewingId.value = null
    }
  }

  /** 勾选导入(按所选定价策略) */
  const handleImport = async () => {
    if (!previewSourceId.value || previewChecked.value.length === 0) return
    importing.value = true
    try {
      const pricing = {
        mode: pricingMode.value,
        markup_percent: markupPercent.value,
        markup_amount: markupAmountYuan.value
      }
      const res = await importSupplyProducts(previewSourceId.value, [...previewChecked.value], {
        pricing,
        save_default: saveDefaultPricing.value,
        category_map: categoryMapping.value
      })
      if (res.ok) {
        ElMessage.success(res.message || t('zcard.supply.importSuccess'))
        previewVisible.value = false
        fetchData()
      } else {
        ElMessage.error(res.error || t('zcard.supply.importFailed'))
      }
    } catch {
      // 拦截器处理
    } finally {
      importing.value = false
    }
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

  onMounted(() => {
    fetchDrivers()
    fetchData()
  })
</script>

<style scoped>
  /* input + 单位(元/%)同行排列 */
  .input-with-unit {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .unit {
    color: var(--el-text-color-secondary);
    white-space: nowrap;
    flex-shrink: 0;
  }
  .supply-page {
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
  .field-help {
    font-size: 12px;
    color: var(--el-text-color-secondary);
    line-height: 1.5;
    margin-top: 4px;
    display: block;
  }

  /* 拉取商品弹窗 */
  .preview-wrap {
    min-height: 200px;
  }
  .preview-error {
    color: var(--el-color-danger);
    font-size: 13px;
    padding: 16px;
    text-align: center;
  }
  .preview-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--el-border-color-lighter);
  }
  .preview-summary {
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }
  .summary-imported {
    margin-left: 8px;
    color: var(--el-color-success);
    font-weight: 600;
  }
  .toolbar-check {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  /* 定价策略区 */
  .preview-pricing {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 12px 14px;
    margin-bottom: 12px;
    background: var(--el-fill-color-lighter);
    border-radius: 8px;
  }
  .pricing-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
  }
  .pricing-row + .pricing-row {
    border-top: 1px dashed var(--el-border-color-lighter);
    padding-top: 8px;
  }
  .pricing-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--el-text-color-primary);
    flex-shrink: 0;
    min-width: 76px;
  }
  .pricing-modes {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
  }
  .pricing-modes :deep(.el-radio) {
    margin-right: 18px;
  }
  .pricing-param {
    margin-left: 6px;
  }
  .pricing-save {
    flex-shrink: 0;
  }
  .pricing-actions {
    justify-content: flex-start;
  }
  /* 分类映射面板 */
  .preview-map {
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
    padding: 8px 12px;
    margin-bottom: 10px;
  }
  .map-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
  }
  .map-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }
  .map-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .map-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    padding: 7px 0;
  }
  .map-upstream {
    font-size: 13px;
    color: var(--el-text-color-primary);
    flex-shrink: 0;
    min-width: 150px;
    max-width: 240px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .map-count {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }
  .map-arrow {
    color: var(--el-text-color-placeholder);
    flex-shrink: 0;
  }
  .map-target {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    flex: 1;
    min-width: 0;
  }
  .map-select {
    flex: 1;
    min-width: 200px;
    max-width: 300px;
  }
  .map-tag {
    flex-shrink: 0;
  }
  .map-empty {
    color: var(--el-text-color-placeholder);
    font-size: 12px;
    padding: 8px 0;
  }
  .map-tip {
    font-size: 12px;
    color: var(--el-text-color-placeholder);
    margin-top: 6px;
    line-height: 1.5;
  }
  .preview-list {
    max-height: 55vh;
    overflow-y: auto;
  }
  .preview-collapse {
    border: none;
  }
  .preview-cat {
    margin-bottom: 4px;
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
    overflow: hidden;
  }
  .preview-cat-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 8px 10px;
    cursor: pointer;
    background: var(--el-fill-color-lighter);
    user-select: none;
  }
  .preview-cat-head:hover {
    background: var(--el-fill-color-light);
  }
  .preview-cat-head :deep(.el-checkbox) {
    margin-right: 8px;
    flex-shrink: 0;
  }
  .cat-check {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .cat-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }
  .cat-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
    margin-left: auto;
  }
  .cat-count {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }
  .cat-arrow {
    flex-shrink: 0;
    transition: transform 0.2s;
    color: var(--el-text-color-secondary);
  }
  .cat-arrow.expanded {
    transform: rotate(180deg);
  }
  .preview-cat-body {
    display: flex;
    flex-direction: column;
    width: 100%;
    padding: 6px 10px;
  }
  .preview-product {
    padding: 8px 10px;
    border-bottom: 1px solid var(--el-border-color-extra-light);
    border-radius: 4px;
    transition: background-color 0.2s;
  }
  /* 已对接(已导入本地)的商品行:浅绿底色弱化,突出新货源 */
  .preview-product.is-imported {
    background: var(--el-color-success-light-9);
  }
  .preview-product :deep(.el-checkbox__label) {
    width: 100%;
  }
  .pp-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    gap: 12px;
  }
  .pp-name {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    flex: 1;
    min-width: 0;
    line-height: 1.5;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .pp-status {
    flex-shrink: 0;
  }
  .pp-meta {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
    padding-left: 4px;
  }
  .pp-base {
    font-size: 12px;
    color: var(--el-text-color-secondary);
    text-decoration: line-through;
  }
  .pp-arrow {
    font-size: 12px;
    color: var(--el-text-color-placeholder);
  }
  .pp-price {
    font-size: 13px;
    color: var(--el-color-danger);
    font-weight: 600;
  }
  .pp-price.pending {
    color: var(--el-color-warning);
  }
  .preview-empty {
    text-align: center;
    color: var(--el-text-color-placeholder);
    padding: 40px;
    font-size: 13px;
  }

.task-source-name {
  font-weight: 600;
  margin-bottom: 12px;
}
.task-card {
  border: 1px solid var(--el-border-color);
  border-radius: 8px;
  padding: 12px 14px;
  margin-bottom: 12px;
}
.task-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}
.task-label {
  font-size: 13px;
  color: var(--el-text-color-secondary);
}
.task-mode {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
.task-progress {
  margin: 6px 0;
}
.task-progress-text {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  margin-top: 4px;
}
.task-counts {
  display: flex;
  gap: 14px;
  margin: 6px 0;
  font-size: 13px;
}
.task-count.created { color: var(--el-color-success); }
.task-count.updated { color: var(--el-color-primary); }
.task-count.hidden { color: var(--el-color-warning); }
.task-error {
  margin: 6px 0;
  font-size: 12px;
  color: var(--el-color-danger);
  word-break: break-all;
  max-height: 80px;
  overflow-y: auto;
}
.task-done {
  margin: 6px 0;
  font-size: 12px;
  color: var(--el-color-success);
}
.task-actions {
  margin-top: 10px;
  display: flex;
  gap: 8px;
}
.task-empty {
  padding: 20px 0;
  text-align: center;
  color: var(--el-text-color-secondary);
  font-size: 13px;
}
.task-history {
  border-top: 1px dashed var(--el-border-color);
  padding-top: 10px;
}
.task-history-title {
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 8px;
}
.task-history-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  padding: 5px 0;
}
.task-history-info {
  flex: 1;
  color: var(--el-text-color-regular);
}
.task-history-time {
  color: var(--el-text-color-secondary);
}

.queue-banner {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 14px;
  font-size: 13px;
}
.queue-banner.queue-ok {
  background: var(--el-color-success-light-9);
  border: 1px solid var(--el-color-success-light-5);
  color: var(--el-color-success);
}
.queue-banner.queue-down {
  background: var(--el-color-danger-light-9);
  border: 1px solid var(--el-color-danger-light-5);
  color: var(--el-color-danger);
}
.queue-banner.queue-checking {
  background: var(--el-fill-color-light);
  color: var(--el-text-color-secondary);
}
.queue-icon {
  flex-shrink: 0;
  margin-top: 1px;
  font-size: 16px;
}
.queue-down-body {
  flex: 1;
}
.queue-down-title {
  font-weight: 600;
  margin-bottom: 4px;
}
.queue-down-tip {
  color: var(--el-text-color-regular);
  margin-bottom: 6px;
}
.queue-cmd {
  background: var(--el-fill-color);
  border-radius: 6px;
  padding: 6px 10px;
  font-size: 12px;
  margin-bottom: 6px;
  overflow-x: auto;
}
.queue-down-help {
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
.all-tasks {
  max-height: 56vh;
  overflow-y: auto;
}
.all-task-row {
  border: 1px solid var(--el-border-color);
  border-radius: 8px;
  padding: 10px 12px;
  margin-bottom: 10px;
}
.all-task-main {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 6px;
}
.all-task-source {
  font-weight: 600;
  font-size: 13px;
}
.all-task-progress {
  display: flex;
  align-items: center;
  gap: 10px;
}
.all-task-progress .el-progress {
  flex: 1;
}
.all-task-counts {
  font-size: 12px;
  color: var(--el-text-color-secondary);
  white-space: nowrap;
}
.all-task-waiting {
  margin: 4px 0;
}
.all-task-meta {
  margin-top: 4px;
  font-size: 12px;
}
</style>
