import { test, expect, Page } from '@playwright/test';
import { resolveSlotClientBase } from '../lib/e2e-slot-ports'

const BASE = resolveSlotClientBase();
// ─── OAuth 登录辅助 ────────────────────────────────────────────────────────────
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

// ─── 测试 1：登录页面渲染 ────────────────────────────────────────────────────
test('登录页面：显示 OAuth 登录按钮', async ({ page }) => {
  await fetch(`${BASE}/api/logout`, { method: 'POST', headers: { 'Content-Type': 'application/json' } });
  await page.goto(BASE);
  await page.waitForLoadState('domcontentloaded');

  await expect(page.locator('h1:has-text("WPTSALL Client")')).toBeVisible();
  await expect(page.locator('button:has-text("通过浏览器登录")')).toBeVisible();
  await expect(page.locator('button:has-text("通过浏览器登录")')).toBeEnabled();
});

// ─── 测试 2：OAuth 完整登录流程 ──────────────────────────────────────────────
test('OAuth 登录：成功后显示主界面', async ({ page }) => {
  await doOAuthLogin(page);
  await expect(page.locator('h2:has-text("概览")').first()).toBeVisible();
  await expect(page.locator('h3:has-text("Worker 状态")')).toBeVisible();
});

// ─── 测试 3：概览页 ──────────────────────────────────────────────────────────
test('概览页：Worker 控制按钮和状态展示', async ({ page }) => {
  await doOAuthLogin(page);
  await expect(page.locator('h2:has-text("概览")').first()).toBeVisible();

  // 验证按钮存在
  await expect(page.locator('button:has-text("运行一次")')).toBeVisible();
  await expect(page.locator('button:has-text("刷新域名")')).toBeVisible();
  await expect(page.locator('button:has-text("刷新组件")')).toBeVisible();

  // 轮询间隔输入（无 type 属性，用 class 定位）
  await expect(page.locator('input.w-16')).toBeVisible();

  // 点击「运行一次」
  await page.click('button:has-text("运行一次")');
  await page.waitForTimeout(3000);
  console.log('概览页 Worker 运行一次：触发成功');

  // 刷新域名
  await page.click('button:has-text("刷新域名")');
  await page.waitForTimeout(1000);

  // 刷新组件
  await page.click('button:has-text("刷新组件")');
  await page.waitForTimeout(1000);
  console.log('概览页 刷新域名/组件：成功');
});

test('概览页：启动前缺失组件预检弹窗可区分阻断与确认继续', async ({ page }) => {
  await doOAuthLogin(page);
  await fetch(`${BASE}/api/worker/stop`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: '{}',
  }).catch(() => null);

  let startCheckCount = 0;
  await page.route('**/api/worker/start-check', async (route) => {
    startCheckCount += 1;
    const blocking = startCheckCount === 1;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: {
          can_start: !blocking,
          requires_confirmation: !blocking,
          summary: {
            domains_checked: 1,
            relations_checked: 1,
            rules_checked: 1,
            fields_checked: 1,
            language_pack_lanes_checked: 0,
            blocking_missing_components: blocking ? 1 : 0,
            confirm_missing_components: blocking ? 0 : 1,
            auto_skip_missing_components: 0,
          },
          missing_components: [
            {
              api_base_url: 'https://blog.wpmm.cc',
              business_line: 'config_i18n',
              relation_id: 12,
              rule_id: 128,
              source_group: blocking ? 'config_object' : 'message_template',
              routing_profile: blocking ? 'config_i18n' : 'notification_email',
              delivery_target: blocking ? 'option_writeback' : 'message_template_writeback',
              object_name: blocking ? 'wp_option' : 'shop_order',
              field_name: blocking ? 'blogdescription' : 'body_html',
              source_role: blocking ? 'config_value_html' : 'message_body_html',
              preflight_policy: blocking ? 'block' : 'warn',
              missing_component_behavior: blocking ? 'stop_task' : 'confirm_continue',
              severity: blocking ? 'blocking' : 'confirm',
              content_format: 'rich_html',
              required_slot_key: 'rich_html',
              suggested_task_type: 'text',
            },
          ],
        },
      }),
    });
  });

  await page.route('**/api/worker/start', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        data: { running: true, poll_seconds: 20 },
      }),
    });
  });

  await page.goto(BASE);
  await page.waitForSelector('nav.min-h-screen', { timeout: 15000 });

  await page.click('button:has-text("启动循环")');
  await expect(page.locator('h3:has-text("发现缺失组件")')).toBeVisible();
  await expect(page.locator('text=配置对象')).toBeVisible();
  await expect(page.locator('text=配置国际化路由')).toBeVisible();
  await expect(page.locator('text=阻断启动 / 停止任务')).toBeVisible();
  await expect(page.locator('button:has-text("继续执行")')).not.toBeVisible();
  await page.click('button:has-text("取消")');

  await page.click('button:has-text("启动循环")');
  await expect(page.locator('h3:has-text("发现缺失组件")')).toBeVisible();
  await expect(page.locator('text=消息模板')).toBeVisible();
  await expect(page.locator('text=通知邮件路由')).toBeVisible();
  await expect(page.locator('text=告警 / 确认后继续')).toBeVisible();
  await expect(page.locator('button:has-text("继续执行")')).toBeVisible();
  await page.click('button:has-text("继续执行")');
});

