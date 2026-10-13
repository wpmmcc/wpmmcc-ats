# 独立测试项：双 WebUI ↔ mock（厂商 + 组件配置）

> **独立门禁**：不并入 `run-release-gate.sh` / Lab 主链。  
> **策略**：只打 local `mock-api`（`:9090`），不测真 DeepL/Google/官网。

## 测什么

两套 Client agent WebUI（默认 `:8977` + `:8978`）各自：

1. 从 catalog 安装代表厂商模板（openai / youdao 签名 / deepl / google）
2. 写入 vendor key → version 绑定
3. `quick-test` 指向 mock 真实路径与鉴权形状
4. enable → `plain_text` 组件槽绑定 → list → `worker/run-once`
5. 轻量 UI smoke（首页可开）

两口测的是**同一套** local-first API 契约；差异在进程/端口，不在 catalog 协议。

## 入口（唯一推荐）

```bash
bash tests/modules/wpmmcc-ats/e2e/run-dual-webui-mock-providers.sh
```

可选环境变量：

| 变量 | 默认 | 含义 |
|------|------|------|
| `WEBUI_A_BASE` | `http://127.0.0.1:8977` | 插件客户端 WebUI agent |
| `WEBUI_B_BASE` | `http://127.0.0.1:8978` | 第二 WebUI agent（Desktop/会话口） |
| `MOCK_API_BASE` | `http://127.0.0.1:9090` | mock-api |
| `SKIP_ENSURE` | `0` | `1` 时不自动起 mock/双 agent |

## 产物

- `tests/reports/e2e/wpmmcc-ats/dual-webui-mock/summary-*.json` — 总汇总
- `…/prove-a/`、`…/prove-b/` — 各口 API 举证
- `…/playwright-*.json` — Playwright 报告

## 与其它 e2e 的关系

| 项 | 关系 |
|----|------|
| `scripts/prove-provider-mock-config.sh` | 本 lane **复用**为单口举证内核 |
| `run-playwright-provider-live-mock.sh` | 单口 live-mock；本项是**双口独立项** |
| E2E-A / E2E-C Lab | **无关**（不启 WP、不跑 claim/callback） |
| `confirm-catalog-112-mock.py` | 只测 mock；本项测 **Client↔mock** |

总索引：[`tests/README.md` §4](../../../../README.md)（根级 `tests/cross/playwright` 已归档 2026-09-22）。
