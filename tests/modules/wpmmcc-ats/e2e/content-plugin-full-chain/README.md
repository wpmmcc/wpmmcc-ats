# Content Plugin Full Chain — 20 插件完整测试架构

> 路径：`tests/modules/wpmmcc-ats/e2e/content-plugin-full-chain/`  
> 目标：把「WP 端配置 → 客户端界面流程 → mock 翻译写回 → 多语言前台（SEO/菜单/主题可达）」串成**可维护的一条链**，而不是再造一套平行体系。  
> Mock 译文用 `【zh_CN】…【/zh_CN】`（等）标记区分，**不是**真人翻译结果。  
> 路径解析：`tests/lib/repo-root.sh`（`wptsall_path e2e` / `wptsall_repo_root`），禁止相对 `../` 猜仓库根。

## 0. 一句话

| 层 | 测什么 | 权威实现（复用，不搬家） |
|----|--------|-------------------------|
| WP 内容插件 | 20 插件装好、模型/关系/种子内容 | `tests/docker-lab/scripts/install-content-plugins.sh` · **`tests/scripts/ensure-plugin-project.sh`** · Stage 3–5 |
| WP 管理界面 | 原生后台 / WPTSALL 管理页可达 | `run-manual-content-plugin-matrix.sh` · support-plugin-webui |
| Client 界面 | Sites→厂商→绑定→Run Once | `client-ui-setup/pre-wp-bind/` · `run-lab-provider-ui-gate.sh` |
| 传输+翻译 | claim → mock → callback 写回 | `run-automatic-plugin-slice.sh` · Stage 6 |
| Marker 精度 | 展示字段有 `【lang】`，slug/guid 无 | `php/verify-markers.php` · `verify-plugin-project.php` |
| 前台多语言 | 虚拟站 URL、正文 marker、档案页 | Stage 7–8 · `run-content-plugin-journey-wave.sh` |
| SEO / 菜单 | hreflang、菜单同步 URL | `run-manual-only-multilingual-gate.sh` |

本目录提供 **编排 + 报告 + 阶段开关**；细节仍在上表脚本里迭代。

## 1. 20 个内容插件项目（SoT）

权威清单：`tests/modules/wpmmcc-ats/e2e/project-specs.json` → `plugin_projects`（20）。

| project-id | plugin | 主内容 |
|------------|--------|--------|
| woocommerce-content | woocommerce | product |
| easy-digital-downloads-content | easy-digital-downloads | download |
| bbpress-content | bbpress | topic |
| tutor-content | tutor | courses |
| learnpress-content | learnpress | lp_course |
| the-events-calendar-content | the-events-calendar | tribe_events |
| wp-job-manager-content | wp-job-manager | job_listing |
| envira-gallery-lite-content | envira-gallery-lite | envira |
| seriously-simple-podcasting-content | seriously-simple-podcasting | podcast |
| wp-recipe-maker-content | wp-recipe-maker | wprm_recipe |
| elementor-content | elementor | page |
| wordpress-seo-content | wordpress-seo | post,page |
| advanced-custom-fields-content | advanced-custom-fields | product |
| site-reviews-content | site-reviews | site-review |
| give-content | give | give_forms |
| directorist-content | directorist | at_biz_dir |
| lifterlms-content | lifterlms | course |
| events-manager-content | events-manager | event |
| hivepress-content | hivepress | hp_listing |
| wptsall-content | wptsall (core) | post/page/tax/media |

## 2. 阶段流水线（`phases.yaml` + `run-gate.sh`）

逻辑阶段：

```
P0 preflight          Lab WP + mock + Client 可达
P1 wp-plugins         安装/激活内容插件（可选；默认跳过除非 FULL_CHAIN_INSTALL_PLUGINS=1）
P2 wp-wptsall-prep    种子 / 扫描 / 关系（tests/scripts/ensure-plugin-project.sh）
P3 client-ui          pre-wp-bind（首次项目后跑一次）
P3b lab-provider      可选（默认跳过）
P4 translate-slice    每项目 automatic-plugin-slice（mock→写回）
P5 verify-markers     DB/写回字段 marker 精度
P6 verify-front       前台 HTTP/Playwright：marker + 可达
P7 verify-menu-seo    菜单同步 / hreflang（默认跳过除非 FULL_CHAIN_MENU_SEO=1；FULL_CHAIN_RELEASE=1 发布证据模式强制启用且禁止跳过）
```

**同 slot 多项目顺序（硬约束）**：`ensure` 会清理 runtime / 重灌项目，若先跑完所有项目的 P2 再 P4，后一个项目会冲掉前一个的 fixtures。`run-gate.sh` 因此按项目交织：

```
for each project:
  P2 ensure(project)
  if first project: P3 (+ optional P3b)
  P4 → P5 → P6
then optional P7
```