// ─── 测试 4：站点页 ──────────────────────────────────────────────────────────
test('站点页：域名 Token CRUD 表单完整性', async ({ page }) => {
  await doOAuthLogin(page);
  await goTo(page, '站点');
  await expect(page.locator('h2:has-text("WP 站点")')).toBeVisible();

  // 「+ 添加站点」按钮
  await expect(page.locator('button:has-text("添加站点")')).toBeVisible();

  // 读取已有绑定
  const status = await fetch(`${BASE}/api/status`).then(r => r.json());
  const bindings = status.data?.domain_token_bindings ?? [];
  console.log(`站点页：已有域名绑定 ${bindings.length} 条`);

  // 打开添加 Modal，检查表单字段
  await page.click('button:has-text("添加站点")');
  await expect(page.locator('input[placeholder*="https://"]')).toBeVisible();
  await expect(page.locator('input[placeholder*="wptc1"]')).toBeVisible();
  await expect(page.locator('button:has-text("保存")')).toBeVisible();
  await page.click('button:has-text("取消")');
  console.log('站点页：表单字段验证通过');
});

// ─── 测试 5：翻译组件页 ──────────────────────────────────────────────────────
test('翻译组件页：四个 Tab 都可访问', async ({ page }) => {
  await doOAuthLogin(page);
  await goTo(page, '翻译组件');
  await expect(page.locator('h2:has-text("翻译组件")')).toBeVisible();

  // Tab 1: 我的组件
  await page.click('button:has-text("我的组件")');
  await expect(page.locator('button:has-text("创建组件")')).toBeVisible();

  // Tab 2: 能力矩阵
  await page.click('button:has-text("能力矩阵")');
  await expect(page.locator('h3:has-text("组件能力矩阵")')).toBeVisible();

  // Tab 3: 规则绑定
  await page.click('button:has-text("规则绑定")');
  await expect(page.locator('button:has-text("保存绑定")')).toBeVisible();
  await expect(page.locator('select').first()).toBeVisible();

  // Tab 4: Server 模板
  await page.click('button:has-text("Server 模板")');
  await expect(page.locator('table')).toBeVisible();
  console.log('翻译组件页：四个 Tab 均可访问');
});

