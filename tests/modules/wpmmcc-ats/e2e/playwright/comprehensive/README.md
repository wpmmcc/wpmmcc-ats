# Comprehensive E2E Test Suite

> WP 插件管理员后台 + 前端 + REST + WP-CLI 全功能 Playwright 测试。

## 运行

```bash
cd tests/modules/wpmmcc-ats/e2e/playwright/comprehensive
npx playwright test -c playwright.comprehensive.config.ts                          # 全部
npx playwright test -c playwright.comprehensive.config.ts 01-admin-render.spec.ts  # 单文件
npx playwright test -c playwright.comprehensive.config.ts --reporter=line         # 单行输出
```

## 覆盖矩阵

| Spec | 覆盖 |
|------|------|
| `01-admin-render.spec.ts` | 13 个 admin 页面渲染 + 菜单可见性 |
| `02-settings.spec.ts` | 设置页字段、nonce、capability（渲染） |
| `03-manual-hub.spec.ts` | 手工翻译 hub + 5 子页 + 内容类型表单（渲染） |
| `04`–`13` | 翻译列 / widget / REST / SEO / WP-CLI / Quick Edit / meta box / capability / coexistence |
| `14-admin-form-submit.spec.ts` | **Settings / Content Types / Sites 真实提交往返**（admin-post + `/virtual-sites` AJV） |
| `15-models-templates-submit.spec.ts` | **Models scan preview + Templates scan/rescan** UI→REST |
| `25-admin-page-matrix.spec.ts` | **10 admin 页面 × S1–S6 矩阵**（菜单/直接 URL、匿名+editor+translator 能力矩阵、主状态、每页主 action 回合、9 个 admin-post action 无 nonce 拒绝；含 lane C FINDING 钉住） |

```bash
# Lab wordpress-test
WP_BASE=http://127.0.0.1:9083 bash tests/modules/wpmmcc-ats/e2e/run-playwright-admin-pages.sh 14
WP_BASE=http://127.0.0.1:9083 bash tests/modules/wpmmcc-ats/e2e/run-playwright-admin-pages.sh 15
# or
cd tests/modules/wpmmcc-ats/e2e/playwright && WP_BASE=http://127.0.0.1:9083 npm run test:support:plugin-models-templates
```

## 14 个 admin 页面

主菜单 (8):
- `wptsall` Dashboard
- `wptsall-languages` Languages
- `wptsall-settings` Settings
- `wptsall-strings` String Translation
- `wptsall-tm` Translation Memory
- `wptsall-media` Media Translation
- `wptsall-custom-fields` Custom Fields
- `wptsall-users` User Translation

Manual Hub (6):
- `wptsall-manual` Hub
- `wptsall-url-discovery` URL Discovery
- `wptsall-pending` Pending Translations
- `wptsall-tax-translations` Taxonomy Translation
- `wptsall-field-discovery` Field Discovery
- `wptsall-content-types` Content Types Config

> **FINDING (2026-09-08, lane C)**:`url-discovery` / `pending` / `tax-translations` / `field-discovery`
> 4 页以 `add_submenu_page(parent='wptsall-manual')` 注册 — `wptsall-manual` 本身是
> 子菜单 slug,WP 只在顶级菜单下渲染子菜单项,因此这 4 页**永远不会出现在 #adminmenu**,
> 只能直接 URL 访问。`25-admin-page-matrix.spec.ts` S1 已钉住该行为(anchor count=0)。
> 另见 `wp-classes.yaml` 各页 notes。

## 性能

- **总耗时**：~5.5 分钟（顺序执行，1 worker）
- **单 spec**：6-15 秒/测试（含登录开销）
- **失败重试**：retries=0（首次失败立即报告）

## 凭据

通过环境变量覆盖：
```bash
export WP_BASE=https://blog.wpmm.cc
export WP_ADMIN_USER=e2esmokeadmin
export WP_ADMIN_PASS=Wptsall-Smoke-Admin-2026!
```

## 与其他测试套件的关系

| 套件 | 关系 |
|------|------|
| `live-smoke.sh` (T1-T36) | L3 基线连通性 + 服务层验证（不需浏览器） |
| `support-plugin-webui/` (4 specs) | L4 E2E 门控/审计/翻译编辑器 |
| `comprehensive/` (本目录) | L4 E2E 完整功能覆盖（54 测试） |
| `journey-three-system/` (7 specs) | L4 真实用户 3 系统旅程 |
| `official-gate/` (4 specs) | L5 上架门控 |

## 关联文档

- `docs/modules/wpmmcc-ats/plugin/40-PLUGIN-TEST-STRATEGY.md`
- `AGENTS.md §28`
