import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

test('生产构建使用本地 Fontsource 字体', async () => {
    const projectRoot = new URL('../../', import.meta.url);
    const viteConfig = await readFile(new URL('vite.config.js', projectRoot), 'utf8');
    const packageJson = JSON.parse(await readFile(new URL('package.json', projectRoot), 'utf8'));

    assert.match(viteConfig, /fontsource\('Instrument Sans'/);
    assert.doesNotMatch(viteConfig, /\b(?:bunny|google)\(/);
    assert.equal(packageJson.devDependencies['@fontsource/instrument-sans'], '5.3.0');
});
