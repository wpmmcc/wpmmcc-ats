# Client Content Round-trip（独立 E2E lane）

全链闭环：**WP 供给（语言/虚拟站/关系/翻译规则/新文章）→ 配对包导入客户端 →
mock 组件配置 → 发现 → 翻译 → WP 侧映射 + 译文文章断言**。

不进入 release-gate / Lab matrix（与 dual-webui-mock 同级的独立 lane）。

## 运行

```bash
WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-client-content-roundtrip.sh
# 自定义端口：
CLIENT_RT_BASE=http://127.0.0.1:8990 MOCK_API_BASE=http://127.0.0.1:9090 \
  WPTSALL_LAB=1 bash tests/modules/wpmmcc-ats/e2e/run-client-content-roundtrip.sh
```

## 前置

- Lab WP（`:9083`，`WPTSALL_LAB=1`）
- client-wpplugin 已构建（`client-wpplugin/source/target/{debug,release}/wptsall-client`）
- mock-translate-api（未运行时脚本会尝试从 `tests/infra/mock-api/target/` 启动）

## 覆盖面（对应用户旅程）

| 步骤 | 实现 |
|---|---|
| zh_CN 语言 / 虚拟站 / en_US→zh_CN 关系 / post 翻译规则 | `php/provision-client-content-roundtrip.php`（幂等） |
| 配对包（CLI `wp wptsall security issue_pairing_pack`）→ 客户端导入认领 | 编排脚本（`/api/site-connections/import`） |
| openai-compatible 组件 → mock `/v1/chat/completions`（auth + URL 覆盖 + 槽位绑定） | 编排脚本（与 `tests/scripts/prove-provider-mock-config.sh` 同序列） |
| 发现引导 + worker 执行 | `/api/discovery-tasks/bootstrap` + `/api/worker/run-once` 轮询 |
| 媒体腿：源文携带真实图片附件 → media_ref 子任务 → 媒体组件（catalog media_mt `custom-media-image-mt` → mock `/api/v1/translate/image`）→ 客户端下载转存 `/media-upload` → media_mappings + 目标附件 + 译文内 URL 重写 | provision 内嵌附件与 mock 改名侧种子文件 + `media_ref:image` 槽位绑定 + verify 附加断言 |
| WP 侧断言：post_mappings 行 + 目标文章 publish + 标题/正文含 `【zh_CN】` | `php/verify-client-content-roundtrip.php` |

## 断言要点

- 终止判定以 **WP 侧地面真值**（映射 + 译文标记）为准，不依赖客户端
  translation_records（其落账在高负载队列下可能滞后于回调）。
- 供给脚本每次运行都会发布一篇**新**文章：`sync_mode=new_only` 语义下，
  只有关系/规则就绪后发布的文章才会被发现（这也是产品语义的一部分）。

## 报告

- `tests/reports/e2e/wpmmcc-ats/client-content-roundtrip/summary-*.json`

## 已知产品发现（由本 lane 旅程实证；状态复核至 2026-09-24 三批）

- **规则编辑器字段列表初始加载 / Auto-detect 渲染挂起 — 已修（分批）**：专用规则编辑页
  field-configurator.js 全链早批已硬化（$.ajax GET 序列化 data 正确、错误态均落文案、
  缺参态给可操作提示防转圈）；残面=Models 列表弹窗编辑器 rule-editor-v2.js——
  `wp.apiFetch` GET 不序列化 `data`，`/fields` 必填 data_type/object_name 恒 400，
  字段建议列表永空。本批修=参数入 path 查询串。lab 实证：无参 400（旧形态必死）、
  带参 200+2 字段（post_title/post_content）。
- **Create Rule 按钮卡 "Saving..." — 已修（本批）**：病灶=saveRule 第二 `.then`
  只认 `response.success` 为真，非该形状的 2xx 响应永不重置按钮。修=唯一哨兵对象
  VALIDATION_STOP（防 null/undefined 碰撞）+ save settle 后无条件先重置按钮 +
  `success===false` 显式告警分支。