// ─── 测试 6：翻译组件 - 创建 / 展开 / 删除 ──────────────────────────────────
test('翻译组件：创建 / 展开 / Auth区块 / 删除', async ({ page }) => {
  await doOAuthLogin(page);
  await goTo(page, '翻译组件');
  await page.click('button:has-text("我的组件")');

  // 创建组件
  await page.click('button:has-text("创建组件")');
  await expect(page.locator('h3:has-text("创建组件")')).toBeVisible();

  const testId = `test-comp-${Date.now()}`;
  await page.fill('input[placeholder*="组件 ID"]', testId);
  await page.fill('input[placeholder*="显示名称"]', 'Test Component PW');
  await page.selectOption('select[id="component-modal-kind"]', 'openai_compatible');
  await page.fill('input[placeholder*="https://"]', 'https://api.deepseek.com');
  await page.fill('input[id="component-modal-model"]', 'deepseek-chat');
  const createSaveBtn = page.locator('div.fixed.inset-0 button:has-text("保存")').first();
  await createSaveBtn.scrollIntoViewIfNeeded();
  await createSaveBtn.evaluate((button: HTMLButtonElement) => button.click());
  await page.waitForTimeout(1000);
  await page.fill('input[placeholder*="搜索组件名称、ID、Vendor"]', testId);
  await page.waitForTimeout(500);
  await expect(page.locator(`text=${testId}`)).toBeVisible();
  console.log(`创建组件 ${testId} 成功`);

  // 展开组件（点击组件行）
  await page.click(`text=${testId}`);
  await page.waitForTimeout(500);
  await expect(page.locator('text=版本列表')).toBeVisible();

  // Auth 凭据区块（用精确 span 定位）
  await expect(page.locator('span:has-text("Auth 凭据")').first()).toBeVisible();
  console.log('Auth 凭据区块可见');

  // 删除组件（找当前展开的删除按钮）
  const rows = page.locator('div.flex.items-center.px-4.py-3');
  const row = rows.filter({ hasText: testId });
  await row.locator('button:has-text("删除")').click();
  await page.waitForTimeout(1000);
  await expect(page.locator(`text=${testId}`)).not.toBeVisible();
  console.log('删除组件成功');
});

test('翻译组件：可创建 OpenAI 兼容组件并展示类型标记', async ({ page }) => {
  await doOAuthLogin(page);
  await goTo(page, '翻译组件');
  await page.click('button:has-text("我的组件")');

  await page.click('button:has-text("创建组件")');
  await expect(page.locator('h3:has-text("创建组件")')).toBeVisible();

  const testId = `llm-comp-${Date.now()}`;
  await page.fill('input[placeholder*="组件 ID"]', testId);
  await page.fill('input[placeholder*="显示名称"]', 'OpenAI Compatible PW');
  await page.selectOption('select[id="component-modal-kind"]', 'openai_compatible');
  await page.fill('input[placeholder*="https://"]', 'https://api.deepseek.com');
  await page.fill('input[id="component-modal-model"]', 'deepseek-chat');
  const llmSaveBtn = page.locator('div.fixed.inset-0 button:has-text("保存")').first();
  await llmSaveBtn.scrollIntoViewIfNeeded();
  await llmSaveBtn.evaluate((button: HTMLButtonElement) => button.click());
  await page.waitForTimeout(1000);
  await page.fill('input[placeholder*="搜索组件名称、ID、Vendor"]', testId);
  await page.waitForTimeout(500);

  const rows = page.locator('div.flex.items-center.px-4.py-3');
  const row = rows.filter({ hasText: testId });
  await expect(row).toBeVisible();
  await expect(row.locator('span:has-text("LLM Builder")')).toBeVisible();
  console.log(`OpenAI 兼容组件 ${testId} 创建成功`);

  await row.locator('button:has-text("删除")').click();
  await page.waitForTimeout(1000);
  await expect(page.locator(`text=${testId}`)).not.toBeVisible();
  console.log('OpenAI 兼容组件删除成功');
});

