# providers-from-mock（登记，代码不搬家）

厂商 / 组件 mock UI 已落在既有路径，本目录只做索引，避免重复实现：

| 用途 | 路径 |
|------|------|
| 门禁 | `tests/modules/wpmmcc-ats/e2e/run-lab-provider-ui-gate.sh` |
| 同步 case | `scripts/sync-lab-provider-ui-cases.py` |
| Case JSON | `tests/modules/wpmmcc-ats/e2e/lab-provider-cases/` |
| Playwright | `playwright/ui-provider-mock/provider-wizard-from-lab-cases.spec.ts` |
| Desktop Tauri | `tests/modules/client-desktop/tests/e2e/tauri-provider-wizard-from-lab-cases.sh` |

对接 WP 前完整链路请用上级 `pre-wp-bind/`。
