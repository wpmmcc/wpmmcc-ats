# 独立测试项：双 UI × Catalog 112（+ 多授权 profile）↔ mock

> **不进** `run-release-gate` / Lab 主链。  
> **策略**：只打 local `mock-api`。

## 测什么

| 维 | 内容 |
|----|------|
| Catalog | 模板仓 `wptsall-provider-templates` **catalog v1.1.0** 共 **116** 条（69 openai_compatible + 43 http_mt + 4 media_mt〔X-11 批 S：custom-media-image/audio/video-mt + heygen〕；87 mock-verified + 29 schema-only），agent 首启从 file 源 auto-fetch。本矩阵的逐条语料=**冻结 112 fixture**（mock 侧 `catalog-builtin3-112.json`）；仓释放为其超集，脚本按子集 parity 钉（fixture 全在场+media_mt 四包在场，2026-09-24 起） |
| 授权 | 各条目 catalog auth fields；并展开 mock `auth-profiles`（Google/Azure 等多登录） |
| UI | **插件 WebUI agent**（默认 `:8977`）+ **Desktop UI agent**（默认 `:8978`） |
| 步骤 | install → vendor-key → quick-test(mock) → enable → `plain_text` bind；两端 UI smoke |

两端测的是**同一套** local-first Client agent API（Desktop 壳与插件 WebUI 共用契约）。  
**不包含** Tauri WebView 对 112 家逐一点击；桌面壳 smoke 见 `tests/modules/client-desktop/tests/e2e/tauri-smoke.sh`。

## 前置

- 模板仓 `PROVIDER_TEMPLATES_REPO`（默认 `${REPO_ROOT}/../wptsall-provider-templates`）：需含 `catalog.json`（v1.0.1+）与 `keys/catalog-signing.public.pem`；
- 车道每次运行前会**预清共享 catalog 缓存**（`config/provider-catalog.*.json`）——clean-release 语义，否则上一轮的 `current.json` 会抑制首启 fetch、安装到过期模板；
- mock：本地 `tests/infra/mock-api`（或容器，镜像见 `tests/infra/mock-api/Dockerfile`）。

## 入口

```bash
bash tests/modules/wpmmcc-ats/e2e/run-dual-ui-catalog-112-mock.sh
```

仅矩阵（栈已就绪）：

```bash
SKIP_ENSURE=1 WEBUI_A_BASE=http://127.0.0.1:8977 WEBUI_B_BASE=http://127.0.0.1:8978 \
  python3 tests/scripts/matrix-dual-ui-catalog-112-mock.py
```

## 产物

- `tests/modules/wpmmcc-ats/e2e/reports/dual-ui-catalog-112/matrix-latest.json`（含时间戳归档 `matrix-<UTC>.json`）
- `tasks/test/14-DUAL-UI-CATALOG-112-MATRIX.md`（矩阵脚本自动刷新）

门禁：两侧 **冻结 112 fixture catalog 用例** 全过 + UI smoke + catalog 子集 parity（fixture 112 条全在场、media_mt 四包〔批 S〕在场）；auth-profile 展开失败记入报告但不单独挡门（可后续收紧）。