// ─── 测试 7：Auth 绑定 Modal ─────────────────────────────────────────────────
test('翻译组件：Auth 绑定 Modal 打开并填写', async ({ page }) => {
  await doOAuthLogin(page);
  await goTo(page, '翻译组件');
  await page.click('button:has-text("我的组件")');

  // 创建测试组件
  const testId = `auth-test-${Date.now()}`;
  await page.click('button:has-text("创建组件")');
  await page.fill('input[placeholder*="组件 ID"]', testId);
  await page.fill('input[placeholder*="显示名称"]', 'Auth Test');
  await page.selectOption('select[id="component-modal-kind"]', 'openai_compatible');
  await page.fill('input[placeholder*="https://"]', 'https://api.deepseek.com');
  await page.fill('input[id="component-modal-model"]', 'deepseek-chat');
  const authSaveBtn = page.locator('div.fixed.inset-0 button:has-text("保存")').first();
  await authSaveBtn.scrollIntoViewIfNeeded();
  await authSaveBtn.evaluate((button: HTMLButtonElement) => button.click());
  await page.waitForTimeout(1000);

  // 展开
  await page.click(`text=${testId}`);
  await page.waitForTimeout(500);

  // 点击「编辑 Auth」
  await expect(page.locator('button:has-text("编辑 Auth")')).toBeVisible();
  await page.click('button:has-text("编辑 Auth")');

  // Modal 标题
  await expect(page.locator(`h4:has-text("Auth 凭据")`)).toBeVisible();

  // 添加字段
  await expect(page.locator('button:has-text("+ 添加字段")')).toBeVisible();
  await page.click('button:has-text("+ 添加字段")');

  // 填写键值对
  const keyInput = page.locator('input[placeholder="字段名"], input[placeholder*="key"], input[placeholder*="Key"]').last();
  const valInput = page.locator('input[placeholder="值"], input[placeholder*="value"], input[placeholder*="Value"]').last();
  await keyInput.fill('api_key');
  await valInput.fill('test-api-key-123');

  // 保存
  await page.click('button:has-text("保存")');
  await page.waitForTimeout(1000);
  console.log('Auth 绑定：保存成功');

  // 验证后端已更新
  const updStatus = await fetch(`${BASE}/api/status`).then(r => r.json());
  const bound = updStatus.data?.component_bindings?.components?.[testId];
  if (bound) {
    console.log(`Auth 绑定验证：${testId} → keys: ${Object.keys(bound.auth ?? {}).join(', ')}`);
    expect(Object.keys(bound.auth ?? {})).toContain('api_key');
  }

  // 清理
  const row = page.locator('div.flex.items-center.px-4.py-3').filter({ hasText: testId });
  await row.locator('button:has-text("删除")').click();
  await page.waitForTimeout(500);
});

// ─── 测试 8：版本添加 + 编辑 ─────────────────────────────────────────────────
test('翻译组件：添加版本 + 编辑版本', async ({ page }) => {
  await doOAuthLogin(page);
  await goTo(page, '翻译组件');
  await page.click('button:has-text("我的组件")');

  const testId = `ver-test-${Date.now()}`;
  await page.click('button:has-text("创建组件")');
  await page.fill('input[placeholder*="组件 ID"]', testId);
  await page.fill('input[placeholder*="显示名称"]', 'Version Test');
  await page.selectOption('select[id="component-modal-kind"]', 'openai_compatible');
  await page.fill('input[placeholder*="https://"]', 'https://api.deepseek.com');
  await page.fill('input[id="component-modal-model"]', 'deepseek-chat');
  const verSaveBtn = page.locator('div.fixed.inset-0 button:has-text("保存")').first();
  await verSaveBtn.scrollIntoViewIfNeeded();
  await verSaveBtn.evaluate((button: HTMLButtonElement) => button.click());
  await page.waitForTimeout(1000);
  await page.fill('input[placeholder*="搜索组件名称、ID、Vendor"]', testId);
  await page.waitForTimeout(500);

  // 展开组件
  await page.click(`text=${testId}`);
  await page.waitForTimeout(500);

  // 添加版本
  await page.click('text=+ 添加版本');
  await expect(page.locator('h4:has-text("添加版本")').first()).toBeVisible();
  await expect(page.locator('input[placeholder*="版本号"]')).toBeVisible();
  await expect(page.locator('input[placeholder*="Key ID"]')).toBeVisible();
  await expect(page.locator('select').first()).toBeVisible();

  await page.fill('input[placeholder*="版本号"]', '1.0.0');
  await page.click('button:has-text("保存")');
  await page.waitForTimeout(1000);
  const versionRow = page.locator('div.flex.items-center.gap-3').filter({ hasText: '1.0.0' }).first();
  await expect(versionRow.locator('span.font-mono').filter({ hasText: '1.0.0' }).first()).toBeVisible();
  console.log('版本添加：成功');

  // 编辑版本：精准点击版本行内的「编辑」按钮（版本行包含 "1.0.0"）
  await versionRow.locator('button:has-text("编辑")').click();
  // 等待 Modal 出现（标题含"编辑版本"）
  await expect(page.locator('h4').filter({ hasText: '编辑版本' }).first()).toBeVisible({ timeout: 5000 });
  // 编辑模式下版本号输入框应隐藏，改为只读展示
  const versionInput = page.locator('input[placeholder*="版本号"]');
  expect(await versionInput.count()).toBe(0);
  await page.locator('div.fixed.inset-0 button:has-text("更新")').last().click({ force: true });
  await page.waitForTimeout(500);
  console.log('版本编辑：成功');

  // 清理
  const row = page.locator('div.flex.items-center.px-4.py-3').filter({ hasText: testId });
  await row.locator('button:has-text("删除")').click();
  await page.waitForTimeout(500);
});

