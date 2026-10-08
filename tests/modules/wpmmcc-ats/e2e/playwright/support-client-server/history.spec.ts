import { test, expect, Page } from '@playwright/test';
import { resolveSlotClientBase } from '../lib/e2e-slot-ports'

const BASE = resolveSlotClientBase();
// ─── OAuth 登录辅助（与 app.spec.ts 保持一致）────────────────────────────────
async function doOAuthLogin(page: Page) {
  await page.goto(BASE);
  const status = await fetch(`${BASE}/api/status`).then(r => r.json());
  if (status?.data?.logged_in) {
    await page.reload();
    await page.waitForSelector('nav', { timeout: 5000 });
    return;
  }

  const [popup] = await Promise.all([
    page.waitForEvent('popup'),
    page.click('button:has-text("通过浏览器登录")'),
  ]);

  await popup.waitForLoadState('domcontentloaded');
  try {
    await popup.waitForSelector('input[type="email"], input[name="email"]', { timeout: 10000 });
    await popup.fill('input[type="email"], input[name="email"]', 'admin@wptsall.dev');
    await popup.fill('input[type="password"]', 'demo');
    await popup.click('button[type="submit"], input[type="submit"]');
  } catch { /* 可能已登录直接进授权页 */ }

  try {
    await popup.waitForEvent('close', { timeout: 30000 });
  } catch { /* 弹窗可能已关闭 */ }

  await page.waitForSelector('nav.min-h-screen', { timeout: 15000 });
}

// ─── 辅助：点击侧边栏导航 ───────────────────────────────────────────────────
async function goTo(page: Page, label: string) {
  await page.click(`nav button:has-text("${label}")`);
  await page.waitForTimeout(300);
}

// ─── Mock 数据 ───────────────────────────────────────────────────────────────
function makeRecord(overrides: Partial<{
  id: number;
  created_at: number;
  domain: string;
  relation_id: number;
  object_id: number;
  object_type: string;
  business_line: string;
  source_lang: string;
  target_lang: string;
  status: string;
  execution_ms: number;
  worker_id: string;
  idempotency_key: string;
  callback_retries: number;
  fields_count: number;
  error_message: string | null;
}> = {}) {
  return {
    id: 1,
    created_at: 1740000000,
    domain: 'example.com',
    relation_id: 5,
    object_id: 42,
    object_type: 'post',
    business_line: 'post_content',
    source_lang: 'en',
    target_lang: 'zh-CN',
    status: 'success',
    execution_ms: 1234,
    worker_id: 'worker-abc',
    idempotency_key: 'key-1',
    callback_retries: 0,
    fields_count: 3,
    error_message: null,
    ...overrides,
  };
}

function mockTranslationsResponse(records: ReturnType<typeof makeRecord>[], total?: number, page = 1, limit = 20) {
  return {
    success: true,
    data: {
      records,
      total: total ?? records.length,
      page,
      limit,
    },
  };
}

