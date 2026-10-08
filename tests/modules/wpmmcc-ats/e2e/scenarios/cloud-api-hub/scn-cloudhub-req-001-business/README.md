# SCN-CLOUDHUB-REQ-001-BUSINESS

- requirement_id: `REQ-CLOUDHUB-001`
- owner: `qa`
- layer: `business`
- product: `cloud-api-hub`

用途：

- 作为 Cloud API Hub 核心业务场景入口，验证 entitlement/provider 模板可用性路径。

执行：

```bash
bash tests/modules/wpmmcc-ats/e2e/scenarios/cloud-api-hub/scn-cloudhub-req-001-business/run.sh
```

快速验证：

```bash
bash tests/modules/wpmmcc-ats/e2e/scenarios/cloud-api-hub/scn-cloudhub-req-001-business/run.sh --dry-run
```