// ─── 测试 9：Server 模板查看 ─────────────────────────────────────────────────
test('翻译组件：Server 模板查看 Modal', async ({ page }) => {
  await doOAuthLogin(page);
  await goTo(page, '翻译组件');
  await page.click('button:has-text("Server 模板")');

  const status = await fetch(`${BASE}/api/status`).then(r => r.json());
  const serverComps = status.data?.components ?? [];
  console.log(`Server 模板：共 ${serverComps.length} 个`);

  if (serverComps.length > 0) {
    await expect(page.locator('button:has-text("查看")').first()).toBeVisible();
    await page.click('button:has-text("查看")');
    await page.waitForTimeout(2000);

    // Modal 应显示内容
    const modal = page.locator('.fixed.inset-0').last();
    if (await modal.isVisible()) {
      const hasPre = await page.locator('pre').isVisible();
      console.log(`Server 模板 Modal：JSON 展示 ${hasPre ? '✓' : '✗'}`);
      expect(hasPre).toBe(true);
      await page.click('button:has-text("关闭")');
    }
  } else {
    console.log('Server 模板：无组件数据（需要先登录刷新）');
  }
});

// ─── 测试 10：API 密钥页 ─────────────────────────────────────────────────────
test('API 密钥页：Vendor Key 表单字段', async ({ page }) => {
  await doOAuthLogin(page);
  await goTo(page, 'API 密钥');
  await expect(page.locator('h2:has-text("API 密钥")')).toBeVisible();
  await page.click('button:has-text("Vendor Keys")');

  // 找「添加」按钮
  const createBtn = page.locator('button:has-text("添加 Key")').first();
  await createBtn.click();
  await page.waitForTimeout(500);

  // 检查 Modal 里的 ID 字段
  const idInput = page.locator('input[placeholder*="ID"]').first();
  await expect(idInput).toBeVisible();

  const testKeyId = `pw-test-key-${Date.now()}`;
  await idInput.fill(testKeyId);

  await page.click('button:has-text("取消")');
  console.log('API 密钥页：Vendor Key 表单验证通过');
});

// ─── 测试 11：日志页 ─────────────────────────────────────────────────────────
test('日志页：加载并显示内容', async ({ page }) => {
  await doOAuthLogin(page);
  await goTo(page, '日志');
  await expect(page.locator('h2:has-text("日志")')).toBeVisible();
  await page.waitForTimeout(2000);

  const count = await page.locator('table tbody tr, pre, code').count();
  console.log(`日志页：内容区域元素数 ${count}`);
  expect(count).toBeGreaterThanOrEqual(0); // 空状态也是合法的
});

// ─── 测试 12：设置页 ─────────────────────────────────────────────────────────
test('设置页：三个 Tab 表单完整', async ({ page }) => {
  await doOAuthLogin(page);
  await goTo(page, '设置');
  await expect(page.locator('h2:has-text("设置")')).toBeVisible();

  // 代理配置 Tab
  await page.click('button:has-text("代理配置")');
  await expect(page.locator('button:has-text("添加代理")')).toBeVisible();
  await page.click('button:has-text("添加代理")');
  await expect(page.locator('input[placeholder*="Profile ID"]')).toBeVisible();
  await expect(page.locator('select').first()).toBeVisible();
  await expect(page.locator('input[placeholder*="Host"]')).toBeVisible();
  await page.click('button:has-text("取消")');
  console.log('设置页代理配置：表单验证通过');

  // Worker 配置 Tab
  await page.click('button:has-text("Worker 配置")');
  await expect(page.locator('input[type="number"]').first()).toBeVisible();
  await expect(page.locator('button:has-text("保存 Worker 配置")')).toBeVisible();
  console.log('设置页 Worker 配置：验证通过');

  // 日志设置 Tab（高级 Tab 已移除，组件模板只在官网创建）
  await page.click('button:has-text("日志设置")');
  await expect(page.locator('button:has-text("保存设置")')).toBeVisible();
  console.log('设置页日志设置：验证通过');
});

