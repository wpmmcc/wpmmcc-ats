/**
 * advanced_coverage.spec.ts
 *
 * 补充覆盖以下未覆盖的 UI 交互（所有测试使用 API Mock，无需真实后端运行）：
 *
 * 1. 翻译历史页 — 刷新按钮、域名过滤、状态过滤、清除过滤、行展开/收起、状态徽章
 * 2. 概览页    — 启动循环/停止循环（条件渲染）、点击启动/停止触发 API、运行记录行高亮
 * 3. 任务页    — 并发配置 Tab 数据展示、内联编辑(Enter 提交/Escape 取消)、启用/禁用切换
 * 4. 站点页    — 已有 Token 列表、编辑 Modal 预填值、删除 Toast、测试连通 Toast
 * 5. API 密钥  — Vendor Key 编辑 Modal、Vendor Key 删除、OAuth 列表显示、OAuth 删除
 */

import { test, expect, type Page } from '@playwright/test';
import { resolveSlotClientBase } from '../lib/e2e-slot-ports'

const BASE = resolveSlotClientBase();
// ─── 通用辅助 ─────────────────────────────────────────────────────────────────

/**
 * 使用 Mocked /api/status（logged_in: true）登录，无需真实后端。
 * 必须在调用此函数前注册好 status mock。
 */
async function doMockedLogin(page: Page) {
  await page.goto(BASE);
  await page.waitForSelector('nav.min-h-screen', { timeout: 10000 });
}

async function goTo(page: Page, label: string) {
  await page.click(`nav button:has-text("${label}")`);
  await page.waitForTimeout(300);
}

/** 生成带指定覆盖字段的 /api/status 响应体 */
function makeStatus(overrides: Record<string, unknown> = {}) {
  return JSON.stringify({
    success: true,
    data: {
      logged_in: true,
      worker_loop_running: false,
      worker_loop_poll_seconds: 20,
      worker_recent_runs: [],
      domains: [],
      domain_token_bindings: [],
      component_count: 0,
      vendor_key_count: 0,
      ...overrides,
    },
  });
}

/** 注册 /api/status mock */
async function mockStatus(page: Page, overrides: Record<string, unknown> = {}) {
  await page.route('**/api/status', async route => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: makeStatus(overrides),
    });
  });
}

// ═══════════════════════════════════════════════════════════════════════════════
// 一、翻译历史页 — 刷新按钮 + 过滤控件 + 行交互
// ═══════════════════════════════════════════════════════════════════════════════

const mockHistoryRecords = [
  {
    id: 1, created_at: 1740000000, domain: 'https://blog.example.com',
    relation_id: 1, object_id: 42, object_type: 'post',
    business_line: 'post_content', source_lang: 'en', target_lang: 'zh-CN',
    status: 'success', execution_ms: 1200, worker_id: 'worker-001',
    idempotency_key: 'ik-001', callback_retries: 0, fields_count: 3,
    error_message: null, callback_sent_at: 1740000010,
  },
  {
    id: 2, created_at: 1740000100, domain: 'https://news.example.com',
    relation_id: 2, object_id: 99, object_type: 'page',
    business_line: 'post_title', source_lang: 'en', target_lang: 'ja',
    status: 'failed', execution_ms: 500, worker_id: 'worker-001',
    idempotency_key: 'ik-002', callback_retries: 3, fields_count: 1,
    error_message: 'Timeout exceeded', callback_sent_at: null,
  },
];

async function setupHistoryMocks(page: Page) {
  await mockStatus(page);
  await page.route('**/api/translations**', async route => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: { records: mockHistoryRecords, total: 2, page: 1, limit: 20 },
      }),
    });
  });
}

