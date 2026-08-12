function safeHttpUrl(value: string): URL | null {
  try {
    const url = new URL(value, window.location.origin)
    return url.protocol === 'https:' || url.protocol === 'http:' ? url : null
  } catch {
    return null
  }
}

/** 只允许跳转到 HTTP(S)，阻断 javascript:、data: 等可执行协议。 */
export function navigateToPaymentUrl(value: string): boolean {
  const url = safeHttpUrl(value)
  if (!url) return false
  window.location.assign(url.href)
  return true
}

/**
 * 将第三方表单解析后重新创建，仅保留 action/method 与 input 的 name/value。
 * 脚本、事件属性、SVG、iframe 等不可信节点不会进入当前页面 DOM。
 */
export function submitPaymentForm(formHtml: string): boolean {
  const documentNode = new DOMParser().parseFromString(formHtml, 'text/html')
  const sourceForm = documentNode.querySelector('form')
  if (!sourceForm) return false

  const action = safeHttpUrl(sourceForm.getAttribute('action') || '')
  if (!action) return false

  const method = (sourceForm.getAttribute('method') || 'POST').toUpperCase()
  if (method !== 'POST' && method !== 'GET') return false

  const form = document.createElement('form')
  form.action = action.href
  form.method = method
  form.style.display = 'none'

  sourceForm.querySelectorAll('input[name]').forEach((sourceInput) => {
    const name = sourceInput.getAttribute('name') || ''
    if (!name || name.length > 200) return

    const input = document.createElement('input')
    input.type = 'hidden'
    input.name = name
    input.value = sourceInput.getAttribute('value') || ''
    form.appendChild(input)
  })

  document.body.appendChild(form)
  form.submit()
  return true
}
