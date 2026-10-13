# Dev Tool E2E

> **Lab 架构 / 并行 / 计划 / 脚本索引**：[`tests/docs/plans/`](../../../docs/plans/)（`LAB-ARCHITECTURE.md` 等）。  
> **E2E 定位（跨模块 vs 模块自有 · 合并不合并）**：根 [`tests/README.md` §4](../../../README.md)。  
> Lab 日志：`tests/modules/wpmmcc-ats/e2e/lab-logs/`。

本目录是 **WP 插件 Lab / Playwright / Client↔mock 编排的主权威**（不是 `tests/cross/playwright`）。

本目录当前分成两套现役入口：

> 当前说明：仓库内 `.github/workflows/` 已移除，三系统验证统一以本地直接执行这些脚本为准，不再通过 GitHub Actions runner 触发。

## 0. 独立项：双 UI ↔ mock

```bash
bash tests/modules/wpmmcc-ats/e2e/run-dual-ui-catalog-112-mock.sh   # API 矩阵 112×双口
bash tests/modules/wpmmcc-ats/e2e/run-dual-webui-mock-providers.sh  # 4 代表族 smoke
bash tests/modules/wpmmcc-ats/e2e/run-lab-provider-ui-gate.sh       # UI 按 mock lab-provider-cases 填界面
bash tests/modules/wpmmcc-ats/e2e/client-ui-setup/pre-wp-bind/run-gate.sh  # 对接 WP 前：Sites→绑定→Run Once（纯界面）
bash tests/modules/wpmmcc-ats/e2e/content-plugin-full-chain/run-gate.sh    # 20 插件全链编排（默认冒烟 2 项目）
```

说明：[`dual-ui-catalog-112/README.md`](./dual-ui-catalog-112/README.md) · [`dual-webui-mock/README.md`](./dual-webui-mock/README.md) · [`lab-provider-cases/README.md`](./lab-provider-cases/README.md) · [`client-ui-setup/README.md`](./client-ui-setup/README.md) · [`content-plugin-full-chain/README.md`](./content-plugin-full-chain/README.md) · 总索引 [`tests/README.md` §4](../../../README.md)（根级 `tests/cross/playwright` 已归档 2026-09-22）。

## 1. 业务主门禁

入口：

```bash
bash tests/modules/wpmmcc-ats/e2e/run.sh
bash tests/modules/wpmmcc-ats/e2e/run.sh --with-journeys
bash tests/modules/wpmmcc-ats/e2e/run-release-gate.sh
bash tests/modules/wpmmcc-ats/e2e/run-project-matrix.sh
bash tests/modules/wpmmcc-ats/e2e/run-project-matrix.sh --include-core --scope full --with-journeys
bash tests/modules/wpmmcc-ats/e2e/run-family-combo-lane.sh --family learning --scope core-only
bash tests/modules/wpmmcc-ats/e2e/run-family-combo-lane.sh --family content-meta --scope core-only
bash tests/modules/wpmmcc-ats/e2e/run-official-plugin-preflight.sh
bash tests/modules/wpmmcc-ats/e2e/run-official-client-execution.sh
bash tests/modules/wpmmcc-ats/e2e/run-official-client-verification.sh
```

用途：

1. 跑官方 7 Stage 三系统业务闭环
2. 验证 relation、scan、client worker、writeback、frontend verification
3. 继续作为主业务 gate
4. `run-project-matrix.sh` 用于把 `project-specs.json` 里的独立插件 project 串行统一跑完，并输出矩阵汇总 JSON
5. 当需要更高层发布门禁时，可显式加 `--with-journeys`，在 7 Stage 主链之后再追加一次 `journey-three-system`
6. 当需要单一发布入口时，可直接使用 `run-release-gate.sh --level full` 顺序编排 ISS 安全/全链路 acceptance、core gate、组件模板专项 lane 和插件矩阵 + journeys
7. 当需要验证过渡期 family verifier 的“组合证据”时，使用 `run-family-combo-lane.sh`
8. `run-family-combo-lane.sh` 当前会先完整跑第一条独立插件 lane，再从 Stage 3 叠加第二条 lane，避免 `shared` 深清理把前一条 lane 的 mapping 证据抹掉

