# Client UI 配置测试（维护入口）

> 路径：`tests/modules/wpmmcc-ats/e2e/client-ui-setup/`  
> 用途：把「客户端界面上该怎么配」的 UI e2e **集中归档**，方便后续迭代。  
> 原则：**填表 / 点按钮走真实界面**；凭据可从 Lab 导出到 fixture，但**禁止**测试脚本用 API 代填表单。

## 目录地图

```
client-ui-setup/
  README.md                         ← 本文件（总索引）
  pre-wp-bind/                      ← 对接 WP 插件前必配
    README.md
    run-gate.sh                     ← 门禁入口
    export-site-fixture.sh          ← 从 Lab WP 导出 token/secret → fixture
    fixtures/
      site.example.json             ← 示例（无密钥）
      .gitignore                    ← 忽略含密钥的 site.local.json
  providers-from-mock/
    README.md                       ← 指向已有 mock 厂商 UI lane（不搬代码）

playwright/client-ui-setup/         ← Playwright 规格（与其它 e2e 同级，便于 npx）
  helpers.ts                        ← expectApi + AJV（Sites/bind/run-once）
  pre-wp-bind.journey.spec.ts
  form-validation.negative.spec.ts  ← 空 Sites / 坏 scope_key
playwright/lib/expect-api.ts
playwright/schemas/*.schema.json
playwright.client-ui-setup.config.ts
```

相关已有 lane（**不搬家**，只在此登记）：

| Lane | 入口 | 测什么 |
|------|------|--------|
| mock 厂商向导全量 | `run-lab-provider-ui-gate.sh` | API Keys 向导按 `lab-provider-cases` 填 |
| 双 WebUI×mock | `run-dual-webui-mock-providers.sh` | 代表族 API+轻 UI |
| 112 API 矩阵 | `run-dual-ui-catalog-112-mock.sh` | Client API，非 DOM |
| **20 插件全链** | `content-plugin-full-chain/run-gate.sh` | WP prep→本目录 Client UI→slice→前台/SEO |

上游全链索引：[`../content-plugin-full-chain/README.md`](../content-plugin-full-chain/README.md)。

## 对接 WP 前：界面必配顺序

1. **Sites** — 配对包 **或** 手动 URL + `wp_client_token` + `route_secret` → **测试**
2. **API Keys / Providers** — 厂商向导（mock 或真钥）→ enable → 槽位
3. **Components → 规则绑定** — 确认/补全 slot ↔ component（向导 `plain_text` 不够时）
4. **Overview** — **Run Once** / **Start Loop**

## 快速跑

```bash
# 1) 导出 Lab 站点凭据到 fixture（含密钥，gitignore）
bash tests/modules/wpmmcc-ats/e2e/client-ui-setup/pre-wp-bind/export-site-fixture.sh

# 2) 对接 WP 前 UI 门禁（默认无头；有头：HEADED=1）
bash tests/modules/wpmmcc-ats/e2e/client-ui-setup/pre-wp-bind/run-gate.sh

# 厂商 UI（可并行维护）
bash tests/modules/wpmmcc-ats/e2e/run-lab-provider-ui-gate.sh
```

总索引：[`tests/README.md` §4](../../../../README.md)（根级 `tests/cross/playwright` 已归档 2026-09-22）。