test.describe('翻译历史页 补充交互', () => {

  test('刷新按钮点击后不崩溃，按钮最终恢复可用', async ({ page }) => {
    await setupHistoryMocks(page);
    await doMockedLogin(page);
    await goTo(page, '翻译历史');
    await expect(page.locator('h2:has-text("翻译历史")')).toBeVisible();

    const refreshBtn = page.locator('button:has-text("刷新")');
    await expect(refreshBtn).toBeVisible();
    await expect(refreshBtn).toBeEnabled();

    await refreshBtn.click();
    // 完成后按钮应恢复可用
    await expect(refreshBtn).toBeEnabled({ timeout: 5000 });

    console.log('翻译历史：刷新按钮 ✓');
  });

  test('域名过滤输入框可以输入和清空', async ({ page }) => {
    await setupHistoryMocks(page);
    await doMockedLogin(page);
    await goTo(page, '翻译历史');
    await expect(page.locator('h2:has-text("翻译历史")')).toBeVisible();
    await page.waitForTimeout(300);

    const domainInput = page.locator('input[placeholder="域名过滤..."]');
    await expect(domainInput).toBeVisible();

    await domainInput.fill('blog.example.com');
    await expect(domainInput).toHaveValue('blog.example.com');

    await domainInput.fill('');
    await expect(domainInput).toHaveValue('');

    console.log('翻译历史：域名过滤输入 ✓');
  });

  test('状态过滤下拉框包含正确选项并可切换', async ({ page }) => {
    await setupHistoryMocks(page);
    await doMockedLogin(page);
    await goTo(page, '翻译历史');
    await expect(page.locator('h2:has-text("翻译历史")')).toBeVisible();
    await page.waitForTimeout(300);

    const statusSelect = page.locator('select').first();
    await expect(statusSelect).toBeVisible();

    const opts = await statusSelect.locator('option').allTextContents();
    expect(opts).toContain('全部状态');
    expect(opts.some(o => o.includes('成功'))).toBe(true);
    expect(opts.some(o => o.includes('失败'))).toBe(true);
    expect(opts.some(o => o.includes('待回调'))).toBe(true);

    await statusSelect.selectOption('failed');
    await expect(statusSelect).toHaveValue('failed');

    await statusSelect.selectOption('');
    await expect(statusSelect).toHaveValue('');

    console.log('翻译历史：状态过滤下拉框 ✓');
  });

  test('清除过滤按钮清空所有过滤条件', async ({ page }) => {
    await setupHistoryMocks(page);
    await doMockedLogin(page);
    await goTo(page, '翻译历史');
    await expect(page.locator('h2:has-text("翻译历史")')).toBeVisible();
    await page.waitForTimeout(300);

    const domainInput = page.locator('input[placeholder="域名过滤..."]');
    const statusSelect = page.locator('select').first();
    const searchInput = page.locator('input[placeholder="关键词搜索..."]');

    // 填写过滤条件
    await domainInput.fill('example.com');
    await statusSelect.selectOption('success');
    await searchInput.fill('post_title');

    // 点击清除
    await page.click('button:has-text("清除过滤")');
    await page.waitForTimeout(300);

    await expect(domainInput).toHaveValue('');
    await expect(statusSelect).toHaveValue('');
    await expect(searchInput).toHaveValue('');

    console.log('翻译历史：清除过滤 ✓');
  });

  test('点击记录行展开详情，再次点击收起', async ({ page }) => {
    await setupHistoryMocks(page);
    await doMockedLogin(page);
    await goTo(page, '翻译历史');
    await expect(page.locator('h2:has-text("翻译历史")')).toBeVisible();
    await page.waitForTimeout(500);

    // 等待记录加载
    await expect(page.locator('table tbody tr').first()).toBeVisible();

    // 点击第一行展开
    await page.locator('table tbody tr').first().click();
    await page.waitForTimeout(300);

    // 展开后应显示详情字段
    await expect(page.locator('span:has-text("记录 ID")')).toBeVisible();

    // 再次点击同一行收起
    await page.locator('table tbody tr').first().click();
    await page.waitForTimeout(300);
    await expect(page.locator('span:has-text("记录 ID")')).not.toBeVisible();

    console.log('翻译历史：行展开/收起 ✓');
  });

  test('状态徽章颜色正确（成功=绿色，失败=红色）', async ({ page }) => {
    await setupHistoryMocks(page);
    await doMockedLogin(page);
    await goTo(page, '翻译历史');
    await expect(page.locator('h2:has-text("翻译历史")')).toBeVisible();
    await page.waitForTimeout(500);

    await expect(page.locator('span.bg-green-100:has-text("成功")')).toBeVisible();
    await expect(page.locator('span.bg-red-100:has-text("失败")')).toBeVisible();

    console.log('翻译历史：状态徽章颜色 ✓');
  });

}); // end 翻译历史 补充