// ─── 翻译历史页测试组 ────────────────────────────────────────────────────────
test.describe('翻译历史页', () => {

  // ─── 测试 1：可以通过侧边栏导航到达 ────────────────────────────────────────
  test('翻译历史页面可以通过侧边栏导航到达', async ({ page }) => {
    await doOAuthLogin(page);
    await goTo(page, '翻译历史');
    await expect(page.locator('h2:has-text("翻译历史")')).toBeVisible();
    console.log('翻译历史：侧边栏导航成功');
  });

  // ─── 测试 2：空状态显示"暂无翻译记录" ──────────────────────────────────────
  test('空状态时显示"暂无翻译记录"', async ({ page }) => {
    // Mock /api/translations to return empty list before page loads
    await page.route('**/api/translations**', async route => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockTranslationsResponse([])),
      });
    });

    await doOAuthLogin(page);
    await goTo(page, '翻译历史');
    await expect(page.locator('h2:has-text("翻译历史")')).toBeVisible();

    await expect(page.locator('td:has-text("暂无翻译记录")')).toBeVisible();
    console.log('翻译历史：空状态文案验证通过');
  });

  // ─── 测试 3：显示翻译记录列表 ──────────────────────────────────────────────
  test('显示翻译记录列表', async ({ page }) => {
    const records = [
      makeRecord({ id: 1, status: 'success' }),
      makeRecord({ id: 2, status: 'failed', error_message: '翻译超时' }),
      makeRecord({ id: 3, status: 'pending_callback' }),
    ];

    await page.route('**/api/translations**', async route => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockTranslationsResponse(records)),
      });
    });

    await doOAuthLogin(page);
    await goTo(page, '翻译历史');
    await expect(page.locator('h2:has-text("翻译历史")')).toBeVisible();

    // 等待表格渲染（非空状态）
    await expect(page.locator('table tbody tr').first()).toBeVisible();

    // 3 条记录 = 3 个主行（不含展开详情行）
    // 每条记录渲染一个 tr，不含 colspan 的 tr 就是数据行
    const dataRows = page.locator('table tbody tr:not([class*="bg-blue"])');
    await expect(dataRows).toHaveCount(3);

    // 状态徽章：成功 / 失败 / 待回调
    await expect(page.locator('span:has-text("成功")')).toBeVisible();
    await expect(page.locator('span:has-text("失败")')).toBeVisible();
    await expect(page.locator('span:has-text("待回调")')).toBeVisible();

    console.log('翻译历史：3 条记录和状态徽章验证通过');
  });

  // ─── 测试 4：域名过滤器过滤记录 ────────────────────────────────────────────
  test('域名过滤器过滤记录', async ({ page }) => {
    const allRecords = [
      makeRecord({ id: 1, domain: 'example.com' }),
      makeRecord({ id: 2, domain: 'other.com' }),
    ];
    const filteredRecords = [
      makeRecord({ id: 1, domain: 'example.com' }),
    ];

    let callCount = 0;
    await page.route('**/api/translations**', async route => {
      const url = route.request().url();
      callCount++;
      if (url.includes('domain=example.com')) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(mockTranslationsResponse(filteredRecords)),
        });
      } else {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(mockTranslationsResponse(allRecords)),
        });
      }
    });

    await doOAuthLogin(page);
    await goTo(page, '翻译历史');
    await expect(page.locator('h2:has-text("翻译历史")')).toBeVisible();

    // 等待初始加载完成（2 条记录可见）
    await expect(page.locator('table tbody tr').first()).toBeVisible();

    // 填写域名过滤器并等待重新请求
    const domainInput = page.locator('input[placeholder*="域名过滤"]');
    await domainInput.fill('example.com');

    // 等待过滤后结果更新（只有 1 条记录）
    await page.waitForTimeout(500);
    const dataRows = page.locator('table tbody tr:not([class*="bg-blue"])');
    await expect(dataRows).toHaveCount(1);
    await expect(page.locator('td:has-text("example.com")')).toBeVisible();

    console.log(`翻译历史：域名过滤触发了 ${callCount} 次 API 调用，结果正确`);
  });

  // ─── 测试 5：状态过滤器过滤记录 ────────────────────────────────────────────
  test('状态过滤器过滤记录', async ({ page }) => {
    const allRecords = [
      makeRecord({ id: 1, status: 'success' }),
      makeRecord({ id: 2, status: 'failed' }),
    ];
    const successOnly = [
      makeRecord({ id: 1, status: 'success' }),
    ];

    await page.route('**/api/translations**', async route => {
      const url = route.request().url();
      if (url.includes('status=success')) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(mockTranslationsResponse(successOnly)),
        });
      } else {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(mockTranslationsResponse(allRecords)),
        });
      }
    });

    await doOAuthLogin(page);
    await goTo(page, '翻译历史');
    await expect(page.locator('h2:has-text("翻译历史")')).toBeVisible();

    // 等待初始加载（2 条）
    await expect(page.locator('table tbody tr').first()).toBeVisible();
    const statusSelect = page.locator('select').first();
    await statusSelect.selectOption('success');

    // 等待过滤后只剩 1 条
    await page.waitForTimeout(500);
    const dataRows = page.locator('table tbody tr:not([class*="bg-blue"])');
    await expect(dataRows).toHaveCount(1);

    // 应只显示"成功"徽章，不显示"失败"徽章
    await expect(page.locator('span:has-text("成功")')).toBeVisible();
    await expect(page.locator('span:has-text("失败")')).not.toBeVisible();

    console.log('翻译历史：状态过滤器验证通过');
  });

  // ─── 测试 6：点击行展开详情 ────────────────────────────────────────────────
  test('点击行展开详情', async ({ page }) => {
    const records = [
      makeRecord({ id: 7, relation_id: 5, object_type: 'post' }),
    ];

    await page.route('**/api/translations**', async route => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockTranslationsResponse(records)),
      });
    });

    await doOAuthLogin(page);
    await goTo(page, '翻译历史');
    await expect(page.locator('h2:has-text("翻译历史")')).toBeVisible();

    // 等待数据行出现
    const firstRow = page.locator('table tbody tr').first();
    await expect(firstRow).toBeVisible();

    // 展开详情前，确认展开行不存在
    await expect(page.locator('td:has-text("关系 ID")')).not.toBeVisible();

    // 点击行展开
    await firstRow.click();
    await page.waitForTimeout(300);

    // 展开详情行应出现
    await expect(page.locator('span:has-text("关系 ID")')).toBeVisible();

    // 关系 ID 的值 "5" 应可见
    const detailCells = page.locator('tr.bg-blue-50 td');
    await expect(detailCells.first()).toBeVisible();
    await expect(page.locator('tr.bg-blue-50').filter({ hasText: '关系 ID' })).toContainText('5');

    console.log('翻译历史：展开详情行验证通过，关系 ID=5 可见');
  });

  // ─── 测试 7：分页控制按钮存在 ──────────────────────────────────────────────
  test('分页控制按钮存在', async ({ page }) => {
    // 构造 20 条记录，total=100（多页）
    const records = Array.from({ length: 20 }, (_, i) =>
      makeRecord({ id: i + 1, object_id: i + 1 })
    );

    await page.route('**/api/translations**', async route => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockTranslationsResponse(records, 100, 1, 20)),
      });
    });

    await doOAuthLogin(page);
    await goTo(page, '翻译历史');
    await expect(page.locator('h2:has-text("翻译历史")')).toBeVisible();

    // 等待表格有数据
    await expect(page.locator('table tbody tr').first()).toBeVisible();

    // "下一页"按钮存在且可点击（第 1 页，page < totalPages=5）
    const nextBtn = page.locator('button:has-text("下一页")');
    await expect(nextBtn).toBeVisible();
    await expect(nextBtn).toBeEnabled();

    // "上一页"按钮存在但禁用（第 1 页）
    const prevBtn = page.locator('button:has-text("上一页")');
    await expect(prevBtn).toBeVisible();
    await expect(prevBtn).toBeDisabled();

    console.log('翻译历史：分页按钮状态验证通过（首页：上一页禁用，下一页可用）');
  });

  // ─── 测试 8：清除过滤按钮重置筛选器 ───────────────────────────────────────
  test('清除过滤按钮重置筛选器', async ({ page }) => {
    await page.route('**/api/translations**', async route => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockTranslationsResponse([])),
      });
    });

    await doOAuthLogin(page);
    await goTo(page, '翻译历史');
    await expect(page.locator('h2:has-text("翻译历史")')).toBeVisible();

    // 填写域名过滤器
    const domainInput = page.locator('input[placeholder*="域名过滤"]');
    await domainInput.fill('test.com');
    await expect(domainInput).toHaveValue('test.com');

    // 点击"清除过滤"按钮
    await page.click('button:has-text("清除过滤")');
    await page.waitForTimeout(300);

    // 域名过滤器应被清空
    await expect(domainInput).toHaveValue('');
    console.log('翻译历史：清除过滤按钮重置验证通过');
  });

}); // end test.describe
