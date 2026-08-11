<!-- 店铺设置 - 选项卡布局 -->
<template>
  <div class="setting-page art-full-height">
    <ElCard v-loading="loading" class="art-table-card setting-card" shadow="never">
      <ElTabs v-model="activeTab" class="setting-tabs">
        <ElTabPane :label="t('zcard.setting.tabSite')" name="site">
          <ElForm :model="form" label-width="auto" class="setting-form">
            <ElFormItem :label="t('zcard.setting.siteName')">
              <ElInput v-model="form.site_name" placeholder="ZCard" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.siteUrl')">
              <ElInput v-model="form.site_url" placeholder="https://example.com" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.siteLogo')">
              <ImagePicker v-model="form.site_logo" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.siteDescription')">
              <ElInput v-model="form.site_description" type="textarea" :rows="2" :placeholder="t('zcard.setting.siteDescriptionPlaceholder')" maxlength="120" show-word-limit />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.siteKeywords')">
              <ElInput v-model="form.site_keywords" :placeholder="t('zcard.setting.siteKeywordsPlaceholder')" />
              <span class="form-tip">{{ t('zcard.setting.siteKeywordsTip') }}</span>
            </ElFormItem>
            <ElDivider content-position="left">{{ t('zcard.setting.brandBar') }}</ElDivider>
            <ElFormItem :label="t('zcard.setting.brandSlogan')">
              <ElInput v-model="form.brand_slogan" :placeholder="t('zcard.setting.brandSloganPlaceholder')" maxlength="100" show-word-limit />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.brandSloganEn')">
              <ElInput v-model="form.brand_slogan_en" :placeholder="t('zcard.setting.brandSloganPlaceholder')" maxlength="160" show-word-limit />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.brandSecure')">
              <ElInput v-model="form.brand_secure" :placeholder="t('zcard.setting.brandSecurePlaceholder')" maxlength="30" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.brandSecureEn')">
              <ElInput v-model="form.brand_secure_en" :placeholder="t('zcard.setting.brandSecurePlaceholder')" maxlength="30" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.brandPrivacy')">
              <ElInput v-model="form.brand_privacy" :placeholder="t('zcard.setting.brandPrivacyPlaceholder')" maxlength="30" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.brandPrivacyEn')">
              <ElInput v-model="form.brand_privacy_en" :placeholder="t('zcard.setting.brandPrivacyPlaceholder')" maxlength="30" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.footerCopyright')">
              <ElInput v-model="form.footer_copyright" :placeholder="t('zcard.setting.footerCopyrightPlaceholder')" />
            </ElFormItem>
          </ElForm>
        </ElTabPane>

        <ElTabPane :label="t('zcard.setting.tabFooter')" name="footer">
          <ElForm :model="form" label-width="auto" class="setting-form">
            <ElFormItem :label="t('zcard.setting.footerAbout')">
              <ElInput v-model="form.footer_about" type="textarea" :rows="3" :placeholder="t('zcard.setting.footerAboutPlaceholder')" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.footerLinks')">
              <ElInput v-model="form.footerLinksJson" type="textarea" :rows="5" placeholder="JSON" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.footerContact')">
              <ElInput v-model="form.footerContactJson" type="textarea" :rows="5" placeholder="JSON" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.footerSocial')">
              <ElInput v-model="form.footerSocialJson" type="textarea" :rows="6" placeholder="JSON" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.footerHelpLinks')">
              <ElInput v-model="form.footerHelpLinksJson" type="textarea" :rows="4" placeholder="JSON" />
              <span class="form-tip">{{ t('zcard.setting.footerHelpLinksTip') }}</span>
              <div class="mt-2 flex items-center gap-2">
                <ElButton size="small" type="primary" plain @click="form.footerHelpLinksJson = DEMO_HELP_LINKS_JSON">
                  {{ t('zcard.setting.footerHelpLinksFillDemo') }}
                </ElButton>
                <span class="form-tip">{{ t('zcard.setting.footerHelpLinksDemoTitle') }}</span>
              </div>
              <!-- 中英文双语预览:实时解析当前 JSON,展示两种语言下的页脚效果 -->
              <div v-if="helpLinksPreview.length" class="mt-2 help-links-preview">
                <div class="preview-title">{{ t('zcard.setting.footerHelpLinksPreview') }}</div>
                <div v-for="(h, idx) in helpLinksPreview" :key="idx" class="preview-row">
                  <span class="preview-lang">{{ t('zcard.setting.langZh') }}</span>
                  <span class="preview-item">{{ h.title || '-' }}</span>
                  <span class="preview-lang">{{ t('zcard.setting.langEn') }}</span>
                  <span class="preview-item">{{ h.title_en || h.title || '-' }}</span>
                </div>
              </div>
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.footerAnalytics')">
              <ElInput v-model="form.footer_analytics" type="textarea" :rows="5" :placeholder="t('zcard.setting.footerAnalyticsPlaceholder')" />
            </ElFormItem>
          </ElForm>
        </ElTabPane>

        <ElTabPane :label="t('zcard.setting.tabDisplay')" name="display">
          <ElForm :model="form" label-width="auto" class="setting-form">
            <ElFormItem :label="t('zcard.setting.categoryNavStyle')">
              <ElRadioGroup v-model="form.category_nav_style">
                <ElRadio value="sidebar">{{ t('zcard.setting.navSidebar') }}</ElRadio>
                <ElRadio value="pills">{{ t('zcard.setting.navTop') }}</ElRadio>
                <ElRadio value="combo">{{ t('zcard.setting.navDrawer') }}</ElRadio>
              </ElRadioGroup>
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.listDefaultView')">
              <ElRadioGroup v-model="form.list_default_view">
                <ElRadio value="grid">{{ t('zcard.setting.viewGrid') }}</ElRadio>
                <ElRadio value="list">{{ t('zcard.setting.viewList') }}</ElRadio>
                <ElRadio value="dual">{{ t('zcard.setting.viewDual') }}</ElRadio>
              </ElRadioGroup>
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.gridColumns')">
              <ElInputNumber v-model="form.grid_columns" :min="2" :max="6" controls-position="right" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.pageSize')">
              <ElInputNumber v-model="form.page_size" :min="6" :max="60" controls-position="right" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.defaultOrder')">
              <ElSelect v-model="form.default_order" style="width: 200px">
                <ElOption label="最新上架" value="newest" />
                <ElOption label="销量优先" value="sales" />
                <ElOption label="价格升序" value="price_asc" />
                <ElOption label="价格降序" value="price_desc" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.showStock')">
              <ElSwitch v-model="form.show_stock" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.showOutOfStock')">
              <ElSwitch v-model="form.show_out_of_stock" />
              <span class="form-tip">{{ t('zcard.setting.showOutOfStockTip') }}</span>
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
            <ElFormItem :label="t('zcard.setting.showReviews')">
              <ElSwitch v-model="form.show_reviews" />
            </ElFormItem>
          </ElForm>
        </ElTabPane>

        <ElTabPane :label="t('zcard.setting.tabFeatured')" name="featured">
          <ElForm :model="form" label-width="auto" class="setting-form">
            <ElFormItem :label="t('zcard.setting.showFeatured')">
              <ElSwitch v-model="form.show_featured" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.featuredCount')">
              <ElInputNumber v-model="form.featured_count" :min="1" :max="50" controls-position="right" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.showHotTags')">
              <ElSwitch v-model="form.show_hot_tags" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.hotTags')">
              <ElInput v-model="form.hotTagCategoriesJson" type="textarea" :rows="4" :placeholder="t('zcard.setting.hotTagsPlaceholder')" />
              <div class="text-xs text-ink-soft mt-1 leading-relaxed">
                <div>{{ t('zcard.setting.hotTagsFormatTip') }}</div>
                <div class="mt-0.5">{{ t('zcard.setting.hotTagsFormatExample') }}</div>
              </div>
            </ElFormItem>
          </ElForm>
        </ElTabPane>

        <ElTabPane :label="t('zcard.setting.tabTrade')" name="trade">
          <ElForm :model="form" label-width="auto" class="setting-form">
            <ElFormItem :label="t('zcard.setting.guestCheckout')">
              <ElSwitch v-model="form.guest_checkout" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.orderCloseMinutes')">
              <ElInputNumber v-model="form.order_close_minutes" :min="1" :max="1440" controls-position="right" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.requireContact')">
              <ElSwitch v-model="form.require_contact" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.contactType')">
              <ElSelect v-model="form.contact_type" style="width: 200px">
                <ElOption label="邮箱" value="email" />
                <ElOption label="手机号" value="phone" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.orderQueryPassword')">
              <ElSwitch v-model="form.order_query_password" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.orderQueryFaqs')">
              <div class="faq-editor">
                <div v-for="(item, idx) in form.order_query_faqs" :key="idx" class="faq-item">
                  <div class="faq-item-head">
                    <span class="faq-index">#{{ idx + 1 }}</span>
                    <ElButton link type="danger" size="small" @click="removeFaq(idx)">
                      {{ t('zcard.setting.faqRemove') }}
                    </ElButton>
                  </div>
                  <ElInput v-model="item.q" :placeholder="t('zcard.setting.faqQuestion')" size="small" />
                  <ElInput
                    v-model="item.a"
                    type="textarea"
                    :rows="2"
                    :placeholder="t('zcard.setting.faqAnswer')"
                    size="small"
                    class="faq-answer"
                  />
                </div>
                <ElButton type="primary" plain size="small" class="faq-add" @click="addFaq">
                  + {{ t('zcard.setting.faqAdd') }}
                </ElButton>
              </div>
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.tradeCaptcha')">
              <ElSwitch v-model="form.trade_captcha" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.allowPostReview')">
              <ElSwitch v-model="form.allow_post_review" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.reviewNeedAudit')">
              <ElSwitch v-model="form.review_need_audit" />
            </ElFormItem>
          </ElForm>
        </ElTabPane>

        <!-- Tab: 安全设置 -->
        <ElTabPane :label="t('zcard.setting.tabSecurity')" name="security">
          <ElForm :model="form" label-width="auto" class="setting-form">
            <ElFormItem :label="t('zcard.setting.registerOpen')">
              <ElSwitch v-model="form.register_open" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.registerType')">
              <ElSelect v-model="form.register_type" style="width: 200px">
                <ElOption label="邮箱注册" value="email" />
                <ElOption label="用户名注册" value="username" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.usernameMinLength')">
              <ElInputNumber v-model="form.username_min_length" :min="1" :max="32" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.captchaRegister')">
              <ElSwitch v-model="form.captcha_register" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.captchaLogin')">
              <ElSwitch v-model="form.captcha_login" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.forgetType')">
              <ElSelect v-model="form.forget_type" style="width: 200px">
                <ElOption label="邮箱找回" value="email" />
                <ElOption label="手机找回" value="sms" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.cardEncryption')">
              <div class="encrypt-control">
                <ElSwitch v-model="form.card_encryption_enabled" @change="onEncryptionToggle" />
                <span v-if="form.card_encryption_enabled" class="encrypt-hint">
                  {{ t('zcard.setting.cardEncryptionOnTip') }}
                </span>
                <span v-else class="encrypt-hint">{{ t('zcard.setting.cardEncryptionOffTip') }}</span>
              </div>
              <div v-if="encryptionRiskCards > 0" class="encrypt-risk">
                <ArtSvgIcon icon="ri:alert-line" /> {{ t('zcard.setting.cardEncryptionRisk', { n: encryptionRiskCards }) }}
              </div>
            </ElFormItem>
            <ElFormItem v-if="form.card_encryption_enabled" :label="t('zcard.setting.cardEncryptionKey')">
              <div class="encrypt-control">
                <ElInput
                  v-model="cardEncryptionKey"
                  type="password"
                  show-password
                  :placeholder="t('zcard.setting.cardEncryptionKeyPlaceholder')"
                  style="width: 320px"
                />
                <ElButton @click="generateEncryptionKey">{{ t('zcard.setting.cardEncryptionRandom') }}</ElButton>
              </div>
              <div class="field-help">{{ t('zcard.setting.cardEncryptionKeyTip') }}</div>
            </ElFormItem>
          </ElForm>
        </ElTabPane>

        <!-- Tab: 系统运维 -->
        <ElTabPane :label="t('zcard.setting.tabSystem')" name="system">
          <ElForm :model="form" label-width="auto" class="setting-form">
            <ElFormItem :label="t('zcard.setting.maintenanceMode')">
              <ElSwitch v-model="form.maintenance_mode" />
              <span class="form-tip">{{ t('zcard.setting.maintenanceTip') }}</span>
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.maintenanceMessage')">
              <ElInput v-model="form.maintenance_message" type="textarea" :rows="2" :placeholder="t('zcard.setting.maintenanceMessagePlaceholder')" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.paymentCallbackDomain')">
              <ElInput v-model="form.payment_callback_domain" placeholder="https://example.com" />
              <span class="form-tip">{{ t('zcard.setting.paymentCallbackDomainTip') }}</span>
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.siteNotice')">
              <ArtWangEditor v-model="form.site_notice" mode="default" :placeholder="t('zcard.setting.siteNoticePlaceholder')" />
              <span class="form-tip">{{ t('zcard.setting.siteNoticeHint') }}</span>
            </ElFormItem>
          </ElForm>
        </ElTabPane>

        <!-- Tab: 邮件设置 -->
        <ElTabPane :label="t('zcard.setting.tabMail')" name="mail">
          <ElForm :model="form" label-width="auto" class="setting-form">
            <ElFormItem :label="t('zcard.setting.mailEnabled')">
              <ElSwitch v-model="form.mail_enabled" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.mailHost')">
              <ElInput v-model="form.mail_host" placeholder="smtp.example.com" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.mailPort')">
              <ElInputNumber v-model="form.mail_port" :min="1" :max="65535" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.mailEncryption')">
              <ElSelect v-model="form.mail_encryption" style="width: 120px">
                <ElOption label="SSL" value="ssl" />
                <ElOption label="TLS" value="tls" />
                <ElOption label="无" value="" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.mailUsername')">
              <ElInput v-model="form.mail_username" placeholder="SMTP 用户名" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.mailPassword')">
              <ElInput v-model="form.mail_password" type="password" show-password placeholder="SMTP 密码/授权码" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.mailFromAddress')">
              <ElInput v-model="form.mail_from_address" placeholder="noreply@example.com" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.mailFromName')">
              <ElInput v-model="form.mail_from_name" placeholder="ZCard" />
            </ElFormItem>
          </ElForm>
        </ElTabPane>

        <!-- Tab: 短信设置 -->
        <ElTabPane :label="t('zcard.setting.tabSms')" name="sms">
          <ElForm :model="form" label-width="auto" class="setting-form">
            <ElFormItem :label="t('zcard.setting.smsEnabled')">
              <ElSwitch v-model="form.sms_enabled" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.smsPlatform')">
              <ElSelect v-model="form.sms_platform" style="width: 200px">
                <ElOption label="阿里云短信" value="aliyun" />
                <ElOption label="腾讯云短信" value="tencent" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.smsAccessKey')">
              <ElInput v-model="form.sms_access_key" placeholder="AccessKey ID" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.smsAccessSecret')">
              <ElInput v-model="form.sms_access_secret" type="password" show-password placeholder="AccessKey Secret" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.smsSignName')">
              <ElInput v-model="form.sms_sign_name" placeholder="短信签名" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.smsTemplateCode')">
              <ElInput v-model="form.sms_template_code" placeholder="验证码模板 CODE" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.smsDeliveryTemplateCode')">
              <ElInput v-model="form.sms_delivery_template_code" placeholder="发货通知模板 CODE" />
            </ElFormItem>
          </ElForm>
        </ElTabPane>

        <!-- Tab: 提现设置 -->
        <ElTabPane :label="t('zcard.setting.tabCash')" name="cash">
          <ElForm :model="form" label-width="auto" class="setting-form">
            <ElFormItem :label="t('zcard.setting.cashMin')">
              <ElInputNumber v-model="form.cash_min" :min="0" :precision="2" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.cashFee')">
              <ElInputNumber v-model="form.cash_fee" :min="0" :precision="2" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.cashTypeAlipay')">
              <ElSwitch v-model="form.cash_type_alipay" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.cashTypeWechat')">
              <ElSwitch v-model="form.cash_type_wechat" />
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.cashTypeUsdt')">
              <ElSwitch v-model="form.cash_type_usdt" />
            </ElFormItem>
          </ElForm>
        </ElTabPane>

        <!-- Tab: 多语言与货币 -->
        <ElTabPane :label="t('zcard.setting.tabLocale')" name="locale">
          <ElForm :model="form" label-width="auto" class="setting-form">
            <ElFormItem :label="t('zcard.setting.baseCurrency')">
              <ElSelect v-model="form.base_currency" style="width: 220px">
                <ElOption
                  v-for="c in currencies"
                  :key="c.code"
                  :label="`${c.code} ${c.symbol} ${c.name}`"
                  :value="c.code"
                />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.defaultDisplayCurrency')">
              <ElSelect v-model="form.default_display_currency" style="width: 220px">
                <ElOption v-for="c in currencies" :key="c.code" :label="`${c.code} ${c.symbol}`" :value="c.code" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.enabledLanguages')">
              <ElSelect v-model="form.enabled_languages" multiple style="width: 320px">
                <ElOption label="中文" value="zh" />
                <ElOption label="English" value="en" />
              </ElSelect>
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.defaultLanguage')">
              <ElSelect v-model="form.default_language" style="width: 220px">
                <ElOption label="中文" value="zh" />
                <ElOption label="English" value="en" />
              </ElSelect>
            </ElFormItem>
          </ElForm>
        </ElTabPane>

        <!-- Tab: 分销设置 -->
        <ElTabPane :label="t('zcard.setting.tabDistribution')" name="distribution">
          <ElForm :model="form" label-width="auto" class="setting-form">
            <ElFormItem :label="t('zcard.setting.distributionEnabled')">
              <ElSwitch v-model="form.distribution_enabled" />
              <span class="form-tip">{{ t('zcard.setting.distributionEnabledTip') }}</span>
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.rateL1')">
              <ElInputNumber v-model="form.distribution_rate_l1" :min="0" :max="100" :precision="2" /> %
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.rateL2')">
              <ElInputNumber v-model="form.distribution_rate_l2" :min="0" :max="100" :precision="2" /> %
            </ElFormItem>
            <ElFormItem :label="t('zcard.setting.rateL3')">
              <ElInputNumber v-model="form.distribution_rate_l3" :min="0" :max="100" :precision="2" /> %
            </ElFormItem>
          </ElForm>
        </ElTabPane>

        <!-- Tab: 货源对接 -->
        <ElTabPane :label="t('zcard.setting.tabSupply')" name="supply">
          <ElForm :model="form" label-width="auto" class="setting-form supply-form">
            <ElFormItem class="supply-form-item" :label="t('zcard.setting.supplyEnabled')">
              <ElSwitch v-model="form.supply_enabled" />
              <span class="form-tip">{{ t('zcard.setting.supplyEnabledTip') }}</span>
            </ElFormItem>
            <ElFormItem class="supply-form-item" :label="t('zcard.setting.supplyUpstreamEnabled')">
              <ElSwitch v-model="form.supply_upstream_enabled" />
              <span class="form-tip">{{ t('zcard.setting.supplyUpstreamEnabledTip') }}</span>
            </ElFormItem>
            <ElFormItem class="supply-form-item" :label="t('zcard.setting.supplySupplierEnabled')">
              <ElSwitch v-model="form.supply_supplier_enabled" />
              <span class="form-tip">{{ t('zcard.setting.supplySupplierEnabledTip') }}</span>
            </ElFormItem>
            <ElFormItem class="supply-form-item" :label="t('zcard.setting.supplyNonceStore')">
              <ElSelect v-model="form.supply_nonce_store" style="width: 220px">
                <ElOption label="Cache (默认)" value="cache" />
                <ElOption label="Redis" value="redis" />
                <ElOption label="Database" value="database" />
              </ElSelect>
              <span class="form-tip">{{ t('zcard.setting.supplyNonceStoreTip') }}</span>
            </ElFormItem>
            <ElFormItem class="supply-form-item" :label="t('zcard.setting.supplyRateLimit')">
              <ElInputNumber v-model="form.supply_rate_limit" :min="1" :max="1000" />
              <span class="form-tip">{{ t('zcard.setting.supplyRateLimitTip') }}</span>
            </ElFormItem>
            <ElFormItem class="supply-form-item" :label="t('zcard.setting.supplyTimestampSkew')">
              <ElInputNumber v-model="form.supply_timestamp_skew" :min="60" :max="3600" />
              <span class="form-tip">{{ t('zcard.setting.supplyTimestampSkewTip') }}</span>
            </ElFormItem>
          </ElForm>
        </ElTabPane>
      </ElTabs>

      <div class="form-footer">
        <ElButton @click="loadSettings">{{ t('zcard.common.reset') }}</ElButton>
        <ElButton type="primary" :loading="saving" @click="handleSave">{{ t('zcard.setting.save') }}</ElButton>
      </div>
    </ElCard>
  </div>