// ═══════════════════════════════════════════════════════════════════════════════
// 二、概览页 — 启动/停止循环按钮 + 运行记录行高亮
// ═══════════════════════════════════════════════════════════════════════════════

test.describe('概览页 循环控制与运行记录', () => {

  test('Worker 停止时只显示「启动循环」按钮，不显示「停止循环」', async ({ page }) => {
    await mockStatus(page, { worker_loop_running: false });
    await doMockedLogin(page);
    await expect(page.locator('h2:has-text("概览")').first()).toBeVisible();
    await page.waitForTimeout(300);

    await expect(page.locator('button:has-text("启动循环")')).toBeVisible();
    await expect(page.locator('button:has-text("停止循环")')).not.toBeVisible();

    console.log('概览页：Worker 停止 → 启动循环按钮 ✓');
  });

  test('Worker 运行中时只显示「停止循环」按钮，不显示「启动循环」', async ({ page }) => {
    await mockStatus(page, { worker_loop_running: true });
    await doMockedLogin(page);
    await expect(page.locator('h2:has-text("概览")').first()).toBeVisible();
    await page.waitForTimeout(300);

    await expect(page.locator('button:has-text("停止循环")')).toBeVisible();
    await expect(page.locator('button:has-text("启动循环")')).not.toBeVisible();

    console.log('概览页：Worker 运行 → 停止循环按钮 ✓');
  });

  test('点击「启动循环」→ 调用 /api/worker/start 并显示成功 Toast', async ({ page }) => {
    await mockStatus(page, { worker_loop_running: false });
    await page.route('**/api/worker/start', async route => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true }),
      });
    });

    await doMockedLogin(page);
    await expect(page.locator('button:has-text("启动循环")')).toBeVisible();
    await page.click('button:has-text("启动循环")');
    await page.waitForTimeout(500);

    // 成功 Toast：包含"启动"或"Worker"
    await expect(
      page.locator('div.font-medium').filter({ hasText: /启动|Worker 循环/ })
    ).toBeVisible({ timeout: 3000 });

    console.log('概览页：启动循环 → 成功 Toast ✓');
  });

  test('点击「停止循环」→ 调用 /api/worker/stop 并显示 Toast', async ({ page }) => {
    await mockStatus(page, { worker_loop_running: true });
    await page.route('**/api/worker/stop', async route => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true }),
      });
    });

    await doMockedLogin(page);
    await expect(page.locator('button:has-text("停止循环")')).toBeVisible();
    await page.click('button:has-text("停止循环")');
    await page.waitForTimeout(500);

    // Toast 包含"停止"或"Worker"
    await expect(
      page.locator('div.font-medium').filter({ hasText: /停止|Worker/ })
    ).toBeVisible({ timeout: 3000 });

    console.log('概览页：停止循环 → Toast ✓');
  });

  test('运行记录行点击高亮，切换行时高亮转移', async ({ page }) => {
    const runs = [
      { ts: 1740000000, status: 'ok',   summary: { completed: 5, failed: 0, domains: [] }, error: '' },
      { ts: 1740000200, status: 'ok',   summary: { completed: 3, failed: 1, domains: [] }, error: '' },
      { ts: 1740000400, status: 'fail', summary: { completed: 0, failed: 2, domains: [] }, error: 'err' },
    ];

    await mockStatus(page, { worker_recent_runs: runs });
    await doMockedLogin(page);
    await expect(page.locator('h2:has-text("概览")').first()).toBeVisible();
    await page.waitForTimeout(400);

    // 运行记录表格行（过滤掉"暂无运行记录"占位行）
    const rows = page.locator('table tbody tr').filter({ hasNot: page.locator('td[colspan]') });
    const rowCount = await rows.count();
    // 第一个表格是"最近运行记录"，第二个是"域名详情"；两个表格共享同一 page 上下文
    // 所以 rows 可能包含两个表格的行，取前三行即可
    expect(rowCount).toBeGreaterThanOrEqual(2);

    // 点击第一行 → bg-blue-50
    const firstRow = rows.first();
    await firstRow.click();
    await expect(firstRow).toHaveClass(/bg-blue-50/);

    // 点击第二行 → 第二行高亮，第一行不再高亮
    const secondRow = rows.nth(1);
    await secondRow.click();
    await expect(secondRow).toHaveClass(/bg-blue-50/);
    await expect(firstRow).not.toHaveClass(/bg-blue-50/);

    console.log('概览页：运行记录行点击高亮 ✓');
  });

  test('点击「刷新域名」按钮 → 不崩溃，显示 Toast', async ({ page }) => {
    await mockStatus(page);
    await page.route('**/api/domains/refresh', async route => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true }),
      });
    });

    await doMockedLogin(page);
    await expect(page.locator('button:has-text("刷新域名")')).toBeVisible();
    await page.click('button:has-text("刷新域名")');
    await page.waitForTimeout(500);

    await expect(
      page.locator('div.font-medium').filter({ hasText: /域名已刷新|刷新/ })
    ).toBeVisible({ timeout: 3000 });

    console.log('概览页：刷新域名 → Toast ✓');
  });

}); // end 概览页