## 2. 组件模板专项 E2E

入口：

```bash
bash tests/modules/wpmmcc-ats/e2e/run-playwright-support-client-server.sh
bash tests/modules/wpmmcc-ats/e2e/run-component-template-ct0-sync.sh --apply
bash tests/modules/wpmmcc-ats/e2e/run-component-template-ct0-smoke.sh
bash tests/modules/wpmmcc-ats/e2e/run-component-template-ct1.sh
bash tests/modules/wpmmcc-ats/e2e/run-component-template-ct2.sh
bash tests/modules/wpmmcc-ats/e2e/run-component-template-ct2-support-ui.sh
bash tests/modules/wpmmcc-ats/e2e/run-component-template-ct3.sh
bash tests/modules/wpmmcc-ats/e2e/run-component-template-lane.sh baseline
bash tests/modules/wpmmcc-ats/e2e/run-component-template-lane.sh release-required
bash tests/modules/wpmmcc-ats/e2e/run-component-template-lane.sh all-nonmutating
bash tests/modules/wpmmcc-ats/e2e/run-component-template-suite.sh --with-business-gate
```

用途：

1. 验证 official seed -> user local mock 模板镜像
2. 验证组件模板标准、auth/signature、mock API 合同
3. 验证 Client worker 真实任务流如何消费组件模板
4. 验证 `free@wptsall.dev` 与 `limit@wptsall.dev` 两账号下 `100` 个 `user local mock` 组件
5. 验证 WP core seeded rule discovery 如何在 Client 规则绑定页被展示并一键填充
6. 验证 Client「站点」页手工维护 `wp_client_token / route_secret` 后，连通测试与 discovery 能立即恢复
7. 支持对整个 `support-client-server` Playwright lane 做统一的 live runtime/token 预刷新，而不是靠单个测试自己兜底
8. 默认 `support-client-server` 只跑 support/browser 流；`component-template-test.spec.ts` 作为官方模板专项验证保留单独入口
9. 当前组件模板专项 lane 已正式分层：
   - `baseline`: `ct0-smoke + ct1 + ct2 + ct2-support-ui`
   - `release-required`: `baseline + ct3`
   - `ct0-sync` 继续保持单独的 mutating 维护 lane，不算进默认 baseline

## 2.5 三产品 Product Smoke（轻量）

入口：

```bash
bash tests/modules/wpmmcc-ats/e2e/run-product-smoke.sh --product wptsall --mode health
bash tests/modules/wpmmcc-ats/e2e/run-product-smoke.sh --product cloud-api-hub --mode business --dry-run
bash tests/modules/wpmmcc-ats/e2e/run-product-smoke.sh --product github-deployer --mode health
```

用途：

1. `--mode health`：产品健康探针，快速判断服务/API 基础可达性。
2. `--mode business`：产品业务 smoke 框架入口，按产品 lane 调度 seed/run/cleanup hook。
3. 输出统一 JSON 报告（包含 step 级 `category` / `next_action`），用于快速区分环境与契约问题。
4. 在本地快速验证时可先用 `--dry-run` 看 lane 编排和输出结构。

当前 business lane 脚本路径：

- `tests/modules/wpmmcc-ats/e2e/product-smoke/business/wptsall.sh`
- `tests/modules/wpmmcc-ats/e2e/product-smoke/business/cloud-api-hub.sh`
- `tests/modules/wpmmcc-ats/e2e/product-smoke/business/github-deployer.sh`

说明：

1. `ISS-20260425-016` 只负责 business smoke 框架与协议。
2. 产品具体业务步骤由产品 issue 承接（`020` / `021` / `025`）。

报告输出：

