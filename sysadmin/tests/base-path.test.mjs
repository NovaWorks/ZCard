import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { test } from 'node:test'
import { loadConfigFromFile } from 'vite'

const html = readFileSync(new URL('../../public/admin/index.html', import.meta.url), 'utf8')
const publicRoot = fileURLToPath(new URL('../../public/', import.meta.url))

test('clean production config has deployable defaults', async () => {
  const result = await loadConfigFromFile(
    { command: 'build', mode: 'production' },
    fileURLToPath(new URL('../vite.config.ts', import.meta.url))
  )

  assert.equal(result?.config.base, '/admin/')
  assert.equal(result?.config.define?.['import.meta.env.VITE_API_URL'], JSON.stringify('/api'))
  assert.equal(result?.config.define?.__APP_VERSION__, JSON.stringify('3.0.2'))
})

test('committed sysadmin entry points assets below /admin/', () => {
  const assets = [...html.matchAll(/(?:src|href)="([^"]+\/assets\/[^"?]+)"/g)].map(
    ([, value]) => value
  )

  assert.ok(assets.length > 0, 'the admin entry should reference generated assets')
  assert.ok(
    assets.every((asset) => asset.startsWith('/admin/assets/')),
    assets.join('\n')
  )
  assert.ok(
    assets.every((asset) => existsSync(resolve(publicRoot, asset.slice(1)))),
    assets.join('\n')
  )
})