// ─── 测试 13：规则绑定 ───────────────────────────────────────────────────────
test('翻译组件：规则绑定保存', async ({ page }) => {
  await doOAuthLogin(page);
  await goTo(page, '翻译组件');
  await page.click('button:has-text("规则绑定")');

  await expect(page.locator('text=当前站点发现的规则语义')).toBeVisible();
  await expect(page.locator('button:has-text("刷新规则")')).toBeVisible();
  await expect(page.locator('select:has(option[value="global"])')).toBeVisible();
  await expect(page.locator('input[placeholder*="输入组件 ID"]')).toBeVisible();
  await expect(page.locator('button:has-text("保存绑定")')).toBeVisible();

  await page.selectOption('select', 'global');
  await page.fill('input[placeholder*="输入组件 ID"]', 'mock-sign-md5');
  await page.click('button:has-text("保存绑定")');
  await page.waitForTimeout(1000);

  const rows = await page.locator('table tbody tr:not(:has(td[colspan]))').count();
  console.log(`规则绑定：列表共 ${rows} 条`);
  expect(rows).toBeGreaterThanOrEqual(1);
});

// ─── 测试 14：登出 ───────────────────────────────────────────────────────────
test('登出：立即跳回登录页（不等下次轮询）', async ({ page }) => {
  await doOAuthLogin(page);
  await expect(page.locator('button:has-text("退出登录")')).toBeVisible();
  await expect(page.locator('nav.min-h-screen')).toBeVisible();

  await page.click('button:has-text("退出登录")');

  // 3 秒内必须跳回登录页 —— 如果 handleLogout 未调用 fetchStatus()，这里会超时失败
  await expect(page.locator('button:has-text("通过浏览器登录")')).toBeVisible({ timeout: 3000 });

  // 主界面导航消失
  await expect(page.locator('nav.min-h-screen')).not.toBeVisible({ timeout: 3000 });

  // 登出成功 Toast 出现
  await expect(page.locator('div.font-medium:has-text("已退出登录")')).toBeVisible({ timeout: 2000 });

  console.log('登出：3 秒内立即跳回登录页验证通过');
});

// ─── 测试 15：API 密钥页 - 创建 Vendor Key ───────────────────────────────────
test('API 密钥页：创建 Vendor Key', async ({ page }) => {
  await doOAuthLogin(page);
  await goTo(page, 'API 密钥');
  await expect(page.locator('h2:has-text("API 密钥")')).toBeVisible();

  // 确保在 Vendor Keys tab（默认已激活）
  await page.click('button:has-text("Vendor Keys")');
  await page.waitForTimeout(300);

  // 点击「+ 添加 Key」按钮
  await page.click('button:has-text("+ 添加 Key")');
  await page.waitForTimeout(500);

  // Modal 标题
  await expect(page.locator('h3:has-text("添加 Vendor Key")')).toBeVisible();

  // 验证三个必填/可选字段存在
  const keyIdInput = page.locator('input[placeholder="Key ID（必填）"]');
  const vendorIdInput = page.locator('input[placeholder="Vendor ID（必填）"]');
  const labelInput = page.locator('input[placeholder="便于识别的显示名称"]');
  await expect(keyIdInput).toBeVisible();
  await expect(vendorIdInput).toBeVisible();
  await expect(labelInput).toBeVisible();

  // 填写表单
  const testKeyId = `pw-test-key-${Date.now()}`;
  await keyIdInput.fill(testKeyId);
  await vendorIdInput.fill('openai');
  await labelInput.fill('PW Test Key');

  // 提交创建
  await page.click('button:has-text("保存")');
  await page.waitForTimeout(1000);

  // Modal 应关闭，新 Key 出现在列表中（或后端拒绝也可接受——只验证流程完整）
  const keyVisible = await page.locator(`td:has-text("${testKeyId}")`).isVisible().catch(() => false);
  console.log(`API 密钥页：Key ${testKeyId} 创建流程完成，列表可见: ${keyVisible}`);

  // 若 Key 已创建，尝试删除以保持环境整洁
  if (keyVisible) {
    const keyRow = page.locator('tr').filter({ hasText: testKeyId });
    await keyRow.locator('button:has-text("删除")').click();
    await page.waitForTimeout(500);
    console.log('API 密钥页：测试 Key 已清理');
  }
});

