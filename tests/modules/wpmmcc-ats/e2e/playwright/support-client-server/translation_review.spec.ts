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

// ─── Mock 数据工厂 ───────────────────────────────────────────────────────────
function makeJob(overrides: Partial<{
  id: number;
  domain: string;
  relation_id: number;
  business_line: string;
  status: string;
  total_items: number;
  done_items: number;
  failed_items: number;
}> = {}) {
  return {
    id: 1,
    domain: 'https://blog.example.com',
    relation_id: 5,
    business_line: 'post_content',
    status: 'completed',
    total_items: 10,
    done_items: 10,
    failed_items: 0,
    triggered_by: 'manual',
    started_at: 1740000000,
    completed_at: 1740001000,
    created_at: 1740000000,
    updated_at: 1740001000,
    ...overrides,
  };
}

function makeItem(overrides: Partial<{
  id: number;
  job_id: number;
  domain: string;
  relation_id: number;
  wp_object_id: number;
  wp_object_subtype: string;
  object_type: string;
  task_type: string;
  source_lang: string;
  target_lang: string;
  status: string;
  raw_path: string;
  translated_path: string;
  error_message: string | null;
  retry_count: number;
  max_retries: number;
  synced_at: number | null;
  translated_at: number | null;
}> = {}) {
  return {
    id: 1,
    job_id: 1,
    domain: 'https://blog.example.com',
    relation_id: 5,
    business_line: 'post_content',
    object_type: 'post',
    wp_object_id: 42,
    wp_object_subtype: '',
    task_type: 'text',
    source_lang: 'en',
    target_lang: 'zh-CN',
    component_id: 'comp-1',
    raw_path: 'data/raw/blog_example_com/rel_5/post_42.json',
    translated_path: 'data/translated/blog_example_com/rel_5/post_42.json',
    status: 'translated',
    client_task_id: 'task-1',
    upload_id: null,
    wp_attachment_id: null,
    error_message: null,
    retry_count: 0,
    max_retries: 3,
    fetched_at: 1740000100,
    translated_at: 1740000200,
    synced_at: null,
    created_at: 1740000000,
    updated_at: 1740000200,
    ...overrides,
  };
}

function mockJobsResp(jobs: ReturnType<typeof makeJob>[]) {
  return { success: true, data: { items: jobs } };
}

function mockItemsResp(items: ReturnType<typeof makeItem>[]) {
  return { success: true, data: { items } };
}

function mockItemContent(
  item: ReturnType<typeof makeItem>,
  raw: Record<string, unknown> | null = null,
  translated: Record<string, unknown> | null = null,
) {
  return {
    success: true,
    data: {
      item,
      raw: raw ?? {
        post_title: 'Hello World',
        post_content: 'This is the content of the post.',
        post_status: 'publish',
      },
      translated: translated ?? {
        post_title: '你好世界',
        post_content: '这是文章的内容。',
        post_status: 'publish',
      },
    },
  };
}

// ─── 统一路由拦截辅助 ──────────────────────────────────────────────────────────
// 单个 page.route 覆盖所有 /api 调用，按 URL 分派，其余 continue
interface MockConfig {
  jobs?: ReturnType<typeof makeJob>[];
  items?: ReturnType<typeof makeItem>[];
  itemContent?: object | null;
  itemId?: number;
  saveSuccess?: boolean;
  saveCaptureRef?: { body: unknown };
  discoveryDelay?: number;
}