- health：`tests/reports/e2e/wpmmcc-ats/product-smoke-<product>-<timestamp>.json`
- business：`tests/reports/e2e/wpmmcc-ats/product-smoke-<product>-business-<timestamp>.json`

## 2.6 Release Gate Staging

入口：

```bash
bash tests/modules/wpmmcc-ats/e2e/run-release-gate.sh --level smoke
bash tests/modules/wpmmcc-ats/e2e/run-release-gate.sh --level business
bash tests/modules/wpmmcc-ats/e2e/run-release-gate.sh --level full
```

分层：

1. `smoke`：`test-host preflight --profile smoke` + 三产品 `health` smoke。
2. `business`：三产品 `business` smoke，引用 `run-product-smoke.sh` 的产品级 report。
3. `full`：重型发布门禁，先执行 ISS acceptance（S1–S7、SEO/provider fault、T1/T2、8 场景），再执行默认 `RG-MANUAL-ONLY`（WP 插件独立手动多语言）、core gate、component template suite、plugin matrix + journeys；任一硬失败都会阻断发布。

跳过产品：

```bash
bash tests/modules/wpmmcc-ats/e2e/run-release-gate.sh --level business --skip-product wptsall
```

跳过项会写入 release gate summary，不允许静默跳过。

手动矩阵策略：

```bash
# 默认 release full 已包含轻量 manual-only gate
bash tests/modules/wpmmcc-ats/e2e/run-release-gate.sh --dry-run

# 20 插件手动内容矩阵属于重型 Lab lane，release 只在需要时显式加入
bash tests/modules/wpmmcc-ats/e2e/run-release-gate.sh --dry-run --with-manual-content-matrix
```

## 2.7 手动多语言 gate

入口：

```bash
bash tests/modules/wpmmcc-ats/e2e/run-manual-only-multilingual-gate.sh
```

用途：

1. 只验证 WP 插件本身的手动多语言能力，不启动 Desktop / Rust client
2. 覆盖语言表、virtual site、relation、手动翻译 REST、term/menu/string/gettext/SEO/hook
3. 产出 JSON + HTML 前台抓取证据，适合替代“客户端矩阵”做插件原生验收

## 2.8 20 个内容插件手动矩阵（WP 插件独立）

入口：

```bash
bash tests/modules/wpmmcc-ats/e2e/run-manual-content-plugin-matrix.sh
bash tests/modules/wpmmcc-ats/e2e/run-manual-content-plugin-matrix.sh --projects woocommerce-content,tutor-content
bash tests/modules/wpmmcc-ats/e2e/run-manual-content-plugin-matrix.sh --skip-playwright
```

用途：

1. 覆盖 `project-specs.json` 当前 20 个 Lab WPCMS 内容插件 project
2. 只走 WPTSALL 手动 REST、手动编辑器、插件原生 wp-admin 与 virtual frontend
3. 明确不启动 Rust/Desktop/WebUI client，明确不使用自动翻译
4. 验证手动标题/正文/摘要/meta、taxonomy term mapping、SEO hreflang、插件前台与 bridge page
5. 最新全量证据：`tests/reports/e2e/wpmmcc-ats/manual-content-plugin-matrix-20260829-045645.json`（PHP `20/20`，Playwright `61/61`）
6. taxonomy admin/render sanity 子集证据：`tests/reports/e2e/wpmmcc-ats/manual-content-plugin-matrix-20260829-105045.json`（`wptsall-content`）

## 2.9 Lab nightly 编排

入口：

```bash
bash tests/modules/wpmmcc-ats/e2e/run-lab-nightly.sh --dry-run
bash tests/modules/wpmmcc-ats/e2e/run-lab-nightly.sh
```

默认包含：

1. `manual_only_multilingual`（WP-only，不启动 client，不自动翻译）
2. `manual_content_plugin_matrix`（20 插件手动内容矩阵）
3. content matrix / architecture seam / component template / cross worker / hotplug smoke