- **Models 列表 Rules 计数列恒为 0 — 已修（前批，本条原登记陈旧）**：
  `Translation_Rule_Service::get_models` 的 LIMIT -1 病灶（MySQL 拒绝负 LIMIT 致查询
  返回空、计数列全空）已修，源码注释明言「used to blank every rule count in the
  admin models list」；lab 实库 with_counts 计数真产。
- **插件初始化扫描完成后跳转 403 — 已修（前批，本条原登记陈旧）**：跳转目标现即
  `admin.php?page=wpmmcc-ats&tab=templates`（class-initialization-page.php:304）。
- **客户端 EN locale 占位文案 — 已修/登记陈旧**：客户端 WebUI en.json 1693 键与
  桌面端 245 键均全译，零 "Title"/"Subtitle"/"Tab X"/"Th X" 占位、页面无字面残留。

### 媒体腿实证发现（2026-09-24，已随本批修复/登记）

- **附件复制自喂链（2026-09-24 二批已修，产品侧）**：客户端 submitter 每轮把上一轮已上传的
  译文媒体当作新 claimable 项再次复制上传（WP 侧 `-1`/`-1-1` 后缀递增），多轮后附件表膨胀。
  根因：`/media-upload` 创建译文附件（translation TARGET）时未包 internal-write——
  `add_attachment` 派发进 content-change outbox，译文附件下一轮即以「新 media 源」被
  claim 再译再传，闭环自喂。修复=双层：①REST 上传路由 `media_handle_sideload` 段包
  `Content_Change_Dispatcher::with_internal_write()`；②`dispatch_media_change` 增加
  translation-product 守卫（`_wptsall_source_attachment_id` 标记存在即跳过——映射目标
  永非翻译源）。验证：修复后全 lane 跑毕 outbox **零 media 行**（译文附件不再回流），
  lane 全绿 PASS。
- **scoped registry 独占语义**：任务设置 `selected_component_id` 会构建独占单件
  registry（`build_task_scoped_runtime_registry`）——双格式任务（text+media_ref）
  必须不选任务组件、走全局槽位绑定；且他任务陈旧 `selected_component_id` 会进入
  claim guard 集挡住匿名兜底，需全量清空（PUT `selected_component_id:""`）。
- **媒体字段伴生键契约**：字段值仅附件 ID 时 `source_ref` 为空——请求体带字面
  `{{input.source_ref}}` token 外发（本次实证插桩定位）；需伴生 `<field>_url`
  meta 键提供 URL。lane 供给已补 `_e2e_media_ref_image_url`。
- **id_reference 映射缺 source_file_url → 内容 URL 不重写**（产品 bug，前批已修）：
  `apply_from_id` 原来写空 `source_file_url`，`replace_media_urls_in_content`
  pass 1 要求双 URL 非空，永远无法重写 `<img src>`；且 Path B（客户端写回）对
  虚拟站目标完全没有内容 URL 重写（Path A `sync_post` 才有）。修复=适配器切换
  前捕获源附件 URL + Path B 步骤 8b 写回后统一重写。
- **`dispatch_translation_media` 键名错配（2026-09-24 二批已修，产品侧）**：客户端
  payload 键为 `translated_ref`，调度器读 `$mapping['translated_url'] ?? ''`——URL 形
  translated_ref 永远派发空值。修复=调度器双键桥接
  `$mapping['translated_ref'] ?? $mapping['translated_url'] ?? ''`（全插件侧唯一消费点）。
- **虚拟站 CPT query 形 permalink 路由 404（2026-09-24 二批已修，产品侧）**：根因定谳=
  `parse_request` 的 REQUEST_URI 回退未剥查询串，`?{cpt_var}={slug}` 查询串泄入
  virtual_path 致解析必败；配套 home/paged 分支 `resolve_query_form_single_object`
  将 viewable CPT query var 升级为 shadow p= 绑定。bbpress 实证 404→200+EN 标题。
  附核心级定谳：非 viewable CPT（foogallery 类）的 query 形 permalink 是 WP 核心级
  死链（class-wp-post-type.php:717 只为 viewable CPT 注册 query var），主站同样
  404——非产品缺陷；plugin-coverage harness 已按双候选制重构（详见其 PROGRESS.md
  2026-09-24 二批复核段）。
