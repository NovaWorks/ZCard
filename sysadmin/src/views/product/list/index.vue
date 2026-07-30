<!-- 商品列表 - 后台管理 -->
<template>
  <div class="product-page art-full-height">
    <!-- 统计面板 -->
    <ElRow :gutter="16" class="stats-row">
      <ElCol v-for="card in statCards" :key="card.key" :xs="12" :sm="8" :md="4">
        <div class="stat-card" :class="card.cls">
          <div class="stat-icon">
            <ElIcon :size="28">
              <component :is="card.icon" />
            </ElIcon>
          </div>
          <div class="stat-body">
            <div class="stat-number">{{ card.value }}</div>
            <div class="stat-label">{{ t(`zcard.product.stats.${card.label}`) }}</div>
          </div>
        </div>
      </ElCol>
    </ElRow>

    <ElCard class="art-table-card" shadow="never">
      <!-- 搜索栏 -->
      <div class="search-bar">
        <ElForm :inline="true" :model="searchForm" @submit.prevent>
          <ElFormItem :label="t('zcard.product.name')">
            <ElInput
              v-model="searchForm.keyword"
              :placeholder="t('zcard.product.searchPlaceholder')"
              clearable
              style="width: 180px"
              @keyup.enter="handleSearch"
            />
          </ElFormItem>
          <ElFormItem :label="t('zcard.category.title')">
            <ElTreeSelect
              v-model="searchForm.category_id"
              :data="categoryTreeData"
              :placeholder="t('zcard.product.all')"
              clearable
              check-strictly
              :props="{ label: 'name', value: 'id', children: 'children' }"
              node-key="id"
              style="width: 200px"
            />
          </ElFormItem>
          <ElFormItem :label="t('zcard.product.featured')">
            <ElSelect v-model="searchForm.is_featured" :placeholder="t('zcard.product.all')" clearable style="width: 120px">
              <ElOption :label="t('zcard.product.featuredYes')" :value="1" />
              <ElOption :label="t('zcard.product.featuredNo')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="t('zcard.product.status')">
            <ElSelect v-model="searchForm.status" :placeholder="t('zcard.product.all')" clearable style="width: 120px">
              <ElOption :label="t('zcard.product.statusOn')" :value="1" />
              <ElOption :label="t('zcard.product.statusOff')" :value="0" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem :label="t('zcard.product.stockType')">
            <ElSelect v-model="searchForm.stock_type" :placeholder="t('zcard.product.all')" clearable style="width: 120px">
              <ElOption :label="t('zcard.product.stockCard')" value="card" />
              <ElOption :label="t('zcard.product.stockUrl')" value="url" />
              <ElOption :label="t('zcard.product.stockCode')" value="code" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem>
            <ElButton type="primary" @click="handleSearch">{{ t('zcard.common.search') }}</ElButton>
            <ElButton @click="handleReset">{{ t('zcard.common.reset') }}</ElButton>
          </ElFormItem>
        </ElForm>
      </div>

      <!-- 表格头部：新增 + 批量操作 -->
      <div class="table-header">
        <ElButton type="primary" @click="openCreate">{{ t('zcard.product.add') }}</ElButton>
        <ElButtonGroup class="ml-2">
          <ElButton
            type="success"
            :disabled="selectedIds.length === 0"
            :loading="batchLoading"
            @click="handleBatch('activate')"
          >
            {{ t('zcard.product.batchActivate') }}
          </ElButton>
          <ElButton
            type="warning"
            :disabled="selectedIds.length === 0"
            :loading="batchLoading"
            @click="handleBatch('deactivate')"
          >
            {{ t('zcard.product.batchDeactivate') }}
          </ElButton>
          <ElButton
            type="danger"
            :disabled="selectedIds.length === 0"
            :loading="batchLoading"
            @click="handleBatch('delete')"
          >
            {{ t('zcard.product.batchDelete') }}
          </ElButton>
        </ElButtonGroup>
        <span v-if="selectedIds.length" class="selection-count">
          {{ t('zcard.product.stats.total') }}: {{ selectedIds.length }}
        </span>
      </div>

      <!-- 表格 -->
      <ElTable
        v-loading="loading"
        :data="tableData"
        border
        stripe
        style="width: 100%"
        @selection-change="handleSelectionChange"
      >
        <ElTableColumn type="selection" width="50" />
        <ElTableColumn prop="id" :label="t('zcard.common.id')" width="80" />
        <ElTableColumn :label="t('zcard.product.cover')" width="90" align="center">
          <template #default="{ row }">
            <ElImage
              v-if="row.cover"
              :src="row.cover"
              :preview-src-list="[row.cover]"
              preview-teleported
              fit="cover"
              style="width: 50px; height: 50px; border-radius: 4px"
            />
            <span v-else style="color: #c0c4cc">-</span>
          </template>
        </ElTableColumn>
        <ElTableColumn prop="name" :label="t('zcard.product.name')" min-width="200" show-overflow-tooltip />
        <ElTableColumn :label="t('zcard.product.category')" width="140">
          <template #default="{ row }">
            {{ row.category?.name || '-' }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.product.priceShort')" width="120" align="right">
          <template #default="{ row }">
            ¥{{ formatPrice(row.price) }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.product.stock')" width="100" align="center">
          <template #default="{ row }">
            {{ row.stock ?? 0 }}
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.product.status')" width="110" align="center">
          <template #default="{ row }">
            <ElSwitch
              :model-value="!!row.status"
              :active-value="true"
              :inactive-value="false"
              :loading="row._statusLoading"
              @change="(val) => handleStatusToggle(row, !!val)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.product.featured')" width="100" align="center">
          <template #default="{ row }">
            <ElSwitch
              :model-value="!!row.is_featured"
              :active-value="true"
              :inactive-value="false"
              :loading="row._featuredLoading"
              @change="(val) => handleFeaturedToggle(row, !!val)"
            />
          </template>
        </ElTableColumn>
        <ElTableColumn :label="t('zcard.product.sort')" width="90" align="center" prop="sort" />
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

    <!-- 新增/编辑抽屉 -->
    <ElDrawer
      v-model="drawerVisible"
      :title="drawerTitle"
      size="55%"
      direction="rtl"
      destroy-on-close
      @closed="resetForm"
    >
      <ElForm
        ref="formRef"
        :model="formData"
        :rules="formRules"
        label-width="140px"
        class="product-form"
      >
        <ElTabs v-model="activeTab" class="product-tabs">
          <!-- Tab 1: 基本信息 -->
          <ElTabPane :label="t('zcard.product.basicInfo')" name="basic">
            <ElFormItem :label="t('zcard.product.name')" prop="name">
              <ElInput v-model="formData.name" :placeholder="t('zcard.product.searchPlaceholder')" maxlength="150" />
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.slug')">
              <ElInput v-model="formData.slug" :placeholder="t('zcard.product.slugPlaceholder')" maxlength="150" />
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.category')">
              <ElSelect
                v-model="formData.category_id"
                :placeholder="t('zcard.product.category')"
                clearable
                filterable
                style="width: 100%"
              >
                <ElOption v-for="cat in categories" :key="cat.id" :label="cat.name" :value="cat.id" />
              </ElSelect>
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.description')">
              <ElInput
                v-model="formData.description"
                type="textarea"
                :rows="5"
                :placeholder="t('zcard.product.description')"
                maxlength="2000"
                show-word-limit
              />
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.cover')">
              <ElUpload
                :show-file-list="false"
                :http-request="handleCoverUpload"
                accept="image/*"
                :before-upload="beforeUpload"
              >
                <div v-if="formData.cover" class="cover-preview">
                  <ElImage :src="formData.cover" fit="cover" class="cover-img" />
                  <div class="cover-mask">
                    <span>{{ t('zcard.product.coverReplace') }}</span>
                  </div>
                </div>
                <div v-else class="cover-placeholder">
                  <ElIcon class="upload-icon"><Plus /></ElIcon>
                  <span>{{ t('zcard.product.coverUpload') }}</span>
                </div>
              </ElUpload>
              <ElButton v-if="formData.cover" link type="danger" class="ml-2" @click="formData.cover = ''">
                {{ t('zcard.product.coverRemove') }}
              </ElButton>
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.images')">
              <ElUpload
                :file-list="galleryFileList"
                list-type="picture-card"
                :http-request="handleGalleryUpload"
                :on-remove="handleGalleryRemove"
                accept="image/*"
                :before-upload="beforeUpload"
                multiple
              >
                <ElIcon class="upload-icon"><Plus /></ElIcon>
              </ElUpload>
              <span class="form-hint">{{ t('zcard.product.galleryHint') }}</span>
            </ElFormItem>
          </ElTabPane>

          <!-- Tab 2: 价格与库存 -->
          <ElTabPane :label="t('zcard.product.pricingStock')" name="pricing">
            <ElFormItem :label="t('zcard.product.price')" prop="priceYuan">
              <ElInputNumber
                v-model="formData.priceYuan"
                :min="0"
                :precision="2"
                :step="1"
                controls-position="right"
                style="width: 220px"
              />
              <span class="form-hint">{{ t('zcard.product.priceUnit') }}</span>
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.virtualSales')">
              <ElInputNumber
                v-model="formData.virtual_sales"
                :min="0"
                :step="1"
                controls-position="right"
                style="width: 220px"
              />
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.stockType')">
              <ElSelect v-model="formData.stock_type" style="width: 100%">
                <ElOption :label="t('zcard.product.stockCard')" value="card" />
                <ElOption :label="t('zcard.product.stockUrl')" value="url" />
                <ElOption :label="t('zcard.product.stockCode')" value="code" />
              </ElSelect>
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.stockVisible')">
              <ElSwitch v-model="formData.stock_visible" />
              <span class="ml-2">{{ formData.stock_visible ? t('zcard.product.show') : t('zcard.product.hide') }}</span>
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.actualStock')">
              <ElTag :type="actualStock > 0 ? 'success' : 'info'">{{ actualStock }}</ElTag>
              <span class="form-hint">{{ t('zcard.product.virtualStockHint') }}</span>
            </ElFormItem>
          </ElTabPane>

          <!-- Tab 3: 规格(SKU) -->
          <ElTabPane v-if="drawerType === 'edit' && editId !== null" :label="t('zcard.product.skuSection')" name="sku">
            <div class="tab-toolbar">
              <ElButton type="primary" size="small" @click="addSkuRow">
                {{ t('zcard.product.skuAdd') }}
              </ElButton>
            </div>
            <ElTable :data="skuList" border size="small" style="width: 100%">
              <ElTableColumn :label="t('zcard.product.name')" min-width="140">
                <template #default="{ row }">
                  <ElInput v-if="row._editing" v-model="row.name" :placeholder="t('zcard.product.skuNamePlaceholder')" size="small" />
                  <span v-else>{{ row.name }}</span>
                </template>
              </ElTableColumn>
              <ElTableColumn :label="t('zcard.product.price')" width="130">
                <template #default="{ row }">
                  <ElInputNumber
                    v-if="row._editing"
                    v-model="row.priceYuan"
                    :min="0"
                    :precision="2"
                    :step="1"
                    size="small"
                    controls-position="right"
                    style="width: 110px"
                  />
                  <span v-else>¥{{ formatPrice(row.price) }}</span>
                </template>
              </ElTableColumn>
              <ElTableColumn :label="t('zcard.product.stockType')" width="140">
                <template #default="{ row }">
                  <ElSelect v-if="row._editing" v-model="row.stock_type" size="small" style="width: 110px">
                    <ElOption :label="t('zcard.product.stockCard')" value="card" />
                    <ElOption :label="t('zcard.product.stockUrl')" value="url" />
                    <ElOption :label="t('zcard.product.stockCode')" value="code" />
                  </ElSelect>
                  <span v-else>{{ stockTypeLabel(row.stock_type) }}</span>
                </template>
              </ElTableColumn>
              <ElTableColumn :label="t('zcard.product.sort')" width="110">
                <template #default="{ row }">
                  <ElInputNumber
                    v-if="row._editing"
                    v-model="row.sort"
                    :min="0"
                    :step="1"
                    size="small"
                    controls-position="right"
                    style="width: 90px"
                  />
                  <span v-else>{{ row.sort ?? 0 }}</span>
                </template>
              </ElTableColumn>
              <ElTableColumn :label="t('zcard.product.status')" width="90" align="center">
                <template #default="{ row }">
                  <ElSwitch v-if="row._editing" v-model="row.status" size="small" />
                  <ElTag v-else :type="row.status ? 'success' : 'info'" effect="plain" size="small">
                    {{ row.status ? t('zcard.category.statusOn') : t('zcard.category.statusOff') }}
                  </ElTag>
                </template>
              </ElTableColumn>
              <ElTableColumn :label="t('zcard.common.actions')" width="170" align="center" fixed="right">
                <template #default="{ row, $index }">
                  <template v-if="row._editing">
                    <ElButton type="primary" link size="small" @click="saveSkuRow(row)">
                      {{ t('zcard.common.save') }}
                    </ElButton>
                    <ElButton link size="small" @click="cancelSkuRow(row, $index)">
                      {{ t('zcard.common.cancel') }}
                    </ElButton>
                  </template>
                  <template v-else>
                    <ElButton type="primary" link size="small" @click="editSkuRow(row)">
                      {{ t('zcard.common.edit') }}
                    </ElButton>
                    <ElButton type="danger" link size="small" @click="deleteSkuRow(row, $index)">
                      {{ t('zcard.common.delete') }}
                    </ElButton>
                  </template>
                </template>
              </ElTableColumn>
            </ElTable>
            <div v-if="!skuList.length" class="sku-empty">
              {{ t('zcard.product.skuEmpty') }}
            </div>
          </ElTabPane>

          <!-- Tab 4: 发货设置 -->
          <ElTabPane :label="t('zcard.product.deliverySettings')" name="delivery">
            <ElFormItem :label="t('zcard.product.deliveryModeRadio')">
              <ElRadioGroup v-model="formData.delivery_mode">
                <ElRadio value="status">{{ t('zcard.product.deliveryStatus') }}</ElRadio>
                <ElRadio value="delete">{{ t('zcard.product.deliveryDelete') }}</ElRadio>
              </ElRadioGroup>
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.deliveryMessage')">
              <ElInput
                v-model="formData.delivery_message"
                type="textarea"
                :rows="4"
                :placeholder="t('zcard.product.deliveryMessageHint')"
              />
              <span class="form-hint">{{ t('zcard.product.deliveryMessageHint') }}</span>
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.leaveMessage')">
              <ElInput
                v-model="formData.leave_message"
                type="textarea"
                :rows="4"
                :placeholder="t('zcard.product.leaveMessageHint')"
              />
              <span class="form-hint">{{ t('zcard.product.leaveMessageHint') }}</span>
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.sendEmail')">
              <ElSwitch v-model="formData.send_email" />
            </ElFormItem>
          </ElTabPane>

          <!-- Tab 5: 控件设置 -->
          <ElTabPane :label="t('zcard.product.controlSettings')" name="controls">
            <ElAlert
              type="info"
              :closable="false"
              :title="t('zcard.product.controlSettingsHint')"
              class="control-alert"
            />
            <div class="tab-toolbar">
              <ElButton type="primary" size="small" @click="addControlRow">
                {{ t('zcard.product.addControl') }}
              </ElButton>
            </div>
            <ElTable :data="controlList" border size="small" style="width: 100%">
              <ElTableColumn :label="t('zcard.product.controlType')" width="140">
                <template #default="{ row }">
                  <ElSelect v-model="row.type" size="small" style="width: 110px">
                    <ElOption :label="t('zcard.product.controlTypeText')" value="text" />
                    <ElOption :label="t('zcard.product.controlTypeEmail')" value="email" />
                    <ElOption :label="t('zcard.product.controlTypeTextarea')" value="textarea" />
                    <ElOption :label="t('zcard.product.controlTypeSelect')" value="select" />
                  </ElSelect>
                </template>
              </ElTableColumn>
              <ElTableColumn :label="t('zcard.product.controlLabel')" min-width="140">
                <template #default="{ row }">
                  <ElInput v-model="row.label" size="small" />
                </template>
              </ElTableColumn>
              <ElTableColumn :label="t('zcard.product.controlName')" min-width="140">
                <template #default="{ row }">
                  <ElInput v-model="row.name" size="small" />
                </template>
              </ElTableColumn>
              <ElTableColumn :label="t('zcard.product.controlRequired')" width="100" align="center">
                <template #default="{ row }">
                  <ElSwitch v-model="row.required" size="small" />
                </template>
              </ElTableColumn>
              <ElTableColumn :label="t('zcard.product.controlOptions')" min-width="160">
                <template #default="{ row }">
                  <ElInput
                    v-model="row.options"
                    :disabled="row.type !== 'select'"
                    size="small"
                    :placeholder="t('zcard.product.controlOptionsHint')"
                  />
                </template>
              </ElTableColumn>
              <ElTableColumn :label="t('zcard.common.actions')" width="100" align="center" fixed="right">
                <template #default="{ $index }">
                  <ElButton type="danger" link size="small" @click="removeControlRow($index)">
                    {{ t('zcard.common.delete') }}
                  </ElButton>
                </template>
              </ElTableColumn>
            </ElTable>
            <div v-if="!controlList.length" class="sku-empty">
              {{ t('zcard.common.noData') }}
            </div>
          </ElTabPane>

          <!-- Tab 6: 商品限制 -->
          <ElTabPane :label="t('zcard.product.purchaseLimits')" name="limits">
            <ElFormItem :label="t('zcard.product.minOrder')">
              <ElInputNumber
                v-model="formData.min_order"
                :min="1"
                :step="1"
                controls-position="right"
                style="width: 220px"
              />
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.maxOrder')">
              <ElInputNumber
                v-model="formData.max_order"
                :min="0"
                :step="1"
                controls-position="right"
                style="width: 220px"
              />
              <span class="form-hint">{{ t('zcard.product.maxOrderHint') }}</span>
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.purchaseLimit')">
              <ElInputNumber
                v-model="formData.purchase_limit"
                :min="0"
                :step="1"
                controls-position="right"
                style="width: 220px"
              />
              <span class="form-hint">{{ t('zcard.product.purchaseLimitHint') }}</span>
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.onlyUser')">
              <ElSwitch v-model="formData.only_user" />
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.contactType')">
              <ElSelect v-model="formData.contact_type" style="width: 220px">
                <ElOption :label="t('zcard.product.contactEmail')" value="email" />
                <ElOption :label="t('zcard.product.contactPhone')" value="phone" />
                <ElOption :label="t('zcard.product.contactNone')" value="none" />
              </ElSelect>
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.hide')">
              <ElSwitch v-model="formData.hide" />
              <span class="form-hint">{{ t('zcard.product.hideHint') }}</span>
            </ElFormItem>
          </ElTabPane>

          <!-- Tab 7: 虚拟数据 -->
          <ElTabPane :label="t('zcard.product.virtualData')" name="virtual">
            <ElFormItem :label="t('zcard.product.isFeatured')">
              <ElSwitch v-model="formData.is_featured" />
              <span class="ml-2">{{ formData.is_featured ? t('zcard.product.featured') : t('zcard.product.featuredPlain') }}</span>
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.virtualReviews')">
              <ElInput
                v-model="virtualReviewsText"
                type="textarea"
                :rows="6"
                :placeholder="t('zcard.product.virtualReviewsHint')"
              />
              <span class="form-hint">{{ t('zcard.product.virtualReviewsHint') }}</span>
            </ElFormItem>

            <ElFormItem :label="t('zcard.product.levelDisable')">
              <ElSwitch v-model="formData.level_disable" />
            </ElFormItem>
          </ElTabPane>

          <!-- Tab: 会员等级价格 -->
          <ElTabPane :label="t('zcard.product.memberPriceTab')" name="memberPrice">
            <ElAlert
              type="info"
              :closable="false"
              :title="t('zcard.product.memberPriceTabHint')"
              class="mb-3"
            />
            <ElTable :data="memberLevels" border size="small" style="width: 100%">
              <ElTableColumn :label="t('zcard.product.levelName')" min-width="140">
                <template #default="{ row }">
                  <ElTag size="small" effect="light" :type="row.group_id === 1 ? 'info' : 'warning'">
                    {{ row.label }}
                  </ElTag>
                </template>
              </ElTableColumn>
              <ElTableColumn :label="t('zcard.product.levelPrice')" width="200">
                <template #default="{ row }">
                  <ElInputNumber
                    v-model="row.priceYuan"
                    :min="0"
                    :precision="2"
                    :step="1"
                    size="small"
                    style="width: 160px"
                  />
                  <span class="ml-1 text-xs text-gray-400">¥</span>
                </template>
              </ElTableColumn>
              <ElTableColumn :label="t('zcard.product.levelDiscount')" width="120">
                <template #default="{ row }">
                  <span v-if="row.priceYuan > 0 && formData.priceYuan > 0" class="text-xs text-green-600">
                    {{ (row.priceYuan / formData.priceYuan * 10).toFixed(1) }}{{ t('zcard.product.levelDiscountUnit') }}
                  </span>
                  <span v-else class="text-xs text-gray-400">-</span>
                </template>
              </ElTableColumn>
            </ElTable>
          </ElTabPane>
        </ElTabs>

        <ElDivider />

        <!-- 抽屉底部公共字段 -->
        <ElFormItem :label="t('zcard.product.sort')">
          <ElInputNumber
            v-model="formData.sort"
            :step="1"
            controls-position="right"
            style="width: 220px"
          />
        </ElFormItem>

        <ElFormItem :label="t('zcard.product.status')">
          <ElSwitch v-model="formData.status" :active-value="1" :inactive-value="0" />
          <span class="ml-2">{{ formData.status ? t('zcard.product.statusOn') : t('zcard.product.statusOff') }}</span>
        </ElFormItem>
      </ElForm>

      <template #footer>
        <ElButton @click="drawerVisible = false">{{ t('zcard.common.cancel') }}</ElButton>
        <ElButton type="primary" :loading="submitting" @click="handleSubmit">
          {{ t('zcard.common.ok') }}
        </ElButton>
      </template>
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import type { FormInstance, FormRules, UploadFile, UploadRequestOptions } from 'element-plus'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import {
    Goods,
    CircleCheck,
    CircleClose,
    Star,
    Box,
    Wallet,
    Document
  } from '@element-plus/icons-vue'
  import { Plus } from '@element-plus/icons-vue'
  import { useI18n } from 'vue-i18n'
  import {
    getProducts,
    getProduct,
    createProduct,
    updateProduct,
    deleteProduct,
    getProductStats,
    batchAction,
    getUserGroups,
    type Product,
    type ProductStats,
    type UserGroup
  } from '@/api/products'
  import { getAllCategories, type Category } from '@/api/categories'
  import { uploadImage } from '@/api/upload'
  import {
    getSkus,
    createSku,
    updateSku,
    deleteSku,
    type Sku
  } from '@/api/sku'

  defineOptions({ name: 'ProductList' })

  const { t } = useI18n()

  /** 金额分 -> 元(两位小数) */
  const formatPrice = (fen: number): string => ((Number(fen) || 0) / 100).toFixed(2)

  /** 库存类型标签 */
  const stockTypeLabel = (type?: string): string => {
    switch (type) {
      case 'card':
        return t('zcard.product.stockCard')
      case 'url':
        return t('zcard.product.stockUrl')
      case 'code':
        return t('zcard.product.stockCode')
      default:
        return type || '-'
    }
  }

  /** 列表/分页状态 */
  const loading = ref(false)
  const tableData = ref<Product[]>([])
  const pagination = reactive({
    page: 1,
    pageSize: 15,
    total: 0
  })

  /** 搜索表单 */
  const searchForm = reactive<{
    keyword?: string
    status?: number
    category_id?: number
    is_featured?: number
    stock_type?: string
  }>({
    keyword: undefined,
    status: undefined,
    category_id: undefined,
    is_featured: undefined,
    stock_type: undefined
  })

  /** 分类列表(扁平化后供下拉使用) */
  const categories = ref<{ id: number; name: string }[]>([])

  /** 搜索栏用的扁平分类(带缩进前缀) */
  const flatCategoriesForSearch = ref<{ id: number; label: string }[]>([])

  /** 搜索栏用的树形分类数据(供 ElTreeSelect) */
  const categoryTreeData = ref<Category[]>([])

  /** 扁平化分类树(带层级缩进) */
  const flattenCategoriesWithIndent = (tree: Category[], depth = 0): { id: number; label: string }[] => {
    const result: { id: number; label: string }[] = []
    const walk = (nodes: Category[], d: number) => {
      nodes.forEach((node) => {
        result.push({ id: node.id, label: '— '.repeat(d) + node.name })
        if (node.children?.length) walk(node.children, d + 1)
      })
    }
    walk(tree, depth)
    return result
  }

  /** 加载分类列表 */
  const loadCategories = async () => {
    try {
      const tree = await getAllCategories()
      categories.value = flattenCategoriesWithIndent(tree || []).map((c) => ({ id: c.id, name: c.label }))
      flatCategoriesForSearch.value = flattenCategoriesWithIndent(tree || [])
      categoryTreeData.value = tree || []
    } catch {
      categories.value = []
      flatCategoriesForSearch.value = []
      categoryTreeData.value = []
    }
  }

  /** 拉取商品列表 */
  const fetchData = async () => {
    loading.value = true
    try {
      const res = await getProducts({
        page: pagination.page,
        pageSize: pagination.pageSize,
        keyword: searchForm.keyword,
        status: searchForm.status,
        category_id: searchForm.category_id,
        is_featured: searchForm.is_featured,
        stock_type: searchForm.stock_type
      })
      tableData.value = (res.data || []).map((p) => ({
        ...p,
        _statusLoading: false,
        _featuredLoading: false
      }))
      pagination.total = res.total || 0
    } catch {
      tableData.value = []
      pagination.total = 0
    } finally {
      loading.value = false
    }
  }

  /** 搜索 */
  const handleSearch = () => {
    pagination.page = 1
    fetchData()
  }

  /** 重置搜索 */
  const handleReset = () => {
    searchForm.keyword = undefined
    searchForm.status = undefined
    searchForm.category_id = undefined
    searchForm.is_featured = undefined
    searchForm.stock_type = undefined
    pagination.page = 1
    fetchData()
  }

  /** 统计数据 */
  const stats = ref<ProductStats>({
    total: 0,
    active: 0,
    inactive: 0,
    featured: 0,
    total_stock: 0,
    total_orders: 0,
    paid_orders: 0
  })

  /** 加载统计数据 */
  const fetchStats = async () => {
    try {
      const res = await getProductStats()
      stats.value = { ...stats.value, ...res }
    } catch {
      // 错误消息由 http 拦截器统一提示
    }
  }

  /** 统计卡片配置 */
  const statCards = computed(() => [
    { key: 'total', label: 'total', value: stats.value.total, icon: Goods, cls: 'stat-total' },
    { key: 'active', label: 'active', value: stats.value.active, icon: CircleCheck, cls: 'stat-active' },
    { key: 'inactive', label: 'inactive', value: stats.value.inactive, icon: CircleClose, cls: 'stat-inactive' },
    { key: 'featured', label: 'featured', value: stats.value.featured, icon: Star, cls: 'stat-featured' },
    { key: 'totalStock', label: 'totalStock', value: stats.value.total_stock, icon: Box, cls: 'stat-stock' },
    { key: 'paidOrders', label: 'paidOrders', value: stats.value.paid_orders, icon: Wallet, cls: 'stat-paid' }
  ])

  /** 批量选择 */
  const selectedIds = ref<number[]>([])
  const batchLoading = ref(false)

  /** 选择变更 */
  const handleSelectionChange = (rows: Product[]) => {
    selectedIds.value = rows.map((r) => r.id)
  }

  /** 批量操作 */
  const handleBatch = (action: 'activate' | 'deactivate' | 'delete') => {
    if (!selectedIds.value.length) {
      ElMessage.warning(t('zcard.product.selectFirst'))
      return
    }
    const actionLabel =
      action === 'activate'
        ? t('zcard.product.batchActivate')
        : action === 'deactivate'
          ? t('zcard.product.batchDeactivate')
          : t('zcard.product.batchDelete')
    ElMessageBox.confirm(
      `${actionLabel} (${selectedIds.value.length})?`,
      t('zcard.common.tips'),
      {
        confirmButtonText: t('zcard.common.ok'),
        cancelButtonText: t('zcard.common.cancel'),
        type: action === 'delete' ? 'warning' : 'info'
      }
    )
      .then(async () => {
        batchLoading.value = true
        try {
          await batchAction([...selectedIds.value], action)
          ElMessage.success(t('zcard.product.batchSuccess'))
          selectedIds.value = []
          fetchData()
          fetchStats()
        } catch {
          // 错误消息由 http 拦截器统一提示
        } finally {
          batchLoading.value = false
        }
      })
      .catch(() => {
        // 用户取消
      })
  }

  /** 行内状态切换 */
  const handleStatusToggle = async (row: Product & { _statusLoading?: boolean }, val: boolean) => {
    const newStatus = val ? 1 : 0
    const oldStatus = row.status
    row.status = newStatus
    if (!row._statusLoading) row._statusLoading = true
    try {
      await updateProduct(row.id, { status: newStatus })
      ElMessage.success(t('zcard.product.batchSuccess'))
      fetchStats()
    } catch {
      // 失败回滚
      row.status = oldStatus
    } finally {
      row._statusLoading = false
    }
  }

  /** 行内推荐切换 */
  const handleFeaturedToggle = async (
    row: Product & { _featuredLoading?: boolean },
    val: boolean
  ) => {
    const oldFeatured = row.is_featured
    row.is_featured = val
    if (!row._featuredLoading) row._featuredLoading = true
    try {
      await updateProduct(row.id, { is_featured: val })
      ElMessage.success(t('zcard.product.batchSuccess'))
      fetchStats()
    } catch {
      // 失败回滚
      row.is_featured = oldFeatured
    } finally {
      row._featuredLoading = false
    }
  }

  /** 抽屉相关 */
  const drawerVisible = ref(false)
  const drawerType = ref<'create' | 'edit'>('create')
  const submitting = ref(false)
  const editId = ref<number | null>(null)
  const formRef = ref<FormInstance>()
  const activeTab = ref<'basic' | 'pricing' | 'sku' | 'delivery' | 'controls' | 'limits' | 'virtual'>('basic')
  /** 编辑态实际库存(从详情接口获取) */
  const actualStock = ref(0)

  const drawerTitle = computed(() =>
    drawerType.value === 'create' ? t('zcard.product.add') : t('zcard.product.edit')
  )

  /** 自定义控件单行 */
  interface ControlRow {
    type: string
    label: string
    name: string
    required: boolean
    options: string
  }

  interface ProductForm {
    name: string
    slug: string
    category_id: number | null
    description: string
    cover: string
    priceYuan: number
    stock_type: string
    stock_visible: boolean
    delivery_mode: string
    is_featured: boolean
    virtual_sales: number
    min_order: number
    max_order: number
    sort: number
    status: number
    // 新增字段
    contact_type: string
    send_email: boolean
    delivery_message: string
    leave_message: string
    only_user: boolean
    purchase_limit: number
    hide: boolean
    level_disable: boolean
  }

  const createEmptyForm = (): ProductForm => ({
    name: '',
    slug: '',
    category_id: null,
    description: '',
    cover: '',
    priceYuan: 0,
    stock_type: 'card',
    stock_visible: true,
    delivery_mode: 'status',
    is_featured: false,
    virtual_sales: 0,
    min_order: 1,
    max_order: 0,
    sort: 0,
    status: 1,
    contact_type: 'email',
    send_email: true,
    delivery_message: '',
    leave_message: '',
    only_user: false,
    purchase_limit: 0,
    hide: false,
    level_disable: false
  })

  const formData = reactive<ProductForm>(createEmptyForm())
  const memberPriceText = ref('')
  /** 会员等级价格行:group_id 关联到 user_groups,label 为只读名称 */
  interface MemberLevel {
    group_id: number | null
    label: string
    priceYuan: number
  }
  const memberLevels = ref<MemberLevel[]>([])
  /** 可用会员等级列表(从后端 user_groups 加载) */
  const userGroups = ref<UserGroup[]>([])

  /** 当前已选过的 group_id,用于下拉过滤 */
  const usedGroupIds = computed(() =>
    new Set(memberLevels.value.map((lv) => lv.group_id).filter((id) => id !== null))
  )

  /** 加载会员等级列表 */
  const loadUserGroups = async () => {
    try {
      const list = await getUserGroups()
      userGroups.value = list || []
    } catch {
      userGroups.value = []
    }
  }

  /** 根据 group_id 取名称 */
  const groupName = (id: number | null): string => {
    if (id === null) return ''
    return userGroups.value.find((g) => g.id === id)?.name || ''
  }

  /** 默认展示所有会员等级，用已保存的 member_price 填充价格 */
  const populateMemberLevels = (mp: Record<string, number> | null | undefined) => {
    memberLevels.value = userGroups.value.map((g) => {
      const savedKey = `group_${g.id}`
      const legacyKey = `level${g.id}`
      const savedPrice = (mp && (mp[savedKey] ?? mp[legacyKey])) || 0
      return {
        group_id: g.id,
        label: g.name,
        priceYuan: Number(savedPrice) > 0 ? Number(savedPrice) / 100 : 0
      }
    })
  }

  const serializeMemberPrice = () => {
    const result: Record<string, number> = {}
    for (const lv of memberLevels.value) {
      if (lv.group_id !== null && lv.priceYuan > 0) {
        result[`group_${lv.group_id}`] = Math.round(lv.priceYuan * 100)
      }
    }
    return Object.keys(result).length ? result : undefined
  }
  const virtualReviewsText = ref('')
  const controlList = ref<ControlRow[]>([])

  const formRules = computed<FormRules>(() => ({
    name: [{ required: true, message: t('zcard.product.nameRequired'), trigger: 'blur' }],
    priceYuan: [{ required: true, message: t('zcard.product.priceRequired'), trigger: 'blur' }]
  }))

  /** 上传前校验 */
  const beforeUpload = (file: File): boolean => {
    const isImage = file.type.startsWith('image/')
    const underLimit = file.size / 1024 / 1024 < 5
    if (!isImage) {
      ElMessage.error(t('zcard.product.uploadImageOnly'))
      return false
    }
    if (!underLimit) {
      ElMessage.error(t('zcard.product.uploadMaxSize'))
      return false
    }
    return true
  }

  /** 封面上传 */
  const handleCoverUpload = async (options: UploadRequestOptions) => {
    const file = options.file as File
    if (!beforeUpload(file)) return
    try {
      const res = await uploadImage(file)
      formData.cover = res.url
      ElMessage.success(t('zcard.product.coverUploaded'))
    } catch {
      // 错误消息由 http 拦截器统一提示
    }
  }

  /** 详情图列表(Element Upload 用 file-list) */
  interface GalleryFile {
    name: string
    url: string
  }
  const galleryFileList = ref<GalleryFile[]>([])
  /** 详情图实际 URL 数组(提交时使用) */
  const galleryUrls = ref<string[]>([])

  /** 详情图上传 */
  const handleGalleryUpload = async (options: UploadRequestOptions) => {
    const file = options.file as File
    if (!beforeUpload(file)) return
    try {
      const res = await uploadImage(file)
      galleryUrls.value.push(res.url)
      galleryFileList.value.push({ name: res.path, url: res.url })
    } catch {
      // 错误消息由 http 拦截器统一提示
    }
  }

  /** 详情图移除 */
  const handleGalleryRemove = (file: UploadFile) => {
    const url = file.url || ''
    galleryUrls.value = galleryUrls.value.filter((u) => u !== url)
    galleryFileList.value = galleryFileList.value.filter((f) => f.url !== url)
  }

  /** SKU 列表编辑行 */
  interface SkuRow extends Sku {
    _editing: boolean
    _isNew: boolean
    priceYuan: number
  }

  const skuList = ref<SkuRow[]>([])

  /** 加载 SKU 列表 */
  const loadSkus = async (productId: number) => {
    try {
      const list = await getSkus(productId)
      skuList.value = (list || []).map((s) => ({
        ...s,
        status: !!s.status,
        priceYuan: Number(((Number(s.price) || 0) / 100).toFixed(2)),
        _editing: false,
        _isNew: false
      }))
    } catch {
      skuList.value = []
    }
  }

  /** 新增 SKU 行(内联编辑) */
  const addSkuRow = () => {
    skuList.value.push({
      product_id: editId.value ?? undefined,
      name: '',
      price: 0,
      priceYuan: 0,
      stock_type: formData.stock_type || 'card',
      sort: skuList.value.length,
      status: true,
      _editing: true,
      _isNew: true
    })
  }

  /** 编辑 SKU 行 */
  const editSkuRow = (row: SkuRow) => {
    row.priceYuan = Number(((Number(row.price) || 0) / 100).toFixed(2))
    row._editing = true
  }

  /** 取消 SKU 行编辑 */
  const cancelSkuRow = (row: SkuRow, index: number) => {
    if (row._isNew) {
      skuList.value.splice(index, 1)
    } else {
      row._editing = false
      row.priceYuan = Number(((Number(row.price) || 0) / 100).toFixed(2))
    }
  }

  /** 保存 SKU 行(新增/更新) */
  const saveSkuRow = async (row: SkuRow) => {
    if (!row.name?.trim()) {
      ElMessage.warning(t('zcard.product.skuNameRequired'))
      return
    }
    const payload: Sku = {
      product_id: row.product_id,
      name: row.name.trim(),
      price: Math.round((Number(row.priceYuan) || 0) * 100),
      stock_type: row.stock_type,
      sort: row.sort ?? 0,
      status: !!row.status
    }
    try {
      if (row._isNew || !row.id) {
        const created = await createSku(payload)
        Object.assign(row, created, {
          priceYuan: Number(((Number(created.price) || 0) / 100).toFixed(2)),
          status: !!created.status,
          _isNew: false,
          _editing: false
        })
        ElMessage.success(t('zcard.product.skuAdded'))
      } else {
        const updated = await updateSku(row.id, payload)
        Object.assign(row, updated, {
          priceYuan: Number(((Number(updated.price) || 0) / 100).toFixed(2)),
          status: !!updated.status,
          _editing: false
        })
        ElMessage.success(t('zcard.product.skuUpdated'))
      }
    } catch {
      // 错误消息由 http 拦截器统一提示
    }
  }

  /** 删除 SKU 行 */
  const deleteSkuRow = (row: SkuRow, index: number) => {
    ElMessageBox.confirm(t('zcard.product.deleteSkuConfirm', { name: row.name }), t('zcard.product.deleteSkuTitle'), {
      confirmButtonText: t('zcard.common.ok'),
      cancelButtonText: t('zcard.common.cancel'),
      type: 'warning'
    })
      .then(async () => {
        if (row.id) {
          try {
            await deleteSku(row.id)
            skuList.value.splice(index, 1)
            ElMessage.success(t('zcard.product.skuDeleted'))
          } catch {
            // 错误消息由 http 拦截器统一提示
          }
        } else {
          skuList.value.splice(index, 1)
        }
      })
      .catch(() => {
        // 用户取消
      })
  }

  /** 新增控件行 */
  const addControlRow = () => {
    controlList.value.push({
      type: 'text',
      label: '',
      name: '',
      required: false,
      options: ''
    })
  }

  /** 删除控件行 */
  const removeControlRow = (index: number) => {
    controlList.value.splice(index, 1)
  }

  /** 把后端 control_config 数组转为可编辑行 */
  const hydrateControls = (config: unknown) => {
    if (!Array.isArray(config)) {
      controlList.value = []
      return
    }
    controlList.value = config.map((c: Record<string, unknown>) => ({
      type: typeof c.type === 'string' ? c.type : 'text',
      label: typeof c.label === 'string' ? c.label : '',
      name: typeof c.name === 'string' ? c.name : '',
      required: !!c.required,
      options: Array.isArray(c.options) ? c.options.join(',') : typeof c.options === 'string' ? c.options : ''
    }))
  }

  /** 把可编辑行转为后端 control_config 数组 */
  const serializeControls = () =>
    controlList.value.map((row) => {
      const item: Record<string, unknown> = {
        type: row.type || 'text',
        label: row.label,
        name: row.name,
        required: !!row.required
      }
      if (row.type === 'select' && row.options) {
        item.options = row.options.split(',').map((s) => s.trim()).filter(Boolean)
      }
      return item
    })

  /** 打开新增抽屉 */
  const openCreate = () => {
    drawerType.value = 'create'
    editId.value = null
    activeTab.value = 'basic'
    actualStock.value = 0
    Object.assign(formData, createEmptyForm())
    populateMemberLevels(null)
    virtualReviewsText.value = ''
    controlList.value = []
    galleryUrls.value = []
    galleryFileList.value = []
    skuList.value = []
    drawerVisible.value = true
    // 后台异步加载会员等级(供会员价下拉使用)
    loadUserGroups()
  }

  /** 打开编辑抽屉 */
  const openEdit = async (row: Product) => {
    drawerType.value = 'edit'
    editId.value = row.id
    activeTab.value = 'basic'
    // 先用列表行预填,然后再拉详情补全
    Object.assign(formData, createEmptyForm(), {
      name: row.name,
      slug: row.slug || '',
      category_id: row.category_id,
      description: row.description || '',
      cover: row.cover || '',
      priceYuan: Number(((Number(row.price) || 0) / 100).toFixed(2)),
      stock_type: row.stock_type || 'card',
      stock_visible: row.stock_visible !== false,
      delivery_mode: row.delivery_mode || 'status',
      is_featured: !!row.is_featured,
      virtual_sales: Number(row.virtual_sales) || 0,
      min_order: Number(row.min_order) || 1,
      max_order: Number(row.max_order) || 0,
      sort: Number(row.sort) || 0,
      status: row.status
    })
    // 详情图回填
    const imgs = Array.isArray(row.images) ? row.images : []
    galleryUrls.value = [...imgs]
    galleryFileList.value = imgs.map((url, i) => ({ name: `image-${i}`, url }))
    controlList.value = []
    virtualReviewsText.value = ''
    populateMemberLevels(null)
    actualStock.value = row.stock ?? 0
    drawerVisible.value = true

    // 先确保会员等级列表已加载,再回填会员价(label 解析依赖 group 名称)
    await loadUserGroups()
    if (row.member_price && typeof row.member_price === 'object') {
      populateMemberLevels(row.member_price)
    }

    // 拉详情补全新字段 + SKU + 控件
    try {
      const detail = (await getProduct(row.id)) as Product & Record<string, unknown>
      Object.assign(formData, {
        contact_type: (detail.contact_type as string) || 'email',
        send_email: detail.send_email !== false,
        delivery_message: (detail.delivery_message as string) || '',
        leave_message: (detail.leave_message as string) || '',
        only_user: !!detail.only_user,
        purchase_limit: Number(detail.purchase_limit) || 0,
        hide: !!detail.hide,
        level_disable: !!detail.level_disable
      })
      // 详情图重新回填(详情接口可能更全)
      const detailImgs = Array.isArray(detail.images) ? detail.images : []
      if (detailImgs.length) {
        galleryUrls.value = [...detailImgs]
        galleryFileList.value = detailImgs.map((url, i) => ({ name: `image-${i}`, url }))
      }
      // 会员价回填(详情优先)
      if (detail.member_price && typeof detail.member_price === 'object') {
        populateMemberLevels(detail.member_price)
      }
      // 控件回填
      hydrateControls(detail.control_config)
      // 虚拟评价回填
      if (detail.virtual_reviews && typeof detail.virtual_reviews === 'object') {
        virtualReviewsText.value = JSON.stringify(detail.virtual_reviews)
      }
      // 实际库存(详情接口中的 cards count 通常不在 show,以列表 stock 兜底)
      if (typeof detail.stock === 'number') actualStock.value = detail.stock
    } catch {
      // 错误消息由 http 拦截器统一提示
    }
    await loadSkus(row.id)
  }

  /** 关闭抽屉后重置表单 */
  const resetForm = () => {
    formRef.value?.resetFields()
    Object.assign(formData, createEmptyForm())
    populateMemberLevels(null)
    virtualReviewsText.value = ''
    controlList.value = []
    galleryUrls.value = []
    galleryFileList.value = []
    skuList.value = []
    editId.value = null
    actualStock.value = 0
  }

  /** 提交表单(新增/编辑) */
  const handleSubmit = async () => {
    if (!formRef.value) return
    try {
      await formRef.value.validate()
    } catch {
      return
    }

    // 会员价(从可视化等级表序列化)
    const memberPrice = serializeMemberPrice()

    // 解析虚拟评价 JSON(可选)
    let virtualReviews: Record<string, unknown> | undefined
    const vrText = virtualReviewsText.value.trim()
    if (vrText) {
      try {
        const parsed = JSON.parse(vrText)
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
          virtualReviews = parsed as Record<string, unknown>
        } else {
          ElMessage.error(t('zcard.product.virtualReviewsHint'))
          return
        }
      } catch {
        ElMessage.error(t('zcard.product.virtualReviewsHint'))
        return
      }
    }

    const payload: Record<string, unknown> = {
      name: formData.name,
      slug: formData.slug || undefined,
      category_id: formData.category_id,
      description: formData.description,
      cover: formData.cover || undefined,
      images: galleryUrls.value.length ? galleryUrls.value : undefined,
      price: Math.round(formData.priceYuan * 100),
      stock_type: formData.stock_type,
      stock_visible: formData.stock_visible,
      delivery_mode: formData.delivery_mode,
      is_featured: formData.is_featured,
      virtual_sales: formData.virtual_sales,
      min_order: formData.min_order,
      max_order: formData.max_order,
      sort: formData.sort,
      status: formData.status,
      contact_type: formData.contact_type,
      send_email: formData.send_email,
      delivery_message: formData.delivery_message || undefined,
      leave_message: formData.leave_message || undefined,
      only_user: formData.only_user,
      purchase_limit: formData.purchase_limit,
      hide: formData.hide,
      level_disable: formData.level_disable,
      control_config: serializeControls()
    }
    if (memberPrice) payload.member_price = memberPrice
    if (virtualReviews) payload.virtual_reviews = virtualReviews

    submitting.value = true
    try {
      if (drawerType.value === 'create') {
        await createProduct(payload)
        ElMessage.success(t('zcard.product.created'))
      } else if (editId.value !== null) {
        await updateProduct(editId.value, payload)
        ElMessage.success(t('zcard.product.updated'))
      }
      drawerVisible.value = false
      fetchData()
      fetchStats()
    } catch {
      // 错误消息由 http 拦截器统一提示
    } finally {
      submitting.value = false
    }
  }

  /** 删除商品 */
  const handleDelete = (row: Product) => {
    ElMessageBox.confirm(t('zcard.product.deleteConfirm', { name: row.name }), t('zcard.product.deleteTitle'), {
      confirmButtonText: t('zcard.common.ok'),
      cancelButtonText: t('zcard.common.cancel'),
      type: 'warning'
    })
      .then(async () => {
        try {
          await deleteProduct(row.id)
          ElMessage.success(t('zcard.common.deleteSuccess'))
          fetchData()
          fetchStats()
        } catch {
          // 错误消息由 http 拦截器统一提示
        }
      })
      .catch(() => {
        // 用户取消
      })
  }

  // 暴露给模板使用的图标(避免 lint 未使用告警)
  void Document

  onMounted(() => {
    loadCategories()
    fetchData()
    fetchStats()
  })
</script>

<style lang="scss" scoped>
  .product-page {
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

  .stat-inactive .stat-icon {
    background: rgba(144, 147, 153, 0.12);
    color: #909399;
  }

  .stat-inactive .stat-number {
    color: #909399;
  }

  .stat-featured .stat-icon {
    background: rgba(230, 162, 60, 0.12);
    color: #e6a23c;
  }

  .stat-featured .stat-number {
    color: #e6a23c;
  }

  .stat-stock .stat-icon {
    background: rgba(144, 89, 233, 0.12);
    color: #9059e9;
  }

  .stat-stock .stat-number {
    color: #9059e9;
  }

  .stat-paid .stat-icon {
    background: rgba(245, 108, 108, 0.12);
    color: #f56c6c;
  }

  .stat-paid .stat-number {
    color: #f56c6c;
  }

  .search-bar {
    margin-bottom: 16px;
  }

  .table-header {
    display: flex;
    align-items: center;
    margin-bottom: 16px;

    .selection-count {
      margin-left: 12px;
      color: var(--el-text-color-secondary);
      font-size: 13px;
    }
  }

  .pagination-bar {
    display: flex;
    justify-content: flex-end;
    margin-top: 16px;
  }

  .product-form {
    padding-right: 8px;
  }

  .product-tabs {
    :deep(.el-tabs__content) {
      padding-top: 8px;
    }
  }

  .tab-toolbar {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 12px;
  }

  .control-alert {
    margin-bottom: 12px;
  }

  .form-hint {
    margin-left: 8px;
    color: var(--el-text-color-secondary);
    font-size: 12px;
  }

  .cover-placeholder,
  .cover-preview {
    width: 120px;
    height: 120px;
    border: 1px dashed var(--el-border-color);
    border-radius: 6px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: var(--el-text-color-secondary);
    font-size: 12px;
    overflow: hidden;
    position: relative;

    .upload-icon {
      font-size: 24px;
      margin-bottom: 6px;
    }
  }

  .cover-preview {
    border-style: solid;
  }

  .cover-img {
    width: 100%;
    height: 100%;
  }

  .cover-mask {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.2s;
  }

  .cover-preview:hover .cover-mask {
    opacity: 1;
  }

  .sku-empty {
    text-align: center;
    color: var(--el-text-color-secondary);
    font-size: 13px;
    padding: 16px 0;
  }

  .ml-2 {
    margin-left: 8px;
  }
</style>