调试时可跳过手动 lane：

```bash
bash tests/modules/wpmmcc-ats/e2e/run-lab-nightly.sh --skip-manual-only
bash tests/modules/wpmmcc-ats/e2e/run-lab-nightly.sh --skip-manual-content-matrix
```

## 3. 报告输出

- 业务主门禁：`tests/reports/e2e/wpmmcc-ats/e2e-v2-*.json`
- 组件模板专项：`tests/reports/e2e/wpmmcc-ats/component-template-*.json`
- 项目矩阵：`tests/reports/e2e/wpmmcc-ats/e2e-project-matrix-*.json`
- 手动多语言 gate：`tests/reports/e2e/wpmmcc-ats/manual-only-multilingual-gate-*.json`
- 内容插件手动矩阵：`tests/reports/e2e/wpmmcc-ats/manual-content-plugin-matrix-*.json`
- 统一发布门禁：`tests/reports/e2e/wpmmcc-ats/e2e-release-gate-*.json`
- `run.sh` 外层失败分类：`tests/reports/e2e/wpmmcc-ats/e2e-v2-runner-failure-*.json`

失败分类字段（外层 summary 统一口径）：

- `category`
- `surface`
- `message`
- `next_action`

当前分类枚举：

- `environment_drift`
- `missing_dependency`
- `contract_mismatch`
- `repo_regression`
- `flaky_or_timeout`

## 4. 真实用户 Journey

入口：

```bash
bash tests/modules/wpmmcc-ats/e2e/run-playwright-journey-three-system.sh
cd tests/modules/wpmmcc-ats/e2e/playwright && npm run test:journey:three-system
```

当前已落地 6 条真实操作链路：

1. 官网忘记密码 -> 程序内取 reset token -> 重置密码 -> 新密码登录 -> 恢复原账号哈希
2. 官网注册 -> 邮箱验证 -> 官网登录 -> Client OAuth 登录
3. WP 管理员登录 -> 插件生成 verification URL -> 官网 reverify 绑定域名 -> Client 立即可见域名
4. WP 创建任务包 -> Client OAuth 登录 -> 概览运行一次 -> 任务页可见执行结果
5. Client 开启 `review_mode` -> 运行真实任务 -> 待审核条目进入审阅页 -> 确认同步到 WP
6. 官网用户登录 -> 创建用户模板 -> Client 拉取模板 -> 创建本地组件 -> 绑定并执行真实 run-once

当前运行口径：

1. wrapper 会先清理 Client 本地缓存的 `session_cache`、删除 `session-token.enc`，并重启 `wptsall-client-webui.service`
2. wrapper 还会主动结束当前 `wptsall-server` 进程并等待 `http://127.0.0.1:8787/health` 恢复，用来清空内存态 rate limit，避免连续跑 public auth journey 时被前一次状态污染
3. wrapper 起跑前会做一轮 public-auth shared-env preflight，当前检查 `server health`、`client status`、`Postgres token access`，并把结果写到 `tests/modules/wpmmcc-ats/e2e/runtime/journey-public-auth-preflight.json`
4. 邮箱验证 token / password reset token 当前都直接从 Postgres 读取，不依赖真实 SMTP 发信和外部邮箱收件箱
5. 第三条 journey 的 WP 侧 verification URL 生成当前走已上线的管理员 REST 接口 `/wp-json/wptsall/v2/site/generate-verification`
6. `demo@wptsall.dev` 当前不再假设固定明文密码；域名复验旅程会先通过 forgot-password 链路把它临时重置到已知强密码，结束后再恢复原 hash
7. `registerUser`、`loginWebUser`、`requestWebPasswordReset`、`loginClientViaOAuth` 当前都带一次 shared-env 自愈重试；若检测到限流或旧会话类抖动，会先重启 `wptsall-server` 再重试
8. `loginClientViaOAuth` 当前会在每次进入 OAuth 前，强制清空 Client runtime `session_cache`、删除 `session-token.enc`、重启 `wptsall-client-webui.service`，避免前一条 journey 的登录态污染后一条
9. 任一 journey wrapper 失败后，会把 Playwright trace/report、Client 状态与最近日志、Server audit/journal、WP recent log tails 自动归档到 `tests/modules/wpmmcc-ats/e2e/runtime/journey-failures/<run-id>/`
10. 这条 lane 是“真人连续操作”补充层，不替代 `run.sh` 的 7 Stage 业务 gate