</template>

<script setup lang="ts">
  import { ElMessage, ElMessageBox } from 'element-plus'
  import { useI18n } from 'vue-i18n'
  import { getSettings, updateSettings, type Settings } from '@/api/settings'
  import { getCurrencies, type Currency } from '@/api/currency'
  import ImagePicker from '@/components/business/image-picker/index.vue'

  defineOptions({ name: 'SettingIndex' })

  const { t } = useI18n()

  /**
   * 帮助中心演示示例:含中英双语 title_en(前台英文界面时显示,缺省回退 title)。
   * 「填入示例」按钮点击后填充到 footerHelpLinksJson 输入框。
   */
  const DEMO_HELP_LINKS_JSON = JSON.stringify([
    { title: '常见问题', title_en: 'FAQ', url: '/orders/query' },
    { title: '购买须知', title_en: 'Purchase Guide', url: '' },
    { title: '售后服务', title_en: 'After-Sales', url: '' },
  ], null, 2)

  const activeTab = ref<'site' | 'footer' | 'display' | 'featured' | 'trade' | 'security' | 'system' | 'mail' | 'sms' | 'cash' | 'locale' | 'distribution' | 'supply'>('site')

  interface SettingForm {
    site_name: string
    site_url: string
    site_logo: string
    site_description: string
    site_keywords: string
    brand_slogan: string
    brand_slogan_en: string
    brand_secure: string
    brand_secure_en: string
    brand_privacy: string
    brand_privacy_en: string
    footer_copyright: string
    footer_about: string
    footer_analytics: string
    footerLinksJson: string
    footerContactJson: string
    footerSocialJson: string
    footerHelpLinksJson: string
    hotTagCategoriesJson: string
    category_nav_style: string
    list_default_view: string
    grid_columns: number
    page_size: number
    default_order: string
    show_stock: boolean
    show_out_of_stock: boolean
    show_sales: boolean
    show_price: boolean
    show_description: boolean
    show_reviews: boolean
    show_featured: boolean
    featured_count: number
    show_hot_tags: boolean
    guest_checkout: boolean
    order_close_minutes: number
    require_contact: boolean
    contact_type: string
    order_query_password: boolean
    order_query_faqs: { q: string; a: string }[]
    trade_captcha: boolean
    card_encryption_enabled: boolean
    allow_post_review: boolean
    review_need_audit: boolean
    // 安全设置
    register_open: boolean
    register_type: string
    captcha_register: boolean
    captcha_login: boolean
    forget_type: string
    username_min_length: number
    // 系统运维
    maintenance_mode: boolean
    maintenance_message: string
    payment_callback_domain: string
    site_notice: string
    // 邮件设置
    mail_enabled: boolean
    mail_host: string
    mail_port: number
    mail_encryption: string
    mail_username: string
    mail_password: string
    mail_from_address: string
    mail_from_name: string
    // 短信设置
    sms_enabled: boolean
    sms_platform: string
    sms_access_key: string
    sms_access_secret: string
    sms_sign_name: string
    sms_template_code: string
    sms_delivery_template_code: string
    // 提现设置
    cash_min: number
    cash_fee: number
    cash_type_alipay: boolean
    cash_type_wechat: boolean
    cash_type_usdt: boolean
    // 多语言与货币
    base_currency: string
    default_display_currency: string
    enabled_languages: string[]
    default_language: string
    // 分销设置
    distribution_enabled: boolean
    distribution_rate_l1: number
    distribution_rate_l2: number
    distribution_rate_l3: number
    // 货源对接
    supply_enabled: boolean
    supply_upstream_enabled: boolean
    supply_supplier_enabled: boolean
    supply_nonce_store: string
    supply_rate_limit: number
    supply_timestamp_skew: number
  }

  const defaultForm = (): SettingForm => ({
    site_name: 'ZCard',
    site_url: '',
    site_logo: '',
    site_description: '',
    site_keywords: '',
    brand_slogan: '',
    brand_slogan_en: '',
    brand_secure: '',
    brand_secure_en: '',
    brand_privacy: '',
    brand_privacy_en: '',
    footer_copyright: '',
    footer_about: '',
    footer_analytics: '',
    footerLinksJson: '[]',
    footerContactJson: '[]',
    footerSocialJson: '[]',
    footerHelpLinksJson: '[]',
    hotTagCategoriesJson: '[]',
    category_nav_style: 'pills',
    list_default_view: 'grid',
    grid_columns: 4,
    page_size: 12,
    default_order: 'newest',
    show_stock: true,
    show_out_of_stock: true,
    show_sales: true,
    show_price: true,
    show_description: true,
    show_reviews: false,
    show_featured: true,
    featured_count: 8,
    show_hot_tags: true,
    guest_checkout: true,
    order_close_minutes: 15,
    require_contact: true,
    contact_type: 'email',
    order_query_password: true,
    order_query_faqs: [] as { q: string; a: string }[],
    trade_captcha: true,
    card_encryption_enabled: false,
    allow_post_review: true,
    review_need_audit: true,
    register_open: true,
    register_type: 'email',
    captcha_register: false,
    captcha_login: false,
    forget_type: 'email',
    username_min_length: 3,
    maintenance_mode: false,
    maintenance_message: '系统维护中,请稍后再来访问。',
    payment_callback_domain: '',
    site_notice: '',
    mail_enabled: false,
    mail_host: '',
    mail_port: 465,
    mail_encryption: 'ssl',
    mail_username: '',
    mail_password: '',
    mail_from_address: '',
    mail_from_name: 'ZCard',
    sms_enabled: false,
    sms_platform: 'aliyun',
    sms_access_key: '',
    sms_access_secret: '',
    sms_sign_name: '',
    sms_template_code: '',
    sms_delivery_template_code: '',
    cash_min: 100,
    cash_fee: 5,
    cash_type_alipay: true,
    cash_type_wechat: true,
    cash_type_usdt: true,
    base_currency: 'CNY',
    default_display_currency: 'CNY',
    enabled_languages: ['zh'],
    default_language: 'zh',
    distribution_enabled: false,
    distribution_rate_l1: 10,
    distribution_rate_l2: 5,
    distribution_rate_l3: 2,
    // 货源对接
    supply_enabled: false,
    supply_upstream_enabled: true,
    supply_supplier_enabled: true,
    supply_nonce_store: 'cache',
    supply_rate_limit: 60,
    supply_timestamp_skew: 300,
  })

  const form = reactive<SettingForm>(defaultForm())
  const raw = ref<Settings>({})
  const loading = ref(false)
  const saving = ref(false)
  /** 卡密加密密钥输入(不回显,留空=保持) */
  const cardEncryptionKey = ref('')
  /** 开启加密时的历史卡密数量(>0 则提示风险) */
  const encryptionRiskCards = ref(0)

  /** 随机生成 32 字节 base64 密钥(与 .env 的 CARD_ENCRYPTION_KEY 同格式) */
  const generateEncryptionKey = () => {
    const bytes = new Uint8Array(32)
    crypto.getRandomValues(bytes)
    const base64 = btoa(String.fromCharCode(...bytes))
    cardEncryptionKey.value = 'base64:' + base64
  }

  /** 开启加密开关:若已有历史卡密,前端先提示风险 */
  const onEncryptionToggle = (val: string | number | boolean) => {
    if (val && encryptionRiskCards.value > 0) {
      ElMessageBox.confirm(
        t('zcard.setting.cardEncryptionRisk', { n: encryptionRiskCards.value }) + '\n' + t('zcard.setting.cardEncryptionRiskConfirm'),
        t('zcard.common.tips'),
        { type: 'warning', confirmButtonText: t('zcard.common.ok'), cancelButtonText: t('zcard.common.cancel') }
      )
        .then(() => {})
        .catch(() => {
          form.card_encryption_enabled = false
        })
    }
  }
  const currencies = ref<Currency[]>([])

  const coerceBool = (value: any, fallback: boolean): boolean => {
    if (value === undefined || value === null || value === '') return fallback
    if (typeof value === 'boolean') return value
    const s = String(value).toLowerCase().trim()
    if (['true', '1', 'yes', 'on'].includes(s)) return true
    if (['false', '0', 'no', 'off'].includes(s)) return false
    return fallback
  }

  const coerce = <T,>(value: any, fallback: T): T => {
    if (value === undefined || value === null || value === '') return fallback
    return value as T
  }

  const toText = (v: any): string => {
    if (Array.isArray(v)) return JSON.stringify(v, null, 2)
    if (typeof v === 'string' && v.trim().startsWith('[')) {
      try { return JSON.stringify(JSON.parse(v), null, 2) } catch { return v }
    }
    return '[]'
  }

  /** 帮助中心双语预览:解析当前 JSON,返回 [{title, title_en}] 列表(无效 JSON 返回空) */
  const helpLinksPreview = computed(() => {
    const arr = parseArr(form.footerHelpLinksJson)
    if (!Array.isArray(arr)) return []
    return arr
      .filter((h) => h && typeof h === 'object')
      .map((h) => ({ title: h.title || '', title_en: h.title_en || '' }))
  })

  const parseArr = (text: string): any[] | null => {
    // 1. 标准 JSON 数组
    try {
      const v = JSON.parse(text)
      return Array.isArray(v) ? v : null
    } catch {
      /* 继续尝试逗号分隔 */
    }
    // 2. 兼容「逗号分隔的分类 ID/名称」(老用户习惯,如: 1,3,5)
    const trimmed = text.trim()
    if (trimmed && !trimmed.startsWith('[')) {
      const parts = trimmed.split(/[,，\s]+/).filter(Boolean)
      if (parts.length) {
        return parts.map((p) => {
          const n = Number(p)
          return Number.isNaN(n) ? p : n
        })
      }
    }
    return null
  }

  const coerceArray = (value: any, fallback: string[]): string[] => {
    if (Array.isArray(value)) return value.map((v) => String(v))
    if (typeof value === 'string' && value.trim().startsWith('[')) {
      try {
        const v = JSON.parse(value)
        if (Array.isArray(v)) return v.map((x: any) => String(x))
      } catch { /* fall through */ }
    }
    return fallback
  }

  const loadSettings = async () => {
    loading.value = true
    try {
      const [data, currencyList] = await Promise.all([
        getSettings(),
        getCurrencies().catch(() => [] as Currency[]),
      ])
      raw.value = data || {}
      // 历史卡密数量(开启加密时的风险提示依据)
      encryptionRiskCards.value = Number(data?.card_count) || 0
      currencies.value = currencyList || []
      const d = defaultForm()
      Object.assign(form, {
        site_name: coerce(data.site_name, d.site_name),
        site_url: coerce(data.site_url, d.site_url),
        site_logo: coerce(data.site_logo, d.site_logo),
        site_description: coerce(data.site_description, d.site_description),
        site_keywords: coerce(data.site_keywords, d.site_keywords),
        brand_slogan: coerce(data.brand_slogan, d.brand_slogan),
        brand_slogan_en: coerce(data.brand_slogan_en, d.brand_slogan_en),
        brand_secure: coerce(data.brand_secure, d.brand_secure),
        brand_secure_en: coerce(data.brand_secure_en, d.brand_secure_en),
        brand_privacy: coerce(data.brand_privacy, d.brand_privacy),
        brand_privacy_en: coerce(data.brand_privacy_en, d.brand_privacy_en),
        footer_copyright: coerce(data.footer_copyright, d.footer_copyright),
        footer_about: coerce(data.footer_about, d.footer_about),
        footer_analytics: coerce(data.footer_analytics, d.footer_analytics),
        category_nav_style: coerce(data.category_nav_style, d.category_nav_style),
        list_default_view: coerce(data.list_default_view, d.list_default_view),
        grid_columns: Number(coerce(data.grid_columns, d.grid_columns)),
        page_size: Number(coerce(data.page_size, d.page_size)),
        default_order: coerce(data.default_order, d.default_order),
        show_stock: coerceBool(data.show_stock, d.show_stock),
        show_out_of_stock: coerceBool(data.show_out_of_stock, d.show_out_of_stock),
        show_sales: coerceBool(data.show_sales, d.show_sales),
        show_price: coerceBool(data.show_price, d.show_price),
        show_description: coerceBool(data.show_description, d.show_description),
        show_reviews: coerceBool(data.show_reviews, d.show_reviews),
        show_featured: coerceBool(data.show_featured, d.show_featured),
        featured_count: Number(coerce(data.featured_count, d.featured_count)),
        show_hot_tags: coerceBool(data.show_hot_tags, d.show_hot_tags),
        guest_checkout: coerceBool(data.guest_checkout, d.guest_checkout),
        order_close_minutes: Number(coerce(data.order_close_minutes, d.order_close_minutes)),
        require_contact: coerceBool(data.require_contact, d.require_contact),
        contact_type: coerce(data.contact_type, d.contact_type),
        order_query_password: coerceBool(data.order_query_password, d.order_query_password),
        order_query_faqs: Array.isArray(data.order_query_faqs)
          ? data.order_query_faqs.map((f: any) => ({ q: String(f.q || ''), a: String(f.a || '') }))
          : [],
        trade_captcha: coerceBool(data.trade_captcha, d.trade_captcha),
        card_encryption_enabled: coerceBool(data.card_encryption_enabled, d.card_encryption_enabled),
        allow_post_review: coerceBool(data.allow_post_review, d.allow_post_review),
        review_need_audit: coerceBool(data.review_need_audit, d.review_need_audit),
        register_open: coerceBool(data.register_open, d.register_open),
        register_type: coerce(data.register_type, d.register_type),
        captcha_register: coerceBool(data.captcha_register, d.captcha_register),
        captcha_login: coerceBool(data.captcha_login, d.captcha_login),
        forget_type: coerce(data.forget_type, d.forget_type),
        username_min_length: Number(coerce(data.username_min_length, d.username_min_length)),
        maintenance_mode: coerceBool(data.maintenance_mode, d.maintenance_mode),
        maintenance_message: coerce(data.maintenance_message, d.maintenance_message),
        payment_callback_domain: coerce(data.payment_callback_domain, d.payment_callback_domain),
        site_notice: coerce(data.site_notice, d.site_notice),
        mail_enabled: coerceBool(data.mail_enabled, d.mail_enabled),
        mail_host: coerce(data.mail_host, d.mail_host),
        mail_port: Number(coerce(data.mail_port, d.mail_port)),
        mail_encryption: coerce(data.mail_encryption, d.mail_encryption),
        mail_username: coerce(data.mail_username, d.mail_username),
        mail_password: coerce(data.mail_password, d.mail_password),
        mail_from_address: coerce(data.mail_from_address, d.mail_from_address),
        mail_from_name: coerce(data.mail_from_name, d.mail_from_name),
        sms_enabled: coerceBool(data.sms_enabled, d.sms_enabled),
        sms_platform: coerce(data.sms_platform, d.sms_platform),
        sms_access_key: coerce(data.sms_access_key, d.sms_access_key),
        sms_access_secret: coerce(data.sms_access_secret, d.sms_access_secret),
        sms_sign_name: coerce(data.sms_sign_name, d.sms_sign_name),
        sms_template_code: coerce(data.sms_template_code, d.sms_template_code),
        sms_delivery_template_code: coerce(data.sms_delivery_template_code, d.sms_delivery_template_code),
        cash_min: Number(coerce(data.cash_min, d.cash_min)),
        cash_fee: Number(coerce(data.cash_fee, d.cash_fee)),
        cash_type_alipay: coerceBool(data.cash_type_alipay, d.cash_type_alipay),
        cash_type_wechat: coerceBool(data.cash_type_wechat, d.cash_type_wechat),
        cash_type_usdt: coerceBool(data.cash_type_usdt, d.cash_type_usdt),
        base_currency: coerce(data.base_currency, d.base_currency),
        default_display_currency: coerce(data.default_display_currency, d.default_display_currency),
        enabled_languages: coerceArray(data.enabled_languages, d.enabled_languages),
        default_language: coerce(data.default_language, d.default_language),
        distribution_enabled: coerceBool(data.distribution_enabled, d.distribution_enabled),
        distribution_rate_l1: Number(coerce(data.distribution_rate_l1, d.distribution_rate_l1)),
        distribution_rate_l2: Number(coerce(data.distribution_rate_l2, d.distribution_rate_l2)),
        distribution_rate_l3: Number(coerce(data.distribution_rate_l3, d.distribution_rate_l3)),
        supply_enabled: coerceBool(data.supply_enabled, d.supply_enabled),
        supply_upstream_enabled: coerceBool(data.supply_upstream_enabled, d.supply_upstream_enabled),
        supply_supplier_enabled: coerceBool(data.supply_supplier_enabled, d.supply_supplier_enabled),
        supply_nonce_store: coerce(data.supply_nonce_store, d.supply_nonce_store),
        supply_rate_limit: Number(coerce(data.supply_rate_limit, d.supply_rate_limit)),
        supply_timestamp_skew: Number(coerce(data.supply_timestamp_skew, d.supply_timestamp_skew)),
      })
      form.footerLinksJson = toText(data.footer_links)
      form.footerContactJson = toText(data.footer_contact)
      form.footerSocialJson = toText(data.footer_social)
      form.footerHelpLinksJson = toText(data.footer_help_links)
      form.hotTagCategoriesJson = toText(data.hot_tag_categories)
    } catch (e) {
      // 拦截器处理
    } finally {
      loading.value = false
    }
  }

  /** 常见问题(FAQ)编辑器 */
  const addFaq = () => {
    form.order_query_faqs.push({ q: '', a: '' })
  }
  const removeFaq = (idx: number) => {
    form.order_query_faqs.splice(idx, 1)
  }

  const handleSave = async () => {
    const links = parseArr(form.footerLinksJson)
    const contact = parseArr(form.footerContactJson)
    const social = parseArr(form.footerSocialJson)
    const helpLinks = parseArr(form.footerHelpLinksJson)
    const hotTags = parseArr(form.hotTagCategoriesJson)
    if (links === null || contact === null || social === null || helpLinks === null || hotTags === null) {
      ElMessage.error(t('zcard.setting.jsonFormatError'))
      return
    }
    saving.value = true
    try {
      const payload: Settings = {
        ...raw.value,
        ...form,
        footer_links: links,
        footer_contact: contact,
        footer_social: social,
        footer_help_links: helpLinks,
        hot_tag_categories: hotTags,
      }
      delete (payload as any).footerLinksJson
      delete (payload as any).footerContactJson
      delete (payload as any).footerSocialJson
      delete (payload as any).footerHelpLinksJson
      delete (payload as any).hotTagCategoriesJson
      // 卡密加密密钥:仅在填写时提交(留空=保持原值);回显值为脱敏占位,不得覆盖
      if (cardEncryptionKey.value.trim()) {
        payload.card_encryption_key = cardEncryptionKey.value.trim()
      }
      await updateSettings(payload)
      raw.value = payload
      cardEncryptionKey.value = ''
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

  .setting-card {
    display: flex;
    flex-direction: column;

    :deep(.el-card__body) {
      display: flex;
      flex-direction: column;
      flex: 1;
      padding-bottom: 0;
    }
  }

  .setting-tabs {
    flex: 1;

    :deep(.el-tabs__content) {
      max-height: calc(100vh - 280px);
      overflow-y: auto;
      padding-right: 4px;
    }
  }

  /* 帮助中心双语预览 */
  .help-links-preview {
    margin-top: 8px;
    padding: 8px 10px;
    border: 1px dashed var(--el-border-color);
    border-radius: 6px;
    background: var(--el-fill-color-lighter);

    .preview-title {
      font-size: 12px;
      font-weight: 600;
      color: var(--el-text-color-primary);
      margin-bottom: 6px;
    }

    .preview-row {
      display: flex;
      align-items: baseline;
      gap: 8px;
      padding: 2px 0;
      font-size: 12px;

      .preview-lang {
        flex-shrink: 0;
        color: var(--el-color-primary);
        font-weight: 500;
        width: 52px;
      }

      .preview-item {
        color: var(--el-text-color-regular);
      }
    }
  }

  .setting-form {
    max-width: 640px;
    padding-top: 8px;
  }

  /* 货源对接表单:开关与说明文字留间距,说明换行,行距加大 */
  .supply-form :deep(.el-form-item) {
    margin-bottom: 22px;
  }
  .supply-form-item :deep(.el-form-item__content) {
    display: flex;
    align-items: flex-start;
    gap: 14px;
  }
  .supply-form-item :deep(.el-form-item__content) > * {
    flex-shrink: 0;
  }
  .supply-form-item .form-tip {
    display: block;
    flex: 1;
    min-width: 0;
    font-size: 12px;
    color: var(--el-text-color-secondary);
    line-height: 1.7;
    padding-top: 6px;
  }

  /* 常见问题(FAQ)编辑器 */
  .field-help {
    font-size: 12px;
    color: var(--el-text-color-secondary);
    line-height: 1.5;
    margin-top: 4px;
  }
  /* 卡密加密配置 */
  .encrypt-control {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .encrypt-hint {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }
  .encrypt-risk {
    margin-top: 6px;
    font-size: 12px;
    color: var(--el-color-danger);
    line-height: 1.5;
  }
  .faq-editor {
    width: 100%;
    display: flex;
    flex-direction: column;
    gap: 10px;
  }
  .faq-item {
    border: 1px solid var(--el-border-color-lighter);
    border-radius: 6px;
    padding: 8px 10px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    background: var(--el-fill-color-lighter);
  }
  .faq-item-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .faq-index {
    font-size: 12px;
    font-weight: 600;
    color: var(--el-text-color-secondary);
  }
  .faq-answer {
    width: 100%;
  }
  .faq-add {
    align-self: flex-start;
  }

  .form-footer {
    position: sticky;
    bottom: 0;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 12px 0;
    margin-top: 8px;
    background: var(--el-bg-color);
    border-top: 1px solid var(--el-border-color-lighter);
    z-index: 10;
  }
</style>