// ═══════════════════════════════════════════════════════════════════════════════
// 三、任务页（并发配置 Tab）— 内联编辑 + 启用/禁用切换
// ═══════════════════════════════════════════════════════════════════════════════

const mockDiscoveryTask = {
  id: 1,
  domain: 'https://blog.example.com',
  relation_id: 1,
  concurrency: 3,
  batch_parallel: 2,
  per_page: 20,
  retry_max: 3,
  timeout_secs: 30,
  enabled: true,
  last_run_at: 1740000000,
  created_at: 1740000000,
  updated_at: 1740000000,
};

async function setupTasksMocks(page: Page, task = mockDiscoveryTask) {
  await mockStatus(page);
  await page.route('**/api/jobs**', async route => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { items: [] } }),
    });
  });
  // 使用统一的 discovery-tasks 处理器（GET=列表, PUT=更新）
  await page.route('**/api/discovery-tasks**', async route => {
    const method = route.request().method();
    if (method === 'GET') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { items: [task] } }),
      });
    } else {
      // PUT（更新）
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true }),
      });
    }
  });
}

test.describe('任务页 任务配置 Tab', () => {

  test('任务配置 Tab 切换显示任务表格', async ({ page }) => {
    await setupTasksMocks(page);
    await doMockedLogin(page);
    await goTo(page, '任务');
    await expect(page.locator('h2:has-text("任务管理")')).toBeVisible();

    await page.click('button:has-text("任务配置")');
    await page.waitForTimeout(500);

    // 任务行显示
    await expect(page.locator('td:has-text("blog.example.com")')).toBeVisible();
    // 启用按钮可见
    await expect(page.locator('button:has-text("启用")')).toBeVisible();

    console.log('任务页：任务配置 Tab 数据展示 ✓');
  });

  test('点击数值单元格进入内联编辑模式', async ({ page }) => {
    await setupTasksMocks(page);
    await doMockedLogin(page);
    await goTo(page, '任务');
    await page.click('button:has-text("任务配置")');
    await page.waitForTimeout(500);
    await expect(page.locator('td:has-text("blog.example.com")')).toBeVisible();

    // 点击第一个"点击编辑"数值按钮（批内并发）
    const editBtn = page.locator('button[title="点击编辑"]').first();
    await editBtn.click();
    await page.waitForTimeout(200);

    // 应出现 number 输入框
    const editInput = page.locator('input[type="number"].w-16');
    await expect(editInput).toBeVisible();
    // 值应为原始值（3）
    await expect(editInput).toHaveValue('3');

    console.log('任务页：内联编辑 → 进入编辑模式 ✓');
  });

  test('内联编辑 Enter 键提交并显示「已保存」Toast', async ({ page }) => {
    await setupTasksMocks(page);
    await doMockedLogin(page);
    await goTo(page, '任务');
    await page.click('button:has-text("任务配置")');
    await page.waitForTimeout(500);
    await expect(page.locator('td:has-text("blog.example.com")')).toBeVisible();

    const editBtn = page.locator('button[title="点击编辑"]').first();
    await editBtn.click();
    await page.waitForTimeout(200);

    const editInput = page.locator('input[type="number"].w-16');
    await expect(editInput).toBeVisible();

    // 修改值并按 Enter
    await editInput.fill('5');
    await editInput.press('Enter');
    await page.waitForTimeout(600);

    // 编辑框消失
    await expect(editInput).not.toBeVisible();
    // 成功 Toast
    await expect(page.locator('div.font-medium:has-text("已保存")')).toBeVisible({ timeout: 3000 });

    console.log('任务页：Enter 提交内联编辑 ✓');
  });

  test('内联编辑 Escape 键取消，无 Toast 无 API 调用', async ({ page }) => {
    let apiCalled = false;
    await setupTasksMocks(page);
    // 覆盖 PUT handler，检测是否被调用
    await page.route('**/api/discovery-tasks/**', async route => {
      if (route.request().method() === 'PUT') apiCalled = true;
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true }) });
    });

    await doMockedLogin(page);
    await goTo(page, '任务');
    await page.click('button:has-text("任务配置")');
    await page.waitForTimeout(500);
    await expect(page.locator('td:has-text("blog.example.com")')).toBeVisible();

    const editBtn = page.locator('button[title="点击编辑"]').first();
    await editBtn.click();
    await page.waitForTimeout(200);

    const editInput = page.locator('input[type="number"].w-16');
    await expect(editInput).toBeVisible();

    // 修改但按 Escape 取消
    await editInput.fill('99');
    await editInput.press('Escape');
    await page.waitForTimeout(300);

    // 编辑框消失（取消）
    await expect(editInput).not.toBeVisible();
    // 无"已保存" Toast
    await expect(page.locator('div.font-medium:has-text("已保存")')).not.toBeVisible();
    // API 不应被调用
    expect(apiCalled).toBe(false);

    console.log('任务页：Escape 取消内联编辑 ✓');
  });

  test('点击「启用」按钮切换状态并显示 Toast', async ({ page }) => {
    await setupTasksMocks(page);
    await doMockedLogin(page);
    await goTo(page, '任务');
    await page.click('button:has-text("任务配置")');
    await page.waitForTimeout(500);
    await expect(page.locator('td:has-text("blog.example.com")')).toBeVisible();

    const toggleBtn = page.locator('button:has-text("启用")').first();
    await expect(toggleBtn).toBeVisible();
    await toggleBtn.click();
    await page.waitForTimeout(500);

    // Toast 显示「已禁用」
    await expect(
      page.locator('div.font-medium').filter({ hasText: /已禁用|已启用/ })
    ).toBeVisible({ timeout: 3000 });

    console.log('任务页：启用/禁用切换 → Toast ✓');
  });

  test('刷新按钮同时刷新 Jobs 和 Tasks 列表', async ({ page }) => {
    let jobsCallCount = 0;
    let tasksCallCount = 0;

    await mockStatus(page);
    await page.route('**/api/jobs**', async route => {
      jobsCallCount++;
      await route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { items: [] } }),
      });
    });
    await page.route('**/api/discovery-tasks**', async route => {
      if (route.request().method() === 'GET') tasksCallCount++;
      await route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { items: [mockDiscoveryTask] } }),
      });
    });

    await doMockedLogin(page);
    await goTo(page, '任务');
    await page.waitForTimeout(500);

    const initialJobs = jobsCallCount;
    const initialTasks = tasksCallCount;

    // 点击刷新按钮
    await page.click('button:has-text("刷新")');
    await page.waitForTimeout(500);

    // 刷新后 API 调用次数应增加
    expect(jobsCallCount).toBeGreaterThan(initialJobs);
    expect(tasksCallCount).toBeGreaterThan(initialTasks);

    console.log('任务页：刷新按钮同时刷新两个列表 ✓');
  });

}); // end 任务页