## 5. 说明

1. 组件模板专项 E2E 不塞进 `run.sh` 的 7 Stage 主链
2. 需要回归 `10+` 个插件时，优先使用 `bash tests/modules/wpmmcc-ats/e2e/run-project-matrix.sh --scope core-only`，而不是人工逐条执行
3. 如果目标是“发布前更高层 gate”，当前推荐：
   - 单 lane：`bash tests/modules/wpmmcc-ats/e2e/run.sh --with-journeys`
   - 矩阵：`bash tests/modules/wpmmcc-ats/e2e/run-project-matrix.sh --include-core --scope full --with-journeys`
   - 总入口：`bash tests/modules/wpmmcc-ats/e2e/run-release-gate.sh`
4. 矩阵 runner 默认继续执行后续 lane，并在最后按汇总退出；若要遇错即停，用 `--fail-fast`
5. `run-project-matrix.sh --with-journeys` 只会在全部 project 完成后追加一次 `journey-three-system`，不会对每个 project 都重复跑 6 条 journey
6. `run-project-matrix.sh` 只是编排层，单个 project 的 Source Of Truth 仍然是 `run.sh`
7. `support-client-mock/` 和 `support-client-server/` 仍然保留，但它们是浏览器支持 lane，不是组件模板 worker 任务流主基线
8. 当前这三块 Client 高价值配置覆盖已经归入 Playwright support / verification lane，而不是再塞进 `run.sh` 的 Stage 脚本：
   - `POST /api/worker/start-check` 的启动前缺失组件预检弹窗
   - `GET /api/rule-component-bindings/discovery` 的规则语义 discovery 与一键填充
   - `站点` 页手工保存 binding 后的连通测试 / discovery 恢复链路
9. `support-plugin-webui/` 当前已补上插件侧真实浏览器 support lane：
   - `plugin-install-activation.support.spec.ts` 覆盖插件存在、启用状态与菜单可达
   - 当前已验证 `wptsall`、`woocommerce`、`bbpress`、`tutor`、`learnpress`、`the-events-calendar`、`wp-job-manager`、`elementor`、`wordpress-seo`、`advanced-custom-fields`
10. 详细分层说明看：
   - `/home/john/projects/wptsall/e2e/README.md`
   - `/home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/playwright/README.md`
11. 15 个独立插件 project 当前“已覆盖 / 未覆盖 / 建议补齐 lane”统一以：
   - `/home/john/projects/wptsall/tests/modules/wpmmcc-ats/e2e/plugin-project-coverage.json`
   为准，不再只靠 README 里的自然语言描述
12. 最新 verifier 可见性补齐：
   - `verify-learning-content.php` 已把 `lesson / tutor_quiz / lp_lesson / lp_quiz / course-category / course-tag / course_category / course_tag` 接成 source-visible + mapping-aware 检查
   - `verify-content-meta-content.php` 已把 `series / wprm_* taxonomy / wprm_* structured meta / podcast focuskw / Yoast social meta` 接成 source-visible + mapping-aware 检查
   - 当前真实站点若只有 source、还没有 mapping/写回证据，这些扩展项会显式 `SKIP`，不再静默缺失
