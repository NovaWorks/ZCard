import request from './request'

/** 页脚/导航链接项 */
export interface FooterLink { title: string; url: string }
/** 客服联系方式项 */
export interface FooterContact { label: string; value: string }
/** 社交/外部链接项 */
export interface FooterSocial { name: string; icon: string; url: string }

export interface StorefrontSettings {
  // 布局
  category_nav_style: 'pills' | 'sidebar' | 'combo'
  list_default_view: 'grid' | 'list' | 'dual'
  grid_columns: number
  page_size: number
  default_order: string
  // 展示项
  show_stock: boolean
  show_sales: boolean
  show_reviews: boolean
  show_price: boolean
  show_description: boolean
  // 推荐
  show_featured: boolean
  featured_count: number
  show_hot_tags: boolean
  hot_tag_categories: number[]
  // 交易
  order_query_password: boolean
  order_query_faqs: { q: string; a: string }[]
  trade_captcha: boolean
  order_close_minutes: number
  contact_type: string
  guest_checkout: boolean
  require_contact: boolean
  allow_post_review: boolean
  review_need_audit: boolean
  // 安全
  register_open: boolean
  register_type: string
  captcha_register: boolean
  captcha_login: boolean
  forget_type: string
  username_min_length: number
  // 系统运维
  maintenance_mode: boolean
  maintenance_message: string
  site_notice: string
  // 站点
  site_name: string
  site_url: string
  site_logo: string
  site_description: string
  site_keywords?: string
  // 顶部品牌条(后台可配置,英文留空回退中文)
  brand_slogan?: string
  brand_slogan_en?: string
  brand_secure?: string
  brand_secure_en?: string
  brand_privacy?: string
  brand_privacy_en?: string
  // 页脚
  footer_about: string
  footer_links: FooterLink[]
  footer_contact: FooterContact[]
  footer_social: FooterSocial[]
  footer_help_links: FooterLink[]
  footer_copyright: string
  footer_analytics: string
  // 邮件/短信/提现(后台配置,前台一般不直接使用)
  mail_enabled: boolean
  sms_enabled: boolean
  cash_min: number
  cash_fee: number
  // 多语言/多货币
  base_currency: string
  default_display_currency: string
  enabled_languages: string[]
  default_language: string
  [key: string]: any
}

export const getStorefrontSettings = () =>
  request.get<unknown, StorefrontSettings>('/settings/storefront')
