/**
 * SEO 工具:统一管理页面 <title> 与 meta(description / keywords / Open Graph)。
 * 切换路由/商品时调用,自动补全或替换对应标签。
 */
interface SeoOptions {
  title?: string
  description?: string
  keywords?: string
  /** 分享图(OG:image),商品封面等 */
  image?: string
  /** 页面类型(og:type),默认 website */
  type?: string
}

function upsertMeta(attr: 'name' | 'property', key: string, content: string): void {
  let el = document.head.querySelector<HTMLMetaElement>(`meta[${attr}="${key}"]`)
  if (!el) {
    el = document.createElement('meta')
    el.setAttribute(attr, key)
    document.head.appendChild(el)
  }
  el.setAttribute('content', content)
}

function removeMeta(attr: 'name' | 'property', key: string): void {
  document.head.querySelectorAll<HTMLMetaElement>(`meta[${attr}="${key}"]`).forEach((el) => el.remove())
}

/**
 * 设置页面 SEO。传空字符串会移除对应标签(如关键词为空时不给搜索引擎噪音)。
 */
export function setSeo(opts: SeoOptions): void {
  document.title = opts.title || ''

  if (opts.description) {
    upsertMeta('name', 'description', opts.description)
    upsertMeta('property', 'og:description', opts.description)
  } else {
    removeMeta('name', 'description')
    removeMeta('property', 'og:description')
  }

  if (opts.keywords) {
    upsertMeta('name', 'keywords', opts.keywords)
  } else {
    removeMeta('name', 'keywords')
  }

  if (opts.image) {
    upsertMeta('property', 'og:image', opts.image)
  } else {
    removeMeta('property', 'og:image')
  }

  if (opts.title) {
    upsertMeta('property', 'og:title', opts.title)
    upsertMeta('property', 'og:site_name', opts.title)
  }
  if (opts.type) {
    upsertMeta('property', 'og:type', opts.type)
  }
}
