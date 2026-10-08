# SCN-WPTSALL-REQ-001-BUSINESS

- requirement_id: `REQ-WPTSALL-001`
- owner: `qa`
- layer: `business`
- product: `wptsall`

用途：

- 作为 WPTSALL 核心业务链路的独立场景入口，统一映射到 `run-product-smoke` 业务 lane。

执行：

```bash
bash tests/modules/wpmmcc-ats/e2e/scenarios/wptsall/scn-wptsall-req-001-business/run.sh
```

快速验证：

```bash
bash tests/modules/wpmmcc-ats/e2e/scenarios/wptsall/scn-wptsall-req-001-business/run.sh --dry-run
```