- 默认 **`FULL_CHAIN_SLOT=slot-a`**（isolated WP，如 `:9181`）。`shared` 会被拒绝并切到 slot-a（P2/P4 需要专用 slot）。
- Client UI 默认共用开发 agent `:8977`（`FULL_CHAIN_CLIENT_BASE`），与 slot WP 分离。

入口：

```bash
# 冒烟（指定 1–2 项目）
WPTSALL_LAB=1 FULL_CHAIN_PROJECTS=give-content,woocommerce-content FULL_CHAIN_SLOT=slot-a \
  bash tests/modules/wpmmcc-ats/e2e/content-plugin-full-chain/run-gate.sh

# 全量 20（耗时长，建议分 slot / nightly）
WPTSALL_LAB=1 FULL_CHAIN_PROJECTS=all bash tests/modules/wpmmcc-ats/e2e/content-plugin-full-chain/run-gate.sh

# 商用模式：强制 p3 Client UI + U1–U7 表单覆盖（禁止 skip p3）
WPTSALL_LAB=1 FULL_CHAIN_COMMERCIAL=1 FULL_CHAIN_PROJECTS=all \
  bash tests/modules/wpmmcc-ats/e2e/content-plugin-full-chain/run-gate.sh

# 统一商用编排 G0–G5（见 tasks/client2/21）
CA_MODE=release bash tests/modules/wpmmcc-ats/e2e/commercial-acceptance/run-commercial-acceptance.sh

# 跳过重阶段
FULL_CHAIN_SKIP=p4,p6,p7 bash …/run-gate.sh
FULL_CHAIN_ONLY=p3,p4 bash …/run-gate.sh
HEADED=1 FULL_CHAIN_ONLY=p3 bash …/run-gate.sh
```

别名：`bash tests/modules/wpmmcc-ats/e2e/run-content-plugin-full-chain-gate.sh`

近期冒烟证据：`tests/reports/e2e/wpmmcc-ats/content-plugin-full-chain/run-20260907T010821/`（give+woo，`ok: true`）。

## 3. Mock 标记约定

- Mock API（`:9090`）输出：`【{locale}】原文【/{locale}】`
- 验收：标题/正文/摘要等**展示字段**含闭合 marker；`post_name` / `guid` 等**功能字段**不得含 marker
- 前台：虚拟站语言前缀 URL 的 HTML 中应可见对应 locale marker（或 journey YAML 规定的 fragment）
- P4 slice：空 virtual 库存时 identity 断言与 core-only soft-skip 对齐（有映射但 marker 错误仍 hard-fail）

## 4. 目录

```
content-plugin-full-chain/
  README.md                 ← 本文件
  phases.yaml               ← 阶段 ↔ 脚本映射
  run-gate.sh               ← 总门禁（交织顺序）
  run-phase.sh              ← 单阶段（repo-root.sh）
  lib/
    projects.py             ← 读 project-specs / 展开 all
    summarize.py            ← 汇总 reports/*.json
  verify/
    run-front-asserts.sh    ← 前台 marker + 基础 hreflang 抽检
    run-marker-check.sh
    README.md
```

报告：`tests/reports/e2e/wpmmcc-ats/content-plugin-full-chain/run-*/` + `summary-*.json`

## 5. 与既有 lane 关系（禁止重复造轮）

| 已有门禁 | 在本链中的角色 |
|----------|----------------|
| `client-ui-setup/pre-wp-bind` | **P3** Client 界面 |
| `run-lab-provider-ui-gate.sh` | P3b 可选扩展（厂商全量） |
| `run-automatic-plugin-slice.sh` | **P4** 每插件闭环 |
| `run-manual-content-plugin-matrix.sh` | WP-only 对照；P1/P6 可引用 |
| `run-manual-only-multilingual-gate.sh` | **P7** 菜单/SEO |
| `run-content-plugin-journey-wave.sh` | **P6** 可选重度前台 journey（`FULL_CHAIN_JOURNEYS=1`） |
| `run-cross-layer-matrix.sh` | 采样矩阵；非本链必跑（`ensure` 路径同 `tests/scripts/`） |

## 6. 已知缺口（迭代 backlog）

1. **20 插件各自完整「设置向导」DOM 配置**仍偏种子/扫描，非逐插件原生 setup wizard  
2. **Field-rules JSON 热插拔**有文档/样例，尚未全部挂进 P2 UI  
3. **全量 20×全前台 journey**成本高 → 默认冒烟 2 项目，全量走 nightly  
4. Client **Run Once** 在大站上可能长时间 claim；P3 验收「界面点通」；P4 用 slice 做写回硬证据

## 7. 迭代约定

- 新断言优先加到既有 `php/verify-*.php` / journey YAML，再在 `phases.yaml` 登记  
- 新 UI 步骤放 `playwright/client-ui-setup/` 或 `playwright/plugin-content-journeys/`，本目录只编排  
- 报告必须带：`project_id`、`phase`、`marker_ok`、`front_ok`、`mock_base`
- 新增脚本一律 `source tests/lib/repo-root.sh`，勿硬编码 `scripts/ensure-plugin-project.sh`