13. family verifier 运行约束与当前实证：
   - `shared` 模式下单独执行 `run.sh` 会做 deep reset，所以 `learning-content` / `content-meta-content` 不能再直接假设能读到多条独立插件 lane 的叠加结果
   - 现在要验证 family 组合证据，统一走：
     - `bash tests/modules/wpmmcc-ats/e2e/run-family-combo-lane.sh --family learning --scope core-only`
     - `bash tests/modules/wpmmcc-ats/e2e/run-family-combo-lane.sh --family content-meta --scope core-only`
   - 当前真实组合结果：
     - learning：`courses / lesson / lp_course / lp_lesson / lp_quiz / course-category / course-tag / course_category / course_tag` 已有真实 WP mapping；剩余 gap 只剩 `tutor_quiz` 仍只有 source、没有 mapping
     - content-meta：`podcast / wprm_recipe / series / wprm_course / wprm_cuisine / wprm_keyword / wprm_ingredient / wprm_* structured meta / podcast _yoast_wpseo_focuskw` 已有真实 WP mapping/写回；剩余 gap 只剩 Yoast social meta 目前仍无 source meta evidence

## 6. Release Checklist

当前发布前执行顺序固定如下：

1. 日常主基线
   - `E2E_PROJECT=core-content E2E_SCOPE=core-only bash tests/modules/wpmmcc-ats/e2e/run.sh`
   - 用途：默认业务闭环 smoke
   - 通过标准：7 Stage 全绿，Stage 6 run-once 处理到新任务，Stage 7 写回/前台验收通过
2. 需要补真人链路时
   - `bash tests/modules/wpmmcc-ats/e2e/run.sh --with-journeys`
   - 用途：官网注册/登录/OAuth、WP 管理动作、Client UI 连续操作补充验收
   - 通过标准：7 Stage 全绿，`journey-three-system` 全部通过，`journey-public-auth-preflight.json` 中 `server_health/client_status/postgres` 均为 `ok`
3. 组件模板相关改动
   - `bash tests/modules/wpmmcc-ats/e2e/run-component-template-lane.sh baseline`
   - 发布前必须补 `bash tests/modules/wpmmcc-ats/e2e/run-component-template-lane.sh release-required`
   - 通过标准：对应 lane summary JSON 中无失败项；`release-required` 必须包含 `ct3`
4. 独立插件批量回归
   - `bash tests/modules/wpmmcc-ats/e2e/run-project-matrix.sh --scope core-only`
   - 发布前高门禁：`bash tests/modules/wpmmcc-ats/e2e/run-project-matrix.sh --include-core --scope full --with-journeys`
   - 通过标准：matrix summary JSON 中 required lanes 全部 `passed`；`--with-journeys` 时 journeys 只能在矩阵末尾追加一次且必须通过
5. 最终统一发布入口
   - `bash tests/modules/wpmmcc-ats/e2e/run-release-gate.sh`
   - 用途：按固定顺序编排 `ISS acceptance -> manual-only multilingual gate -> core gate -> component-template suite -> plugin matrix + journeys`
   - 通过标准：脚本退出码为 `0`，release summary JSON/markdown 中 required sections 全部 `passed`
   - 重型 20 插件手动内容矩阵默认放在 Lab nightly；release 需要时显式加 `--with-manual-content-matrix`

当前不要再临时决定“这次只跑哪些”：

1. 只改核心业务闭环，至少跑第 1 步
2. 只改官网注册/登录/OAuth、Client 登录态或 shared-env 行为，至少跑第 1 步和第 2 步
3. 只改组件模板标准、mock、签名、模板控制面，至少跑第 3 步；发版前必须跑到 `release-required`
4. 涉及独立插件 project、字段写回、插件菜单/任务流，至少跑第 4 步
5. 涉及 WP 插件手动多语言、manual REST、virtual frontend、taxonomy/menu/string/hreflang 时，补跑 `run-manual-only-multilingual-gate.sh`
6. 涉及内容插件手动翻译体验或插件原生前台兼容时，补跑 `run-manual-content-plugin-matrix.sh`
7. 准备生产发布时，不再拆着选，直接跑上面的第 5 步（`run-release-gate.sh`）
