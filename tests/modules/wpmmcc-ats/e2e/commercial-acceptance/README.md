# Commercial Acceptance Gate

SoT definition: [`tasks/client2/21-COMMERCIAL-ACCEPTANCE-MATRIX-DEFINITION.md`](../../../../../tasks/client2/21-COMMERCIAL-ACCEPTANCE-MATRIX-DEFINITION.md)

## Run

```bash
# Static checks only (U1–U7 action coverage + UI-only lint + plan)
CA_MODE=dry-run bash tests/modules/wpmmcc-ats/e2e/commercial-acceptance/run-commercial-acceptance.sh

# Daily: G0–G5 (G5 = identity-chain Lab; G4_TAURI skips if no Desktop binary)
CA_MODE=daily bash tests/modules/wpmmcc-ats/e2e/commercial-acceptance/run-commercial-acceptance.sh

# RC: 8 projects + COMMERCIAL_UI_ONLY
CA_MODE=rc bash tests/modules/wpmmcc-ats/e2e/commercial-acceptance/run-commercial-acceptance.sh

# Release: 20 projects + menu/SEO
CA_MODE=release bash tests/modules/wpmmcc-ats/e2e/commercial-acceptance/run-commercial-acceptance.sh
```

Reports: `tests/reports/e2e/commercial-acceptance/run-*/summary.json`  
Evidence fields: `ui_forms`, `evidence.*`, G2 marked `non_ui_api_roundtrip`.

## Helpers

| Script | Role |
|---|---|
| `lib/check-ui-form-coverage.py` | U1–U7 frontend + real Playwright action + SIM-10 |
| `lib/check-ui-only-journey.py` | `--strict` bans `bindSite(request)`; soft mode requires DOM |
| `run-multi-site-client-deep.sh` | **G5_UI**：≥3 真站 Sites UI 绑定 + ATS 管道（可含 slot-a 出 discovery/jobs） |
| `write-coverage-rollup.sh` | 汇总最新多站 + full-chain + per-project UI 触点数字 |
| `../playwright/client-ui-setup/per-project-ui-touch.journey.spec.ts` | 每内容插件 Client UI：Discovery + Run Once |
| `../playwright/simulation/sim-10-commercial-forms-matrix.spec.ts` | U1–U7 full DOM matrix |
| `../run-identity-chain-gate.sh` | G5 real multi-site topology |
| `../../client-desktop/tests/e2e/tauri-smoke.sh` | G4_TAURI Desktop WebView |
| `../content-plugin-full-chain/run-gate.sh` | G3; `FULL_CHAIN_COMMERCIAL=1` forces p3 |

## Skip knobs

`CA_SKIP_G0`…`CA_SKIP_G5`, `CA_SKIP_G5_UI`, `CA_SKIP_G4_TAURI`, `CA_KEEP_GOING=1`, `CA_G5_TIMEOUT_SEC=720`