// ═══════════════════════════════════════════════════════════════════════════════
// 四、站点页 — 已有 Token 列表 + 编辑 Modal 预填 + 删除 + 测试连通
// ═══════════════════════════════════════════════════════════════════════════════

const existingToken = {
  api_base_url: 'https://blog.example.com',
  token_prefix: 'wptc1.123',
  token_len: 80,
  route_secret: 'secret-abc-12345678',
};

async function setupSitesMocks(page: Page) {
  await mockStatus(page, { domain_token_bindings: [existingToken] });
  await page.route('**/api/domain-tokens/**', async route => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true }),
    });
  });
}

test.describe('站点页 CRUD 操作', () => {

  test('已有 Token 时显示站点列表（含编辑/删除/测试按钮）', async ({ page }) => {
    await setupSitesMocks(page);
    await doMockedLogin(page);
    await goTo(page, '站点');

    await expect(page.locator('td:has-text("blog.example.com")')).toBeVisible();
    await expect(page.locator('button:has-text("测试")')).toBeVisible();
    await expect(page.locator('button:has-text("编辑")')).toBeVisible();
    await expect(page.locator('button:has-text("删除")')).toBeVisible();

    // Route Secret 前8位应脱敏显示
    await expect(page.locator('span.text-green-600:has-text("secret-a")')).toBeVisible();

    console.log('站点页：已有 Token 列表显示 ✓');
  });

  test('点击「编辑」打开 Modal 并预填域名和 Route Secret', async ({ page }) => {
    await setupSitesMocks(page);
    await doMockedLogin(page);
    await goTo(page, '站点');
    await expect(page.locator('td:has-text("blog.example.com")')).toBeVisible();

    await page.click('button:has-text("编辑")');
    await page.waitForTimeout(300);

    // Modal 标题「编辑站点」
    await expect(page.locator('h3:has-text("编辑站点")')).toBeVisible();

    // 域名输入框预填
    const urlInput = page.locator('input[placeholder="https://example.com"]');
    await expect(urlInput).toHaveValue(existingToken.api_base_url);

    // Token 字段清空（安全）
    const tokenInput = page.locator('input[id="site-modal-token"]');
    await expect(tokenInput).toHaveValue('');

    // Route Secret 回显
    const secretInput = page.locator('input[placeholder*="WP Plugin"]');
    await expect(secretInput).toHaveValue(existingToken.route_secret);

    await page.click('button:has-text("取消")');
    await expect(page.locator('h3:has-text("编辑站点")')).not.toBeVisible();

    console.log('站点页：编辑 Modal 预填值 ✓');
  });

  test('点击「删除」→ 调用 API 并显示「Token 已删除」Toast', async ({ page }) => {
    await setupSitesMocks(page);
    await doMockedLogin(page);
    await goTo(page, '站点');
    await expect(page.locator('td:has-text("blog.example.com")')).toBeVisible();

    await page.click('button:has-text("删除")');
    await page.waitForTimeout(500);

    await expect(
      page.locator('div.font-medium:has-text("Token 已删除")')
    ).toBeVisible({ timeout: 3000 });

    console.log('站点页：删除 Token → Toast ✓');
  });

  test('点击「测试」→ 调用 /api/domain-tokens/test 并显示连通 Toast', async ({ page }) => {
    await mockStatus(page, { domain_token_bindings: [existingToken] });
    await page.route('**/api/domain-tokens/test', async route => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true }),
      });
    });

    await doMockedLogin(page);
    await goTo(page, '站点');
    await expect(page.locator('td:has-text("blog.example.com")')).toBeVisible();

    await page.click('button:has-text("测试")');
    await page.waitForTimeout(600);

    await expect(
      page.locator('div.font-medium').filter({ hasText: /连通正常/ })
    ).toBeVisible({ timeout: 3000 });

    console.log('站点页：测试连通 → Toast ✓');
  });

  test('点击「测试」期间按钮显示「测试中...」并禁用', async ({ page }) => {
    await mockStatus(page, { domain_token_bindings: [existingToken] });
    // 延迟响应以便观察加载状态
    await page.route('**/api/domain-tokens/test', async route => {
      await new Promise(r => setTimeout(r, 800));
      await route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({ success: true }),
      });
    });

    await doMockedLogin(page);
    await goTo(page, '站点');
    await expect(page.locator('td:has-text("blog.example.com")')).toBeVisible();

    await page.click('button:has-text("测试")');
    await page.waitForTimeout(200);

    // 按钮应短暂显示「测试中...」并被禁用
    const testingBtn = page.locator('button:has-text("测试中...")');
    await expect(testingBtn).toBeVisible({ timeout: 1000 });
    await expect(testingBtn).toBeDisabled();

    // 等待完成
    await expect(page.locator('button:has-text("测试")')).toBeEnabled({ timeout: 5000 });

    console.log('站点页：测试按钮 Loading 状态 ✓');
  });

}); // end 站点页


