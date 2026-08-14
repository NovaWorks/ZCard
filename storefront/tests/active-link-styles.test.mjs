import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

test('全局路由高亮只作用于精确匹配链接', async () => {
  const css = await readFile(new URL('../src/assets/main.css', import.meta.url), 'utf8')

  assert.doesNotMatch(css, /\.router-link-active\s*\{[^}]*color\s*:/s)
  assert.match(css, /\.router-link-exact-active\s*\{[^}]*color:\s*var\(--color-primary\)/s)
})