async function setupMocks(page: Page, cfg: MockConfig = {}) {
  const itemId = cfg.itemId ?? 1;

  await page.route('**/api/**', async route => {
    const url = route.request().url();
    const method = route.request().method();

    // PUT /api/items/:id/translated
    if (url.match(/\/api\/items\/\d+\/translated/) && method === 'PUT') {
      if (cfg.saveCaptureRef) {
        const raw = route.request().postData();
        cfg.saveCaptureRef.body = raw ? JSON.parse(raw) : null;
      }
      const success = cfg.saveSuccess ?? true;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(
          success
            ? { success: true }
            : { success: false, error: { message: '文件写入失败' } },
        ),
      });
      return;
    }

    // GET /api/items/:id/content
    if (url.match(/\/api\/items\/\d+\/content/)) {
      const content = cfg.itemContent !== undefined
        ? cfg.itemContent
        : mockItemContent(cfg.items?.[0] ?? makeItem({ id: itemId }));
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(content),
      });
      return;
    }

    // GET /api/jobs/:id/items
    if (url.match(/\/api\/jobs\/\d+\/items/)) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockItemsResp(cfg.items ?? [])),
      });
      return;
    }

    // GET /api/jobs (list)
    if (url.includes('/api/jobs')) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockJobsResp(cfg.jobs ?? [])),
      });
      return;
    }

    // GET /api/discovery-tasks
    if (url.includes('/api/discovery-tasks')) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { items: [] } }),
      });
      return;
    }

    // 其他路由直接透传（status、oauth 等）
    await route.continue();
  });
}

// ─── 辅助：导航到审阅页 ────────────────────────────────────────────────────────
async function navigateToReview(
  page: Page,
  item: ReturnType<typeof makeItem>,
  contentOverride?: object,
) {
  await setupMocks(page, {
    jobs: [makeJob({ id: 1 })],
    items: [item],
    itemContent: contentOverride,
    itemId: item.id,
  });

  await doOAuthLogin(page);
  await goTo(page, '任务');

  // 展开任务卡片
  await page.locator('div.space-y-3 > div button.w-full').first().click();
  await page.waitForTimeout(400);

  // 点击"审阅"按钮
  await expect(page.locator('button:has-text("审阅")')).toBeVisible();
  await page.click('button:has-text("审阅")');
  await page.waitForTimeout(400);
}