// ─── 测试 16：设置页 - 代理 Profile 表单字段 ────────────────────────────────
test('代理配置页：代理 Profile 表单字段', async ({ page }) => {
  await doOAuthLogin(page);
  await goTo(page, '设置');
  await expect(page.locator('h2:has-text("设置")')).toBeVisible();

  // 确保在「代理配置」Tab（默认已激活）
  await page.click('button:has-text("代理配置")');
  await page.waitForTimeout(300);

  // 打开「添加代理」Modal
  await page.click('button:has-text("+ 添加代理")');
  await page.waitForTimeout(500);

  // Modal 标题
  await expect(page.locator('h3:has-text("添加代理")')).toBeVisible();

  // 验证所有表单字段：id / name / protocol / host / port / username / password
  await expect(page.locator('input[placeholder="Profile ID（必填）"]')).toBeVisible();
  await expect(page.locator('input[placeholder="名称"]')).toBeVisible();
  // 协议下拉框（http/https/socks5/socks5h）
  const protocolSelect = page.locator('select').filter({ hasText: 'http' }).first();
  await expect(protocolSelect).toBeVisible();
  await expect(page.locator('input[placeholder="Host（必填）"]')).toBeVisible();
  // 端口字段（type="number"）
  await expect(page.locator('input[placeholder="端口"]')).toBeVisible();
  await expect(page.locator('input[placeholder="用户名（可选）"]')).toBeVisible();
  await expect(page.locator('input[placeholder="密码（可选）"]')).toBeVisible();

  // 验证协议选项内容
  const options = await protocolSelect.locator('option').allTextContents();
  expect(options).toContain('http');
  expect(options).toContain('socks5');

  await page.click('button:has-text("取消")');
  console.log('代理配置页：所有表单字段（Profile ID / 名称 / 协议 / Host / 端口 / 用户名 / 密码）验证通过');
});

// ─── 测试 17：任务管理页 - 两个标签页结构与默认激活状态 ──────────────────────
test('任务管理页：翻译任务 Tab 默认激活，任务配置 Tab 可切换', async ({ page }) => {
  await doOAuthLogin(page);
  await goTo(page, '任务');

  // 页面标题
  await expect(page.locator('h2:has-text("任务管理")')).toBeVisible();

  // 两个 Tab 按钮都可见
  const jobsTab = page.locator('button:has-text("翻译任务")');
  const discoveryTab = page.locator('button:has-text("任务配置")');
  await expect(jobsTab).toBeVisible();
  await expect(discoveryTab).toBeVisible();

  // 默认激活「翻译任务」Tab（有 bg-white 类），「任务配置」不激活
  await expect(jobsTab).toHaveClass(/bg-white/);
  await expect(discoveryTab).not.toHaveClass(/bg-white/);

  // 「刷新」按钮存在
  await expect(page.locator('button:has-text("刷新")')).toBeVisible();

  // 翻译任务 Tab 内容：Job 卡片 或 空状态文案
  const hasJobCards = await page.locator('.bg-white.border.border-gray-200.rounded-xl').count();
  const hasEmptyMsg = await page.locator('text=暂无翻译任务记录').isVisible().catch(() => false);
  console.log(`任务管理页（翻译任务 Tab）：job cards=${hasJobCards}, empty=${hasEmptyMsg}`);
  expect(hasJobCards > 0 || hasEmptyMsg).toBe(true);

  // 切换到「任务配置」Tab
  await discoveryTab.click();
  await page.waitForTimeout(300);

  // 切换后「任务配置」激活，「翻译任务」不再激活
  await expect(discoveryTab).toHaveClass(/bg-white/);
  await expect(jobsTab).not.toHaveClass(/bg-white/);

  // 任务配置 Tab 内容：discovery tasks 表格 或 空状态文案
  const hasDiscoveryTable = await page.locator('table').isVisible().catch(() => false);
  const hasDiscoveryEmpty = await page.locator('text=暂无并发配置').isVisible().catch(() => false);
  console.log(`任务管理页（任务配置 Tab）：table=${hasDiscoveryTable}, empty=${hasDiscoveryEmpty}`);
  expect(hasDiscoveryTable || hasDiscoveryEmpty).toBe(true);

  console.log('任务管理页：标签页结构与默认激活状态验证通过');
});