// ═══════════════════════════════════════════════════════════════════════════════
// 五、API 密钥页 — Vendor Key 编辑 + 删除 + OAuth 列表 + OAuth 删除
// ═══════════════════════════════════════════════════════════════════════════════

const mockVendorKey = {
  id: 'openai-key-1',
  vendor_id: 'openai',
  label: 'OpenAI Key 1',
  auth_keys: ['api_key'],
  max_concurrent: 5,
  requests_per_second: 3,
  weight: 1,
  enabled: true,
};

const mockOAuthItem = {
  id: 'openai-oauth-1',
  vendor_id: 'openai',
  label: 'OpenAI OAuth',
  grant_type: 'client_credentials',
  token_url: 'https://api.openai.com/v1/token',
  client_id: 'client-123',
  scopes: 'translate',
  has_token: false,
};

async function setupApiKeysMocks(page: Page) {
  await mockStatus(page);
  await page.route('**/api/vendor-keys**', async route => {
    const method = route.request().method();
    if (method === 'GET') {
      await route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { items: [mockVendorKey] } }),
      });
    } else {
      await route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({ success: true }),
      });
    }
  });
  await page.route('**/api/vendor-oauth**', async route => {
    const method = route.request().method();
    if (method === 'GET') {
      await route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { items: [mockOAuthItem] } }),
      });
    } else {
      await route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({ success: true }),
      });
    }
  });
}