// ═══════════════════════════════════════════════════════════════════════════════
// 任务管理页测试组
// ═══════════════════════════════════════════════════════════════════════════════
test.describe('任务管理页', () => {

  // ─── 测试 1：翻译任务标签页默认激活 ────────────────────────────────────────
  test('翻译任务标签页默认激活', async ({ page }) => {
    await setupMocks(page, { jobs: [], items: [] });
    await doOAuthLogin(page);
    await goTo(page, '任务');

    const jobsTab = page.locator('button:has-text("翻译任务")');
    await expect(jobsTab).toBeVisible();
    await expect(jobsTab).toHaveClass(/bg-white/);

    // 任务配置标签未激活
    await expect(page.locator('button:has-text("任务配置")')).not.toHaveClass(/bg-white/);

    console.log('任务管理：翻译任务标签默认激活验证通过');
  });

  // ─── 测试 2：空状态时显示正确提示文案 ──────────────────────────────────────
  test('翻译任务空状态时显示提示', async ({ page }) => {
    await setupMocks(page, { jobs: [], items: [] });
    await doOAuthLogin(page);
    await goTo(page, '任务');

    await expect(page.locator('text=暂无翻译任务记录')).toBeVisible();
    console.log('任务管理：空状态文案验证通过');
  });

  // ─── 测试 3：显示任务列表和状态徽章 ────────────────────────────────────────
  test('显示任务列表和状态徽章', async ({ page }) => {
    const jobs = [
      makeJob({ id: 1, status: 'completed', done_items: 10, total_items: 10 }),
      makeJob({ id: 2, status: 'running', done_items: 3, total_items: 10 }),
    ];

    await setupMocks(page, { jobs, items: [] });
    await doOAuthLogin(page);
    await goTo(page, '任务');

    // 两个任务卡片
    await expect(page.locator('div.space-y-3 > div')).toHaveCount(2);

    // 状态徽章（Tasks.svelte 直接渲染 job.status 字符串）
    await expect(page.locator('span:has-text("completed")').first()).toBeVisible();
    await expect(page.locator('span:has-text("running")').first()).toBeVisible();

    // 底部计数
    await expect(page.locator('text=共 2 个任务')).toBeVisible();

    console.log('任务管理：任务列表显示验证通过');
  });

  // ─── 测试 4：任务卡片显示进度信息 ──────────────────────────────────────────
  test('任务卡片显示进度信息', async ({ page }) => {
    const job = makeJob({ id: 1, status: 'running', done_items: 6, total_items: 10 });

    await setupMocks(page, { jobs: [job], items: [] });
    await doOAuthLogin(page);
    await goTo(page, '任务');

    // 进度文字（6/10 完成）
    await expect(page.locator('text=6/10 完成')).toBeVisible();

    // 进度百分比 60%
    await expect(page.locator('text=60%')).toBeVisible();

    // 关系 ID
    await expect(page.locator('text=rel=5')).toBeVisible();

    console.log('任务管理：任务卡片进度信息验证通过');
  });

  // ─── 测试 5：点击任务卡片展开条目列表 ──────────────────────────────────────
  test('点击任务卡片展开条目列表', async ({ page }) => {
    const job = makeJob({ id: 1, total_items: 2, done_items: 2 });
    const items = [
      makeItem({ id: 1, wp_object_id: 42, task_type: 'text', status: 'done' }),
      makeItem({ id: 2, wp_object_id: 99, task_type: 'text', status: 'translated' }),
    ];

    await setupMocks(page, { jobs: [job], items });
    await doOAuthLogin(page);
    await goTo(page, '任务');

    // 展开前，表格标题不可见
    await expect(page.locator('th:has-text("对象 ID")')).not.toBeVisible();

    // 点击任务卡片
    await page.locator('div.space-y-3 > div button.w-full').first().click();
    await page.waitForTimeout(400);

    // 展开后，表格标题可见
    await expect(page.locator('th:has-text("对象 ID")')).toBeVisible();
    await expect(page.locator('th').filter({ hasText: /^类型$/ })).toBeVisible();
    await expect(page.locator('th:has-text("状态")')).toBeVisible();
    await expect(page.locator('th:has-text("操作")')).toBeVisible();

    // 两条数据
    await expect(page.locator('td:has-text("42")')).toBeVisible();
    await expect(page.locator('td:has-text("99")')).toBeVisible();

    // 条目计数
    await expect(page.locator('text=共 2 条')).toBeVisible();

    console.log('任务管理：点击任务卡片展开条目列表验证通过');
  });

  // ─── 测试 6：有 raw_path 的条目显示"审阅"按钮 ─────────────────────────────
  test('有 raw_path 的条目显示审阅按钮', async ({ page }) => {
    const job = makeJob({ id: 1 });
    const items = [
      makeItem({ id: 1, wp_object_id: 42, raw_path: 'data/raw/42.json' }),
    ];

    await setupMocks(page, { jobs: [job], items });
    await doOAuthLogin(page);
    await goTo(page, '任务');

    await page.locator('div.space-y-3 > div button.w-full').first().click();
    await page.waitForTimeout(400);

    await expect(page.locator('button:has-text("审阅")')).toBeVisible();
    console.log('任务管理：有 raw_path 条目显示审阅按钮验证通过');
  });

  // ─── 测试 7：无 raw_path 的条目不显示"审阅"按钮 ──────────────────────────
  test('无 raw_path 的条目不显示审阅按钮', async ({ page }) => {
    const job = makeJob({ id: 1 });
    const items = [
      makeItem({ id: 1, wp_object_id: 42, raw_path: '' }),  // 空 raw_path
    ];

    await setupMocks(page, { jobs: [job], items });
    await doOAuthLogin(page);
    await goTo(page, '任务');

    await page.locator('div.space-y-3 > div button.w-full').first().click();
    await page.waitForTimeout(400);

    // 表格存在（有条目），但"审阅"按钮不显示
    await expect(page.locator('th:has-text("对象 ID")')).toBeVisible();
    await expect(page.locator('button:has-text("审阅")')).not.toBeVisible();

    console.log('任务管理：无 raw_path 条目不显示审阅按钮验证通过');
  });

  // ─── 测试 8：条目有错误信息时显示红色提示 ─────────────────────────────────
  test('条目有错误信息时显示红色提示', async ({ page }) => {
    const job = makeJob({ id: 1 });
    const items = [
      makeItem({ id: 1, wp_object_id: 42, status: 'failed', error_message: '翻译 API 超时' }),
    ];

    await setupMocks(page, { jobs: [job], items });
    await doOAuthLogin(page);
    await goTo(page, '任务');

    await page.locator('div.space-y-3 > div button.w-full').first().click();
    await page.waitForTimeout(400);

    // 错误信息显示为红色
    await expect(page.locator('td.text-red-500:has-text("翻译 API 超时")')).toBeVisible();

    console.log('任务管理：条目错误信息显示验证通过');
  });

  // ─── 测试 9：再次点击任务卡片收起条目列表 ─────────────────────────────────
  test('再次点击任务卡片收起条目列表', async ({ page }) => {
    const job = makeJob({ id: 1 });
    const items = [makeItem({ id: 1 })];

    await setupMocks(page, { jobs: [job], items });
    await doOAuthLogin(page);
    await goTo(page, '任务');

    const cardBtn = page.locator('div.space-y-3 > div button.w-full').first();

    // 展开
    await cardBtn.click();
    await page.waitForTimeout(400);
    await expect(page.locator('th:has-text("对象 ID")')).toBeVisible();

    // 再次点击收起
    await cardBtn.click();
    await page.waitForTimeout(300);
    await expect(page.locator('th:has-text("对象 ID")')).not.toBeVisible();

    console.log('任务管理：再次点击收起条目列表验证通过');
  });

  // ─── 测试 10：切换到"任务配置"标签 ────────────────────────────────────────
  test('切换到任务配置标签', async ({ page }) => {
    await setupMocks(page, { jobs: [], items: [] });
    await doOAuthLogin(page);
    await goTo(page, '任务');

    await page.click('button:has-text("任务配置")');
    await page.waitForTimeout(300);

    // 标签激活
    await expect(page.locator('button:has-text("任务配置")')).toHaveClass(/bg-white/);

    // 空状态文案
    await expect(page.locator('text=暂无并发配置')).toBeVisible();

    console.log('任务管理：切换到任务配置标签验证通过');
  });

  // ─── 测试 11：刷新按钮重新拉取数据 ────────────────────────────────────────
  test('刷新按钮重新拉取数据', async ({ page }) => {
    let callCount = 0;

    await page.route('**/api/**', async route => {
      const url = route.request().url();
      if (url.includes('/api/jobs') && !url.match(/\/api\/jobs\/\d+\/items/)) callCount++;

      if (url.match(/\/api\/jobs\/\d+\/items/)) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockItemsResp([])) });
      } else if (url.includes('/api/jobs')) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockJobsResp([])) });
      } else if (url.includes('/api/discovery-tasks')) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { items: [] } }) });
      } else {
        await route.continue();
      }
    });

    await doOAuthLogin(page);
    await goTo(page, '任务');

    const before = callCount;
    await page.click('button:has-text("刷新")');
    await page.waitForTimeout(300);

    expect(callCount).toBeGreaterThan(before);
    console.log(`任务管理：刷新按钮触发了 ${callCount} 次 /api/jobs 调用`);
  });

}); // end 任务管理页

