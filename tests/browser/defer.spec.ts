// 状態層なしで rosanna-defer.js だけが動くこと
import { spawn, type ChildProcess } from 'node:child_process';
import { resolve } from 'node:path';
import { expect, test } from '@playwright/test';

let php: ChildProcess;
let baseURL: string;

test.beforeAll(async () => {
  php = spawn('php', ['-d', 'display_errors=0', '-S', '127.0.0.1:0', resolve(__dirname, 'fixtures/app.php')]);
  baseURL = await new Promise<string>((ready, failed) => {
    const timer = setTimeout(() => failed(new Error('php -S did not start')), 15_000);
    const onData = (chunk: Buffer) => {
      const started = chunk.toString().match(/Development Server \((http:\/\/[^)]+)\) started/);
      if (started) {
        clearTimeout(timer);
        ready(started[1]);
      }
    };
    php.stdout?.on('data', onData);
    php.stderr?.on('data', onData);
  });
});

test.afterAll(() => {
  php.kill('SIGKILL');
});

test('断片が取得され、セッションも rosanna.js も使わない', async ({ page }) => {
  const requested: string[] = [];
  const cookies: string[] = [];
  page.on('response', (response) => {
    requested.push(new URL(response.url()).pathname);
    const cookie = response.headers()['set-cookie'];
    if (cookie) cookies.push(cookie);
  });
  await page.goto(`${baseURL}/`);

  await expect(page.locator('#plain')).toHaveText('plain a');
  await expect(page.locator('#cached')).toHaveText('cached');
  await expect(page.locator('#count')).toHaveText(/^count=\d+$/);
  await expect(page.locator('#host-form')).toHaveCount(1);

  expect(requested).not.toContain('/rosanna.js');
  expect(cookies).toEqual([]);
  // 登録されていない名前はフォールバックのまま
  await expect(page.locator('#unregistered')).toHaveText('still');
});

test('localCache は再読み込みで使われ、フォームを含む断片は保存されない', async ({ page }) => {
  await page.goto(`${baseURL}/`);
  await expect(page.locator('#cached')).toHaveText('cached');
  await expect(page.locator('#host-form')).toHaveCount(1);
  const keys = await page.evaluate(() => Object.keys(localStorage));
  expect(keys).toContain('rosanna:defer:/_defer/cached');
  expect(keys).not.toContain('rosanna:defer:/_defer/with-form');
  // host の CSRF トークンの名前を知らなくても保存しない
  expect(await page.evaluate(() => JSON.stringify(localStorage))).not.toContain('host-secret');

  const hits: string[] = [];
  page.on('request', (request) => hits.push(new URL(request.url()).pathname));
  await page.reload();
  await expect(page.locator('#cached')).toHaveText('cached');
  await expect(page.locator('#plain')).toHaveText('plain a');
  expect(hits).not.toContain('/_defer/cached');
  expect(hits).toContain('/_defer/with-form');
});

test('data-rosanna-reload-defer と reloadDefer で取得し直す', async ({ page }) => {
  await page.goto(`${baseURL}/`);
  const count = page.locator('#count');
  await expect(count).toHaveText(/^count=\d+$/);
  const value = async () => Number((await count.textContent())?.replace('count=', ''));

  const first = await value();
  await page.locator('#reload').click();
  await expect.poll(value).toBeGreaterThan(first);

  const second = await value();
  const reloaded = await page.evaluate(() => (window as unknown as { Rosanna: { reloadDefer(name: string): number } }).Rosanna.reloadDefer('count'));
  expect(reloaded).toBe(1);
  await expect.poll(value).toBeGreaterThan(second);
});

test('外部のスクリプトが読めないときはフォールバックが 1 回取得する', async ({ page }) => {
  await page.goto(`${baseURL}/?src=/missing.js`);
  await expect(page.locator('#plain')).toHaveText('plain a');
  expect(await page.evaluate(() => Object.keys(localStorage))).toEqual([]);
});

for (const [label, query] of [['本体', ''], ['フォールバック', '&src=/missing.js']] as const) {
  test(`JS-16: prefix の外へのリダイレクトの応答を挿入しない (${label})`, async ({ page }) => {
    const moved = page.waitForResponse((response) => new URL(response.url()).pathname === '/login');
    await page.goto(`${baseURL}/?scene=moved${query}`);
    await moved;
    await expect(page.locator('[aria-busy]')).toHaveCount(0);
    await expect(page.locator('#login')).toHaveCount(0);
    await expect(page.locator('#moved-fallback')).toHaveText('still');
  });

  test(`DF-6: 自分を含む断片を取得し続けない (${label})`, async ({ page }) => {
    const hits: string[] = [];
    page.on('request', (request) => {
      const { pathname } = new URL(request.url());
      if (pathname === '/_defer/self-loop') hits.push(pathname);
    });
    await page.goto(`${baseURL}/?scene=self-loop${query}`);
    await expect(page.locator('#self-loop-stop')).toHaveText('stop');
    await page.waitForTimeout(300);
    expect(hits).toHaveLength(1);
  });
}

test('JS-18: 親を取得し直すと、ネストした子も取得し直す', async ({ page }) => {
  await page.goto(`${baseURL}/?scene=parent`);
  const child = page.locator('#child');
  await expect(child).toHaveText(/^child=\d+$/);
  const value = async () => Number((await child.textContent())?.replace('child=', ''));
  const first = await value();

  await page.evaluate(() => (window as unknown as { Rosanna: { reloadDefer(name: string): number } }).Rosanna.reloadDefer('parent'));

  await expect.poll(value).toBeGreaterThan(first);
});

test('JS-17: template の中の hidden も localStorage に書かない', async ({ page }) => {
  await page.goto(`${baseURL}/?scene=template`);
  await expect(page.locator('template')).toHaveCount(1);
  expect(await page.evaluate(() => JSON.stringify(localStorage))).not.toContain('template-secret');
});

test('JS-18: localStorage が満杯でもキャッシュを読めて、clearDeferCache で消せる', async ({ page }) => {
  await page.goto(`${baseURL}/`);
  await expect(page.locator('#cached')).toHaveText('cached');
  await page.evaluate(() => {
    let chunk = 'x'.repeat(1024 * 1024);
    let index = 0;
    while (chunk.length >= 1) {
      try {
        localStorage.setItem(`host:${index++}`, chunk);
      } catch {
        chunk = chunk.slice(0, Math.floor(chunk.length / 2));
      }
    }
  });

  const hits: string[] = [];
  page.on('request', (request) => hits.push(new URL(request.url()).pathname));
  await page.reload();
  await expect(page.locator('#cached')).toHaveText('cached');
  expect(hits).not.toContain('/_defer/cached');

  const left = await page.evaluate(() => {
    (window as unknown as { Rosanna: { clearDeferCache(name?: string): void } }).Rosanna.clearDeferCache();
    return Object.keys(localStorage).filter((key) => key.startsWith('rosanna:defer:/'));
  });
  expect(left).toEqual([]);
});

test('JS-16: url が空の応答 (Service Worker が作ったもの) はリダイレクトとして扱わない', async ({ page }) => {
  await page.addInitScript(() => {
    const original = window.fetch;
    window.fetch = async (input, init) => {
      const url = String(input);
      if (!url.includes('/_defer/plain')) return original(input, init);
      return new Response('<p id="synthesized">synthesized</p>', { headers: { 'Content-Type': 'text/html' } });
    };
  });
  await page.goto(`${baseURL}/`);
  await expect(page.locator('#synthesized')).toHaveText('synthesized');
});
