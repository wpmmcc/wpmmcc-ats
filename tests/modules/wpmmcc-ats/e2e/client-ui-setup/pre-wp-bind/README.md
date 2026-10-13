# pre-wp-bind — 对接 WP 插件前（纯界面）

在 Client WebUI 上按真实操作完成：

1. **Sites**：从 fixture 填 URL / token / route_secret → 保存 → 测试连通  
2. **API Keys**：跑一个 mock 厂商向导（可 `SKIP_PROVIDER=1`）  
3. **Components → 规则绑定**：保存 `global` + `plain_text` → 指定组件  
4. **Overview**：点击 **Run Once**，确认界面反馈（toast / 无致命红错）

## 入口

```bash
bash tests/modules/wpmmcc-ats/e2e/client-ui-setup/pre-wp-bind/run-gate.sh
```

| 变量 | 默认 | 含义 |
|------|------|------|
| `CLIENT_BASE` / `WEBUI_A_BASE` | `http://127.0.0.1:8977` | Client WebUI |
| `WP_URL` / `LAB_WP_BASE` | `http://127.0.0.1:9083` | Lab WP |
| `MOCK_API_BASE` | `http://127.0.0.1:9090` | mock |
| `PRE_WP_SITE_FIXTURE` | `fixtures/site.local.json` | UI 填表用凭据 |
| `SKIP_PROVIDER` | `0` | `1` 跳过厂商向导（已有组件时） |
| `HEADED` | `0` | `1` 有头浏览器 |
| `PRE_WP_COMPONENT_ID` | （自动/fixture） | 规则绑定目标组件 |

## Fixture

```bash
bash tests/modules/wpmmcc-ats/e2e/client-ui-setup/pre-wp-bind/export-site-fixture.sh
```

写出 `fixtures/site.local.json`（**含密钥，勿提交**）。示例结构见 `site.example.json`。

> ⚠️ 每次导出都会**轮换 WP 侧设备 token**（旧 token 立即失效）。已绑定在运行中 Client Sites 里的旧 token 会变成陈旧凭据，后续 approve/callback 将得到 **401 Unauthorized**。单独跑过 export 后，必须重跑 pre-wp-bind gate（`run-gate.sh`），由 Sites journey 把新 token upsert 进 Client。gate 内部的 fixture 新鲜度守卫会自动处理"过期→重导→重灌"闭环。

## Spec

`playwright/client-ui-setup/pre-wp-bind.journey.spec.ts`