// ═══════════════════════════════════════════════════════════════════════════════
// 翻译审阅页测试组
// ═══════════════════════════════════════════════════════════════════════════════
test.describe('翻译审阅页', () => {

  // ─── 测试 1：点击"审阅"导航到翻译审阅页 ────────────────────────────────────
  test('点击审阅按钮导航到翻译审阅页', async ({ page }) => {
    const item = makeItem({ id: 1, wp_object_id: 42 });
    await navigateToReview(page, item);

    // 审阅页应显示"返回任务"按钮
    await expect(page.locator('button:has-text("返回任务")')).toBeVisible();

    // 任务管理页的 h2 标题不可见
    await expect(page.locator('h2:has-text("任务管理")')).not.toBeVisible();

    console.log('翻译审阅：导航到审阅页验证通过');
  });

  // ─── 测试 2：头部显示对象元信息 ────────────────────────────────────────────
  test('头部显示对象元信息', async ({ page }) => {
    const item = makeItem({
      id: 1,
      wp_object_id: 42,
      status: 'translated',
      task_type: 'text',
      source_lang: 'en',
      target_lang: 'zh-CN',
      domain: 'https://blog.example.com',
      relation_id: 5,
    });
    await navigateToReview(page, item);

    // 对象 ID
    await expect(page.locator('text=对象 #42')).toBeVisible();

    // 状态徽章
    await expect(page.locator('span:has-text("translated")')).toBeVisible();

    // 任务类型
    await expect(page.locator('span.font-mono:has-text("text")')).toBeVisible();

    // 语言方向（源语言 → 目标语言）
    await expect(page.locator('text=en → zh-CN')).toBeVisible();

    // 域名（去掉 https:// 协议前缀）
    await expect(page.locator('text=blog.example.com')).toBeVisible();

    // 关系 ID
    await expect(page.locator('text=rel=5')).toBeVisible();

    console.log('翻译审阅：头部元信息验证通过');
  });

  // ─── 测试 3：两列布局（原文/译文） ──────────────────────────────────────────
  test('两列布局显示原文和译文列标题', async ({ page }) => {
    const item = makeItem({ id: 1 });
    await navigateToReview(page, item);

    // 等待内容加载
    await expect(page.locator('code:has-text("post_title")')).toBeVisible();

    // 原文列标题
    await expect(page.locator('span:has-text("原文")')).toBeVisible();
    await expect(page.locator('span:has-text("（只读）")')).toBeVisible();

    // 译文列标题
    await expect(page.locator('span:has-text("译文")')).toBeVisible();
    await expect(page.locator('span:has-text("（可编辑）")')).toBeVisible();

    // 标题栏内的"保存译文"按钮（新UI含多个保存按钮，用 first()）
    await expect(page.locator('button:has-text("保存译文")').first()).toBeVisible();

    // 底部的"保存译文"按钮（两个）
    const saveBtns = page.locator('button:has-text("保存译文")');
    await expect(saveBtns).toHaveCount(2);

    console.log('翻译审阅：两列布局验证通过');
  });

  // ─── 测试 4：字段名显示在 code 标签中 ──────────────────────────────────────
  test('字段名显示在 code 标签中', async ({ page }) => {
    const item = makeItem({ id: 1 });
    const content = mockItemContent(item, {
      post_title: 'Hello',
      post_status: 'publish',
    }, {
      post_title: '你好',
      post_status: 'publish',
    });
    await navigateToReview(page, item, content);

    await expect(page.locator('code:has-text("post_title")')).toBeVisible();
    await expect(page.locator('code:has-text("post_status")')).toBeVisible();

    console.log('翻译审阅：字段名 code 标签验证通过');
  });

  // ─── 测试 5：短字符串字段显示 input 输入框 ──────────────────────────────────
  test('短字符串字段显示输入框（input）', async ({ page }) => {
    const item = makeItem({ id: 1 });
    const content = mockItemContent(item, {
      post_title: 'Hello World',   // 短字符串 → input[type="text"]
    }, {
      post_title: '你好世界',
    });
    await navigateToReview(page, item, content);

    // 右侧译文显示 input（不是 textarea）
    const titleInput = page.locator('input[type="text"]').first();
    await expect(titleInput).toBeVisible();
    await expect(titleInput).toHaveValue('你好世界');

    console.log('翻译审阅：短字符串字段显示输入框验证通过');
  });

  // ─── 测试 6：长字符串字段显示 textarea ─────────────────────────────────────
  test('长字符串字段显示 textarea', async ({ page }) => {
    const longText = 'A'.repeat(130);  // > 120 chars → textarea
    const item = makeItem({ id: 1 });
    const content = mockItemContent(item, {
      post_content: longText,
    }, {
      post_content: '很长的中文内容'.repeat(20),
    });
    await navigateToReview(page, item, content);

    // 可编辑的 textarea（不含 readonly 属性）
    const editableTextarea = page.locator('textarea:not([readonly])').last();
    await expect(editableTextarea).toBeVisible();

    console.log('翻译审阅：长字符串字段显示 textarea 验证通过');
  });

  // ─── 测试 7：post_content 固定使用 textarea ─────────────────────────────────
  test('post_content 键固定使用 textarea（无论长度）', async ({ page }) => {
    const item = makeItem({ id: 1 });
    const content = mockItemContent(item, {
      post_content: 'Short',  // 短字符串，但 post_content 是长字段键
    }, {
      post_content: '短',
    });
    await navigateToReview(page, item, content);

    // post_content 即使短也用 textarea
    const editableTextarea = page.locator('textarea:not([readonly])').last();
    await expect(editableTextarea).toBeVisible();

    console.log('翻译审阅：post_content 固定使用 textarea 验证通过');
  });

  // ─── 测试 8：非字符串字段显示只读提示 ──────────────────────────────────────
  test('非字符串字段显示只读提示', async ({ page }) => {
    const item = makeItem({ id: 1, status: 'pending_review' });
    const content = mockItemContent(item, {
      post_title: 'Hello',
      meta_data: { key: 'value', nested: true },  // 非字符串对象
    }, {
      post_title: '你好',
      meta_data: { key: 'value', nested: true },
    });
    await navigateToReview(page, item, content);

    // 非字符串字段应显示只读提示
    await expect(page.locator('span:has-text("非字符串 — 仅查看")')).toBeVisible();

    // meta_data 字段名
    await expect(page.locator('code:has-text("meta_data")')).toBeVisible();

    // 非字符串字段右侧显示 pre（只读），不显示 input
    await expect(page.locator('input[type="text"]')).toHaveCount(1);  // 只有 post_title 有 input

    console.log('翻译审阅：非字符串字段只读提示验证通过');
  });

  // ─── 测试 9：原文显示原始内容（只读） ──────────────────────────────────────
  test('原文列显示原始内容（只读）', async ({ page }) => {
    const item = makeItem({ id: 1 });
    const content = mockItemContent(item, {
      post_title: 'Original English Title',
    }, {
      post_title: '中文标题',
    });
    await navigateToReview(page, item, content);

    // 原文内容应可见（只读 p 标签或只读 textarea）
    await expect(page.locator('text=Original English Title')).toBeVisible();

    // 译文 input 显示已翻译内容
    await expect(page.locator('input[type="text"]').first()).toHaveValue('中文标题');

    console.log('翻译审阅：原文只读、译文可编辑验证通过');
  });

  // ─── 测试 10：编辑译文字段 ──────────────────────────────────────────────────
  test('可以编辑译文字段', async ({ page }) => {
    const item = makeItem({ id: 1 });
    const content = mockItemContent(item, {
      post_title: 'Hello World',
    }, {
      post_title: '你好世界',
    });
    await navigateToReview(page, item, content);

    const titleInput = page.locator('input[type="text"]').first();
    await expect(titleInput).toHaveValue('你好世界');

    // 清空并输入新内容
    await titleInput.fill('修改后的标题');
    await expect(titleInput).toHaveValue('修改后的标题');

    // 原文未变
    await expect(page.locator('text=Hello World')).toBeVisible();

    console.log('翻译审阅：编辑译文字段验证通过');
  });

  // ─── 测试 11：点击"保存译文"触发 API 并显示成功提示 ──────────────────────
  test('点击保存译文触发 API 并显示成功提示', async ({ page }) => {
    const item = makeItem({ id: 1 });
    const captureRef = { body: null as unknown };

    await page.route('**/api/**', async route => {
      const url = route.request().url();
      const method = route.request().method();

      if (url.match(/\/api\/items\/\d+\/translated/) && method === 'PUT') {
        const raw = route.request().postData();
        captureRef.body = raw ? JSON.parse(raw) : null;
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true }) });
        return;
      }
      if (url.match(/\/api\/items\/\d+\/content/)) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockItemContent(item)) });
        return;
      }
      if (url.match(/\/api\/jobs\/\d+\/items/)) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockItemsResp([item])) });
        return;
      }
      if (url.includes('/api/jobs')) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockJobsResp([makeJob()])) });
        return;
      }
      if (url.includes('/api/discovery-tasks')) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { items: [] } }) });
        return;
      }
      await route.continue();
    });

    await doOAuthLogin(page);
    await goTo(page, '任务');
    await page.locator('div.space-y-3 > div button.w-full').first().click();
    await page.waitForTimeout(400);
    await page.click('button:has-text("审阅")');
    await page.waitForTimeout(400);

    // 等待内容加载完成
    await expect(page.locator('code:has-text("post_title")')).toBeVisible();

    // 点击保存
    await page.click('button:has-text("保存译文")');
    await page.waitForTimeout(600);

    // 成功提示出现
    await expect(page.locator('div.font-medium:has-text("保存成功")')).toBeVisible();

    // PUT 请求包含 content 字段
    expect(captureRef.body).toBeTruthy();
    expect((captureRef.body as any).content).toBeDefined();

    console.log('翻译审阅：点击保存触发 API 并显示成功提示验证通过');
  });

  // ─── 测试 12：保存失败时显示错误提示 ──────────────────────────────────────
  test('保存失败时显示错误提示', async ({ page }) => {
    const item = makeItem({ id: 1 });

    await page.route('**/api/**', async route => {
      const url = route.request().url();
      const method = route.request().method();

      if (url.match(/\/api\/items\/\d+\/translated/) && method === 'PUT') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ success: false, error: { message: '文件写入失败' } }),
        });
        return;
      }
      if (url.match(/\/api\/items\/\d+\/content/)) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockItemContent(item)) });
        return;
      }
      if (url.match(/\/api\/jobs\/\d+\/items/)) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockItemsResp([item])) });
        return;
      }
      if (url.includes('/api/jobs')) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockJobsResp([makeJob()])) });
        return;
      }
      if (url.includes('/api/discovery-tasks')) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { items: [] } }) });
        return;
      }
      await route.continue();
    });

    await doOAuthLogin(page);
    await goTo(page, '任务');
    await page.locator('div.space-y-3 > div button.w-full').first().click();
    await page.waitForTimeout(400);
    await page.click('button:has-text("审阅")');
    await page.waitForTimeout(400);

    await expect(page.locator('code:has-text("post_title")')).toBeVisible();
    await page.click('button:has-text("保存译文")');
    await page.waitForTimeout(600);

    // 错误提示出现
    await expect(page.locator('div.font-medium:has-text("保存失败")')).toBeVisible();

    console.log('翻译审阅：保存失败错误提示验证通过');
  });

  // ─── 测试 13：点击"返回任务"回到任务管理页 ─────────────────────────────────
  test('点击返回任务回到任务管理页', async ({ page }) => {
    const item = makeItem({ id: 1 });
    await navigateToReview(page, item);

    // 确认在审阅页
    await expect(page.locator('button:has-text("返回任务")')).toBeVisible();

    // 点击返回
    await page.click('button:has-text("返回任务")');
    await page.waitForTimeout(300);

    // 应回到任务管理页
    await expect(page.locator('h2:has-text("任务管理")')).toBeVisible();

    // 审阅页的返回按钮不再存在
    await expect(page.locator('button:has-text("返回任务")')).not.toBeVisible();

    console.log('翻译审阅：返回任务管理页验证通过');
  });

  // ─── 测试 14：API 返回 null raw 时显示"暂无内容数据" ─────────────────────
  test('API 返回 null raw 时显示暂无内容数据', async ({ page }) => {
    const item = makeItem({ id: 1 });
    const emptyContent = { success: true, data: { item, raw: null, translated: null } };

    await navigateToReview(page, item, emptyContent);

    // 空状态文案
    await expect(page.locator('text=暂无内容数据')).toBeVisible();

    // 不显示字段行
    await expect(page.locator('code:has-text("post_title")')).not.toBeVisible();

    console.log('翻译审阅：null raw 时显示暂无内容数据验证通过');
  });

  // ─── 测试 15：加载时显示"加载中..."状态 ────────────────────────────────────
  test('加载时显示加载中状态', async ({ page }) => {
    const item = makeItem({ id: 1 });
    let resolveContent!: () => void;
    const contentGate = new Promise<void>(res => { resolveContent = res; });

    await page.route('**/api/**', async route => {
      const url = route.request().url();
      const method = route.request().method();

      if (url.match(/\/api\/items\/\d+\/content/)) {
        await contentGate;  // 挂起，直到手动释放
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockItemContent(item)) });
        return;
      }
      if (url.match(/\/api\/jobs\/\d+\/items/)) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockItemsResp([item])) });
        return;
      }
      if (url.includes('/api/jobs')) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockJobsResp([makeJob()])) });
        return;
      }
      if (url.includes('/api/discovery-tasks')) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { items: [] } }) });
        return;
      }
      await route.continue();
    });

    await doOAuthLogin(page);
    await goTo(page, '任务');
    await page.locator('div.space-y-3 > div button.w-full').first().click();
    await page.waitForTimeout(400);
    await page.click('button:has-text("审阅")');

    // 内容 API 尚未响应，应显示加载中
    await expect(page.locator('text=加载中...')).toBeVisible();

    // 释放内容响应
    resolveContent();

    // 加载完成后加载中消失
    await expect(page.locator('text=加载中...')).not.toBeVisible({ timeout: 5000 });
    await expect(page.locator('code:has-text("post_title")')).toBeVisible();

    console.log('翻译审阅：加载中状态验证通过');
  });

  // ─── 测试 16：底部保存按钮也能触发保存 ────────────────────────────────────
  test('底部保存按钮也能触发保存', async ({ page }) => {
    const item = makeItem({ id: 1, status: 'pending_review' });
    let saveCalled = false;

    await page.route('**/api/**', async route => {
      const url = route.request().url();
      const method = route.request().method();

      if (url.match(/\/api\/items\/\d+\/translated/) && method === 'PUT') {
        saveCalled = true;
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true }) });
        return;
      }
      if (url.match(/\/api\/items\/\d+\/content/)) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockItemContent(item)) });
        return;
      }
      if (url.match(/\/api\/jobs\/\d+\/items/)) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockItemsResp([item])) });
        return;
      }
      if (url.includes('/api/jobs')) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockJobsResp([makeJob()])) });
        return;
      }
      if (url.includes('/api/discovery-tasks')) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { items: [] } }) });
        return;
      }
      await route.continue();
    });

    await doOAuthLogin(page);
    await goTo(page, '任务');
    await page.locator('div.space-y-3 > div button.w-full').first().click();
    await page.waitForTimeout(400);
    await page.click('button:has-text("审阅")');
    await page.waitForTimeout(400);

    await expect(page.locator('code:has-text("post_title")')).toBeVisible();

    // 页面底部的第二个"保存译文"按钮
    const bottomSaveBtn = page.locator('button:has-text("保存译文")').last();
    await bottomSaveBtn.scrollIntoViewIfNeeded();
    await bottomSaveBtn.click();
    await page.waitForTimeout(500);

    expect(saveCalled).toBe(true);
    await expect(page.locator('div.font-medium:has-text("保存成功")')).toBeVisible();

    console.log('翻译审阅：底部保存按钮触发保存验证通过');
  });

}); // end 翻译审阅页