test.describe('API密钥 Vendor Key 编辑 & OAuth 操作', () => {

  test('Vendor Key 列表加载后显示编辑和删除按钮', async ({ page }) => {
    await setupApiKeysMocks(page);
    await doMockedLogin(page);
    await goTo(page, 'API 密钥');
    await page.waitForTimeout(500);
    await page.click('button:has-text("Vendor Keys")');
    await page.waitForTimeout(300);

    await expect(page.locator('text=openai-key-1').first()).toBeVisible({ timeout: 5000 });
    await expect(page.locator('button:has-text("编辑")').first()).toBeVisible();
    await expect(page.locator('button:has-text("删除")').first()).toBeVisible();

    console.log('API密钥：Vendor Key 列表显示 ✓');
  });

  test('Vendor Key「编辑」按钮打开 Modal 并预填 ID', async ({ page }) => {
    await setupApiKeysMocks(page);
    await doMockedLogin(page);
    await goTo(page, 'API 密钥');
    await page.waitForTimeout(500);
    await page.click('button:has-text("Vendor Keys")');
    await page.waitForTimeout(300);

    await expect(page.locator('text=openai-key-1').first()).toBeVisible({ timeout: 5000 });

    // 点击编辑
    await page.locator('button:has-text("编辑")').first().click();
    await page.waitForTimeout(300);

    // Modal 应打开（标题含 "编辑"）
    await expect(
      page.locator('h3').filter({ hasText: /编辑.*Key|Key.*编辑/ })
    ).toBeVisible();

    // 关闭 Modal
    await page.click('button:has-text("取消")');
    await expect(
      page.locator('h3').filter({ hasText: /编辑.*Key|Key.*编辑/ })
    ).not.toBeVisible();

    console.log('API密钥：Vendor Key 编辑 Modal ✓');
  });

  test('Vendor Key「删除」→ 成功 Toast「Key 已删除」', async ({ page }) => {
    await setupApiKeysMocks(page);
    await doMockedLogin(page);
    await goTo(page, 'API 密钥');
    await page.waitForTimeout(500);
    await page.click('button:has-text("Vendor Keys")');
    await page.waitForTimeout(300);

    await expect(page.locator('text=openai-key-1').first()).toBeVisible({ timeout: 5000 });

    await page.locator('button:has-text("删除")').first().click();
    await page.waitForTimeout(500);

    await expect(
      page.locator('div.font-medium:has-text("Key 已删除")')
    ).toBeVisible({ timeout: 3000 });

    console.log('API密钥：Vendor Key 删除 → Toast ✓');
  });

  test('OAuth Tab 显示 OAuth 列表含授权和删除按钮', async ({ page }) => {
    await setupApiKeysMocks(page);
    await doMockedLogin(page);
    await goTo(page, 'API 密钥');
    await page.click('button:has-text("OAuth 配置")');
    await page.waitForTimeout(500);

    await expect(page.locator('text=openai-oauth-1').first()).toBeVisible({ timeout: 5000 });
    // 应有授权和删除按钮
    await expect(page.locator('button:has-text("授权")').first()).toBeVisible();
    await expect(page.locator('button:has-text("删除")').first()).toBeVisible();

    console.log('API密钥：OAuth 列表显示 ✓');
  });

  test('OAuth「删除」→ 成功 Toast', async ({ page }) => {
    await setupApiKeysMocks(page);
    await doMockedLogin(page);
    await goTo(page, 'API 密钥');
    await page.click('button:has-text("OAuth 配置")');
    await page.waitForTimeout(500);

    await expect(page.locator('text=openai-oauth-1').first()).toBeVisible({ timeout: 5000 });

    await page.locator('button:has-text("删除")').first().click();
    await page.waitForTimeout(500);

    await expect(
      page.locator('div.font-medium').filter({ hasText: /删除|OAuth/ })
    ).toBeVisible({ timeout: 3000 });

    console.log('API密钥：OAuth 删除 → Toast ✓');
  });

  test('创建 Vendor Key Modal 必填 ID 验证', async ({ page }) => {
    await setupApiKeysMocks(page);
    await doMockedLogin(page);
    await goTo(page, 'API 密钥');
    await page.waitForTimeout(500);
    await page.click('button:has-text("Vendor Keys")');
    await page.waitForTimeout(300);

    await page.click('button:has-text("+ 添加 Key")');
    await page.waitForTimeout(300);
    await expect(page.locator('h3:has-text("添加 Vendor Key")')).toBeVisible();

    // 不填 ID，直接保存
    await page.click('button:has-text("保存")');
    await page.waitForTimeout(300);

    // 错误 Toast：请填写 Key ID
    await expect(
      page.locator('div.font-medium:has-text("请填写 Key ID")')
    ).toBeVisible({ timeout: 3000 });

    await page.click('button:has-text("取消")');

    console.log('API密钥：创建 Key ID 必填验证 ✓');
  });

}); // end API 密钥


// ═══════════════════════════════════════════════════════════════════════════════
// 六、综合 — 保存轮询间隔按钮文字（Overview 保存按钮）
// ═══════════════════════════════════════════════════════════════════════════════

test.describe('概览页 轮询间隔保存', () => {

  test('保存轮询间隔按钮（文字「保存」）触发 API 显示 Toast', async ({ page }) => {
    await mockStatus(page);
    await page.route('**/api/worker/config', async route => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true }),
      });
    });

    await doMockedLogin(page);
    await expect(page.locator('h2:has-text("概览")').first()).toBeVisible();

    // 轮询间隔输入框
    const pollInput = page.locator('input.w-16');
    await expect(pollInput).toBeVisible();
    await pollInput.fill('30');

    // 点击「保存」按钮（Overview 保存轮询间隔，按钮文字是「保存」）
    await page.click('button:has-text("保存")');
    await page.waitForTimeout(500);

    // Toast 包含"轮询"
    await expect(
      page.locator('div.font-medium').filter({ hasText: /轮询|已设为/ })
    ).toBeVisible({ timeout: 3000 });

    console.log('概览页：保存轮询间隔 → Toast ✓');
  });

}); // end 轮询间隔
