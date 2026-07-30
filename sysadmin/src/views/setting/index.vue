<!-- 店铺设置 - 后台管理 -->
<template>
  <div class="setting-page art-full-height">
    <ElCard v-loading="loading" class="art-table-card" shadow="never">
      <ElForm :model="form" label-width="140px" class="setting-form">
        <!-- 布局 -->
        <div class="section">
          <div class="section-title">{{ t('zcard.setting.layout') }}</div>
          <ElFormItem :label="t('zcard.setting.categoryNavStyle')">
            <ElRadioGroup v-model="form.category_nav_style">
              <ElRadio value="sidebar">{{ t('zcard.setting.navSidebar') }}</ElRadio>
              <ElRadio value="top">{{ t('zcard.setting.navTop') }}</ElRadio>
              <ElRadio value="drawer">{{ t('zcard.setting.navDrawer') }}</ElRadio>
            </ElRadioGroup>
          </ElFormItem>
          <ElFormItem :label="t('zcard.setting.listDefaultView')">
            <ElRadioGroup v-model="form.list_default_view">
              <ElRadio value="grid">{{ t('zcard.setting.viewGrid') }}</ElRadio>
              <ElRadio value="list">{{ t('zcard.setting.viewList') }}</ElRadio>
            </ElRadioGroup>
          </ElFormItem>
          <ElFormItem :label="t('zcard.setting.gridColumns')">
            <ElInputNumber
              v-model="form.grid_columns"
              :min="2"
              :max="6"
              controls-position="right"
            />
          </ElFormItem>
        </div>

        <!-- 展示项 -->
        <div class="section">
          <div class="section-title">{{ t('zcard.setting.displayItems') }}</div>
          <ElFormItem :label="t('zcard.setting.showStock')">
            <ElSwitch v-model="form.show_stock" />
          </ElFormItem>
          <ElFormItem :label="t('zcard.setting.showSales')">
            <ElSwitch v-model="form.show_sales" />
          </ElFormItem>
          <ElFormItem :label="t('zcard.setting.showPrice')">
            <ElSwitch v-model="form.show_price" />
          </ElFormItem>
          <ElFormItem :label="t('zcard.setting.showDescription')">
            <ElSwitch v-model="form.show_description" />
          </ElFormItem>
        </div>

        <!-- 首页推荐 -->
        <div class="section">
          <div class="section-title">{{ t('zcard.setting.featured') }}</div>
          <ElFormItem :label="t('zcard.setting.showFeatured')">
            <ElSwitch v-model="form.featured_enabled" />
          </ElFormItem>
          <ElFormItem :label="t('zcard.setting.featuredLimit')">
            <ElInputNumber
              v-model="form.featured_limit"
              :min="1"
              :max="50"
              controls-position="right"
            />
          </ElFormItem>
        </div>

        <!-- 热门标签 -->
        <div class="section">
          <div class="section-title">{{ t('zcard.setting.hotTags') }}</div>
          <ElFormItem :label="t('zcard.setting.showHotTags')">
            <ElSwitch v-model="form.hot_tags_enabled" />
          </ElFormItem>
          <ElFormItem :label="t('zcard.setting.hotTags')">
            <ElInput
              v-model="form.hot_tags"
              type="textarea"
              :rows="3"
              :placeholder="t('zcard.setting.hotTagsPlaceholder')"
            />
          </ElFormItem>
        </div>

        <!-- 下单设置 -->
        <div class="section">
          <div class="section-title">{{ t('zcard.setting.checkoutSettings') }}</div>
          <ElFormItem :label="t('zcard.setting.guestCheckout')">
            <ElSwitch v-model="form.guest_checkout" />
          </ElFormItem>
          <ElFormItem :label="t('zcard.setting.orderExpireMinutes')">
            <ElInputNumber
              v-model="form.order_expire_minutes"
              :min="1"
              :max="1440"
              controls-position="right"
            />
          </ElFormItem>
          <ElFormItem :label="t('zcard.setting.requireContact')">
            <ElSwitch v-model="form.require_contact" />
          </ElFormItem>
        </div>
      </ElForm>

      <div class="form-footer">
        <ElButton @click="loadSettings">{{ t('zcard.common.reset') }}</ElButton>
        <ElButton type="primary" :loading="saving" @click="handleSave">{{ t('zcard.setting.save') }}</ElButton>
      </div>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { ElMessage } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import { getSettings, updateSettings, type Settings } from '@/api/settings'

  defineOptions({ name: 'SettingIndex' })

  const { t } = useI18n()

  /**
   * 表单结构：所有字段在表单中显式声明，便于渲染。
   * 加载时把后端扁平配置合并进来；保存时只回传表单中存在的键。
   */
  interface SettingForm {
    // 布局
    category_nav_style: string
    list_default_view: string
    grid_columns: number
    // 展示项
    show_stock: boolean
    show_sales: boolean
    show_price: boolean
    show_description: boolean
    // 首页推荐
    featured_enabled: boolean
    featured_limit: number
    // 热门标签
    hot_tags_enabled: boolean
    hot_tags: string
    // 下单设置
    guest_checkout: boolean
    order_expire_minutes: number
    require_contact: boolean
  }

  const defaultForm = (): SettingForm => ({
    category_nav_style: 'sidebar',
    list_default_view: 'grid',
    grid_columns: 4,
    show_stock: true,
    show_sales: true,
    show_price: true,
    show_description: true,
    featured_enabled: true,
    featured_limit: 8,
    hot_tags_enabled: true,
    hot_tags: '',
    guest_checkout: false,
    order_expire_minutes: 30,
    require_contact: true
  })

  const form = reactive<SettingForm>(defaultForm())

  /** 原始后端配置，用于合并未知字段 */
  const raw = ref<Settings>({})

  const loading = ref(false)
  const saving = ref(false)

  /** 把后端任意值转成表单字段对应类型 */
  const coerce = <T,>(value: any, fallback: T): T => {
    if (value === undefined || value === null || value === '') return fallback
    return value as T
  }

  /** 加载设置 */
  const loadSettings = async () => {
    loading.value = true
    try {
      const data = await getSettings()
      raw.value = data || {}
      const d = defaultForm()
      Object.assign(form, {
        category_nav_style: coerce(data.category_nav_style, d.category_nav_style),
        list_default_view: coerce(data.list_default_view, d.list_default_view),
        grid_columns: Number(coerce(data.grid_columns, d.grid_columns)),
        show_stock: coerceBool(data.show_stock, d.show_stock),
        show_sales: coerceBool(data.show_sales, d.show_sales),
        show_price: coerceBool(data.show_price, d.show_price),
        show_description: coerceBool(data.show_description, d.show_description),
        featured_enabled: coerceBool(data.featured_enabled, d.featured_enabled),
        featured_limit: Number(coerce(data.featured_limit, d.featured_limit)),
        hot_tags_enabled: coerceBool(data.hot_tags_enabled, d.hot_tags_enabled),
        hot_tags: coerce(data.hot_tags, d.hot_tags),
        guest_checkout: coerceBool(data.guest_checkout, d.guest_checkout),
        order_expire_minutes: Number(
          coerce(data.order_expire_minutes, d.order_expire_minutes)
        ),
        require_contact: coerceBool(data.require_contact, d.require_contact)
      })
    } catch (e) {
      // 拦截器处理
    } finally {
      loading.value = false
    }
  }

  /** 宽松布尔转换（兼容字符串 "true"/"false"/"1"/"0"） */
  const coerceBool = (value: any, fallback: boolean): boolean => {
    if (value === undefined || value === null || value === '') return fallback
    if (typeof value === 'boolean') return value
    const s = String(value).toLowerCase().trim()
    if (s === 'true' || s === '1' || s === 'yes' || s === 'on') return true
    if (s === 'false' || s === '0' || s === 'no' || s === 'off') return false
    return fallback
  }

  /** 保存：合并表单 + 原始未知字段后回传 */
  const handleSave = async () => {
    saving.value = true
    try {
      const payload: Settings = { ...raw.value, ...form }
      await updateSettings(payload)
      raw.value = payload
      ElMessage.success(t('zcard.setting.saveSuccess'))
    } catch (e) {
      // 拦截器处理
    } finally {
      saving.value = false
    }
  }

  onMounted(() => {
    loadSettings()
  })
</script>

<style lang="scss" scoped>
  .setting-page {
    display: flex;
    flex-direction: column;
  }

  .section {
    padding: 16px 0;
    border-bottom: 1px solid var(--el-border-color-lighter);

    &:last-of-type {
      border-bottom: none;
    }
  }

  .section-title {
    margin-bottom: 16px;
    font-size: 16px;
    font-weight: 600;
    color: var(--el-text-color-primary);
  }

  .form-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 8px;
    padding-top: 16px;
    border-top: 1px solid var(--el-border-color-lighter);
  }
</style>
